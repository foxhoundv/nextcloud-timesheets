<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Timesheets\Db\Job;
use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUserManager;

class StorageService {
    public function __construct(
        private IConfig $config,
        private IRootFolder $rootFolder,
        private IUserManager $users,
        private ShiftMapper $shifts,
        private JobMapper $jobs,
        private ShiftClockService $clock,
        private WorkbookWriter $workbooks,
    ) {
    }

    public function settings(): array {
        return [
            'owner' => $this->config->getAppValue('timesheets', 'storage_owner', ''),
            'payrollRoot' => $this->config->getAppValue('timesheets', 'payroll_storage_root', ''),
            'salesOrderRoot' => $this->config->getAppValue('timesheets', 'sales_order_storage_root', ''),
        ];
    }

    public function saveRoots(string $ownerId, string $payrollRoot, string $salesOrderRoot): void {
        $owner = $this->users->get($ownerId);
        if ($owner === null || !$owner->isEnabled()) {
            throw new \DomainException('The account used for the shared storage roots must exist and be enabled.', 400);
        }
        $payrollRoot = $this->validateRoot($ownerId, $payrollRoot, 'Payroll');
        $salesOrderRoot = $this->validateRoot($ownerId, $salesOrderRoot, 'Sales Order');

        $this->config->setAppValue('timesheets', 'storage_owner', $ownerId);
        $this->config->setAppValue('timesheets', 'payroll_storage_root', $payrollRoot);
        $this->config->setAppValue('timesheets', 'sales_order_storage_root', $salesOrderRoot);
    }

    public function generatePayroll(string $period, string $date): array {
        [$start, $end] = $this->period($period, $date);
        $root = $this->configuredRoot('payroll_storage_root', 'Payroll');
        $byUser = [];
        foreach ($this->shifts->findCompletedBetween($start, $end) as $shift) {
            $byUser[$shift->getUserId()][] = $shift;
        }

        $files = [];
        foreach ($byUser as $userId => $shifts) {
            $user = $this->users->get($userId);
            $displayName = $user?->getDisplayName() ?? $userId;
            $actualTotal = 0;
            $reportedTotal = 0;
            $rows = [
                ['Employee timesheet', $displayName],
                ['Account', $userId],
                ['Period', $period],
                ['From', $start],
                ['Through', $end],
                [],
                ['Shift date', 'Clock in', 'Clock out', 'Actual duration', 'Reported duration', 'Rounding interval (minutes)'],
            ];
            foreach ($shifts as $shift) {
                $actual = $this->shiftDuration($shift);
                $reported = $shift->getRoundedSeconds() ?? $actual;
                $actualTotal += $actual;
                $reportedTotal += $reported;
                $zone = $this->clock->userTimeZone($userId);
                $rows[] = [
                    $shift->getShiftDate(),
                    $this->clock->parseTimestamp($shift->getClockIn())->setTimezone($zone)->format('Y-m-d H:i:s T'),
                    $this->clock->parseTimestamp($shift->getClockOut())->setTimezone($zone)->format('Y-m-d H:i:s T'),
                    $this->duration($actual),
                    $this->duration($reported),
                    $shift->getRoundingInterval() ?? 'Not captured',
                ];
            }
            $rows[] = [];
            $rows[] = ['Total', '', '', $this->duration($actualTotal), $this->duration($reportedTotal), ''];
            $userFolder = $root->getOrCreateFolder(rawurlencode($userId));
            $fileName = 'timesheet-' . $period . '-' . $start . '-' . $end . '.xlsx';
            $this->writeFile($userFolder, $fileName, $this->workbooks->write($rows));
            $files[] = $userFolder->getName() . '/' . $fileName;
        }
        return ['start' => $start, 'end' => $end, 'count' => count($files), 'files' => $files];
    }

    public function generateSalesOrder(string $reference): array {
        $reference = trim($reference);
        if ($reference === '' || !mb_check_encoding($reference, 'UTF-8')
            || mb_strlen($reference) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $reference)) {
            throw new \DomainException('Enter a valid Sales Order reference.', 400);
        }
        $root = $this->configuredRoot('sales_order_storage_root', 'Sales Order');
        $matches = [];
        $prefix = mb_strtolower($reference, 'UTF-8');
        foreach ($root->getDirectoryListing() as $node) {
            if ($node instanceof Folder
                && str_starts_with(mb_strtolower($node->getName(), 'UTF-8'), $prefix)) {
                $matches[] = $node;
            }
        }
        if ($matches === []) {
            throw new \DomainException('No Sales Order folder starts with that reference.', 404);
        }
        if (count($matches) !== 1) {
            throw new \DomainException('More than one Sales Order folder starts with that reference. Enter a more specific reference.', 409);
        }

        $salesOrderFolder = $matches[0];
        $logFolder = $salesOrderFolder->nodeExists('Time logs')
            ? $salesOrderFolder->get('Time logs')
            : $salesOrderFolder->newFolder('Time logs');
        if (!$logFolder instanceof Folder) {
            throw new \DomainException('The Sales Order Time logs path exists but is not a folder.', 400);
        }

        $jobs = $this->jobs->findCompletedByReference($reference);
        $actualTotal = 0;
        $reportedTotal = 0;
        $rows = [
            ['Sales Order time log', $reference],
            ['Folder', $salesOrderFolder->getName()],
            [],
            ['Employee', 'Account', 'Job reference', 'Job date', 'Clock in', 'Clock out', 'Actual duration', 'Reported duration', 'Rounding interval (minutes)'],
        ];
        foreach ($jobs as $job) {
            $actual = $job->getDurationSeconds();
            if ($actual === null) {
                $actual = $this->clock->parseTimestamp($job->getClockOut())->getTimestamp()
                    - $this->clock->parseTimestamp($job->getClockIn())->getTimestamp();
            }
            if ($actual < 0) {
                throw new \RuntimeException('A saved job has a negative duration and cannot be exported.');
            }
            $reported = $job->getRoundedSeconds() ?? $actual;
            $actualTotal += $actual;
            $reportedTotal += $reported;
            $user = $this->users->get($job->getUserId());
            $zone = $this->clock->userTimeZone($job->getUserId());
            $rows[] = [
                $user?->getDisplayName() ?? $job->getUserId(),
                $job->getUserId(),
                $job->getJobReference(),
                $job->getJobDate(),
                $this->clock->parseTimestamp($job->getClockIn())->setTimezone($zone)->format('Y-m-d H:i:s T'),
                $this->clock->parseTimestamp($job->getClockOut())->setTimezone($zone)->format('Y-m-d H:i:s T'),
                $this->duration($actual),
                $this->duration($reported),
                $job->getRoundingInterval() ?? 'Not captured',
            ];
        }
        $rows[] = [];
        $rows[] = ['Total', '', '', '', '', '', $this->duration($actualTotal), $this->duration($reportedTotal), ''];
        $fileName = 'Time logs.xlsx';
        $this->writeFile($logFolder, $fileName, $this->workbooks->write($rows));
        return [
            'folder' => $salesOrderFolder->getName() . '/Time logs/' . $fileName,
            'count' => count($jobs),
            'reference' => $reference,
        ];
    }

    private function validateRoot(string $ownerId, string $path, string $label): string {
        $path = trim($path);
        if ($path === '' || strlen($path) > 1024 || str_starts_with($path, '/') || str_ends_with($path, '/')
            || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new \DomainException($label . ' storage root must be a non-empty path relative to the configured account\'s files.', 400);
        }
        $parts = explode('/', $path);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new \DomainException($label . ' storage root contains an invalid path component.', 400);
        }
        try {
            $folder = $this->rootFolder->getUserFolder($ownerId)->get($path);
        } catch (NotFoundException $error) {
            throw new \DomainException($label . ' storage root folder was not found in the configured account\'s files.', 400, $error);
        }
        if (!$folder instanceof Folder || !$folder->isUpdateable()) {
            throw new \DomainException($label . ' storage root must be an existing writable folder.', 400);
        }
        return $path;
    }

    private function configuredRoot(string $key, string $label): Folder {
        $settings = $this->settings();
        if ($settings['owner'] === '' || $settings[$key === 'payroll_storage_root' ? 'payrollRoot' : 'salesOrderRoot'] === '') {
            throw new \DomainException('Configure the ' . $label . ' storage root before generating workbooks.', 400);
        }
        $owner = $this->users->get($settings['owner']);
        if ($owner === null || !$owner->isEnabled()) {
            throw new \DomainException('The configured storage account is unavailable. Save the storage roots again.', 400);
        }
        $path = $settings[$key === 'payroll_storage_root' ? 'payrollRoot' : 'salesOrderRoot'];
        try {
            $folder = $this->rootFolder->getUserFolder($settings['owner'])->get($path);
        } catch (NotFoundException $error) {
            throw new \DomainException('The configured ' . $label . ' storage root no longer exists.', 400, $error);
        }
        if (!$folder instanceof Folder || !$folder->isUpdateable()) {
            throw new \DomainException('The configured ' . $label . ' storage root is not a writable folder.', 400);
        }
        return $folder;
    }

    private function period(string $period, string $date): array {
        $selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($selectedDate === false || $selectedDate->format('Y-m-d') !== $date) {
            throw new \DomainException('Choose a valid date for the report period.', 400);
        }
        if ($period === 'weekly') {
            $daysFromMonday = (int) $selectedDate->format('N') - 1;
            $start = $selectedDate->modify('-' . $daysFromMonday . ' days');
            return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
        }
        if ($period === 'monthly') {
            return [$selectedDate->format('Y-m-01'), $selectedDate->format('Y-m-t')];
        }
        throw new \DomainException('Choose a supported report period. Bi-weekly reports are not configured yet.', 400);
    }

    private function shiftDuration(Shift $shift): int {
        $seconds = $shift->getDurationSeconds() ?? (int) ($shift->getDurationMinutes() ?? 0) * 60;
        if ($seconds < 0) {
            throw new \RuntimeException('A saved shift has a negative duration and cannot be exported.');
        }
        return $seconds;
    }

    private function duration(int $seconds): string {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function writeFile(Folder $folder, string $fileName, string $content): void {
        if (!$folder->nodeExists($fileName)) {
            $folder->newFile($fileName, $content);
            return;
        }
        $file = $folder->get($fileName);
        if (!$file instanceof File) {
            throw new \DomainException('The workbook target exists but is not a file.', 400);
        }
        $file->putContent($content);
    }
}
