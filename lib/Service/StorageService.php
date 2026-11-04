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
use Psr\Log\LoggerInterface;

class StorageService {
    public function __construct(
        private IConfig $config,
        private IRootFolder $rootFolder,
        private IUserManager $users,
        private ShiftMapper $shifts,
        private JobMapper $jobs,
        private ShiftClockService $clock,
        private WorkbookWriter $workbooks,
        private LoggerInterface $logger,
    ) {
    }

    public function settings(): array {
        return [
            'owner' => $this->config->getAppValue('timesheets', 'storage_owner', ''),
            'payrollRoot' => $this->config->getAppValue('timesheets', 'payroll_storage_root', ''),
            'jobRoot' => $this->config->getAppValue('timesheets', 'sales_order_storage_root', ''),
            'jobReferenceFormat' => $this->config->getAppValue('timesheets', 'job_reference_format', 'SO{year:2}{sequence:4}'),
            'jobYearBase' => $this->config->getAppValue('timesheets', 'job_year_base', '2000'),
            'jobFolderTemplate' => $this->config->getAppValue('timesheets', 'job_folder_template', '{year}/{group}/{jobId}'),
            'jobGroupFirstEnd' => $this->config->getAppValue('timesheets', 'job_group_first_end', '99'),
            'jobGroupSize' => $this->config->getAppValue('timesheets', 'job_group_size', '100'),
            'jobGroupNameFormat' => $this->config->getAppValue('timesheets', 'job_group_name_format', '{start}-{end}'),
            'payrollWeekStart' => $this->config->getAppValue('timesheets', 'payroll_week_start', '1'),
        ];
    }

    public function saveRoots(
        string $ownerId,
        string $payrollRoot,
        string $jobRoot,
        string $jobReferenceFormat = 'SO{year:2}{sequence:4}',
        string $jobYearBase = '2000',
        string $jobFolderTemplate = '{year}/{group}/{jobId}',
        string $jobGroupFirstEnd = '99',
        string $jobGroupSize = '100',
        string $jobGroupNameFormat = '{start}-{end}',
        ?string $payrollWeekStart = null,
    ): void {
        $owner = $this->users->get($ownerId);
        if ($owner === null || !$owner->isEnabled()) {
            throw new \DomainException('The account used for the shared storage roots must exist and be enabled.', 400);
        }
        $payrollRoot = $this->validateRoot($ownerId, $payrollRoot, 'Payroll');
        $jobRoot = $this->validateRoot($ownerId, $jobRoot, 'Job');
        $jobConfiguration = $this->validateJobConfiguration(
            $jobReferenceFormat,
            $jobYearBase,
            $jobFolderTemplate,
            $jobGroupFirstEnd,
            $jobGroupSize,
            $jobGroupNameFormat,
        );
        $payrollWeekStart ??= $this->settings()['payrollWeekStart'];
        if (!in_array($payrollWeekStart, ['1', '2', '3', '4', '5', '6', '7'], true)) {
            throw new \DomainException('Choose a valid first day of the payroll workweek.', 400);
        }

        $this->config->setAppValue('timesheets', 'storage_owner', $ownerId);
        $this->config->setAppValue('timesheets', 'payroll_storage_root', $payrollRoot);
        $this->config->setAppValue('timesheets', 'sales_order_storage_root', $jobRoot);
        $this->config->setAppValue('timesheets', 'payroll_week_start', $payrollWeekStart);
        foreach ($jobConfiguration as $key => $value) {
            $this->config->setAppValue('timesheets', $key, $value);
        }
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

    public function synchronizeJob(string $reference): array {
        try {
            return $this->writeJobWorkbook($reference);
        } catch (\OCP\Files\GenericFileException | \OCP\Files\NotPermittedException
            | \OCP\Files\NotFoundException | \OCP\Files\AlreadyExistsException
            | \OCP\Files\InvalidPathException | \OCP\Files\NotEnoughSpaceException
            | \OCP\Files\StorageNotAvailableException | \OCP\Files\StorageInvalidException
            | \OCP\Files\ConnectionLostException | \OCP\Files\ForbiddenException
            | \OCP\Files\InvalidContentException | \OCP\Files\EntityTooLargeException
            | \OCP\Files\LockNotAcquiredException | \OCP\Lock\LockedException $error) {
            $this->logger->error('Unable to synchronize the Timesheets Job workbook.', [
                'app' => 'timesheets', 'reference' => $reference, 'exception' => $error,
            ]);
            throw new \DomainException('The Job workbook could not be updated. Contact your administrator before starting this Job.', 409, $error);
        }
    }

    private function writeJobWorkbook(string $reference): array {
        $reference = trim($reference);
        if ($reference === '' || !mb_check_encoding($reference, 'UTF-8')
            || mb_strlen($reference) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $reference)) {
            throw new \DomainException('Enter a valid Job ID.', 400);
        }
        $settings = $this->settings();
        $root = $this->configuredRoot('sales_order_storage_root', 'Job');
        $parsed = $this->parseJobReference($reference, $settings);
        $relativeFolder = $this->jobFolderPath($parsed, $reference, $settings);
        $parts = explode('/', $relativeFolder);
        $folderPrefix = array_pop($parts);
        $parentPath = implode('/', $parts);
        try {
            $parent = $parentPath === '' ? $root : $root->get($parentPath);
        } catch (NotFoundException $error) {
            throw new \DomainException('The Job folder does not exist. Contact your administrator before starting this Job.', 404, $error);
        }
        if (!$parent instanceof Folder) {
            throw new \DomainException('The configured Job folder path is not a folder.', 400);
        }
        $matches = [];
        $prefix = mb_strtolower($folderPrefix, 'UTF-8');
        foreach ($parent->getDirectoryListing() as $node) {
            if ($node instanceof Folder
                && str_starts_with(mb_strtolower($node->getName(), 'UTF-8'), $prefix)) {
                $matches[] = $node;
            }
        }
        if ($matches === []) {
            throw new \DomainException('The Job folder does not exist. Contact your administrator before starting this Job.', 404);
        }
        if (count($matches) !== 1) {
            throw new \DomainException('More than one Job folder starts with that ID. Contact your administrator to resolve the folder structure.', 409);
        }

        $jobFolder = $matches[0];
        $logFolder = $jobFolder->nodeExists('Time logs')
            ? $jobFolder->get('Time logs')
            : $jobFolder->newFolder('Time logs');
        if (!$logFolder instanceof Folder) {
            throw new \DomainException('The Job Time logs path exists but is not a folder.', 400);
        }

        $jobs = $this->jobs->findByReference($reference);
        $actualTotal = 0;
        $reportedTotal = 0;
        $rows = [
            ['Job time log', $reference],
            ['Folder', $jobFolder->getName()],
            [],
            ['Interval ID', 'Employee', 'Account', 'Job ID', 'Job date', 'Clock in', 'Clock out', 'Actual duration', 'Reported duration', 'Rounding interval (minutes)', 'Status'],
        ];
        foreach ($jobs as $job) {
            $actual = $job->getDurationSeconds();
            $closed = $job->getClockOut() !== null;
            if ($closed && $actual === null) {
                $actual = $this->clock->parseTimestamp($job->getClockOut())->getTimestamp()
                    - $this->clock->parseTimestamp($job->getClockIn())->getTimestamp();
            }
            if ($actual !== null && $actual < 0) {
                throw new \RuntimeException('A saved job has a negative duration and cannot be exported.');
            }
            $reported = $closed ? ($job->getRoundedSeconds() ?? $actual) : null;
            $actualTotal += $closed ? $actual : 0;
            $reportedTotal += $reported ?? 0;
            $user = $this->users->get($job->getUserId());
            $zone = $this->clock->userTimeZone($job->getUserId());
            $rows[] = [
                $job->getId(),
                $user?->getDisplayName() ?? $job->getUserId(),
                $job->getUserId(),
                $job->getJobReference(),
                $job->getJobDate(),
                $this->clock->parseTimestamp($job->getClockIn())->setTimezone($zone)->format('Y-m-d H:i:s T'),
                $closed ? $this->clock->parseTimestamp($job->getClockOut())->setTimezone($zone)->format('Y-m-d H:i:s T') : '',
                $closed ? $this->duration($actual) : '',
                $closed ? $this->duration($reported) : '',
                $closed ? ($job->getRoundingInterval() ?? 'Not captured') : '',
                $closed ? 'Closed' : 'Active',
            ];
        }
        $rows[] = [];
        $rows[] = ['Total (completed intervals)', '', '', '', '', '', '', $this->duration($actualTotal), $this->duration($reportedTotal), '', ''];
        $fileName = 'Time logs.xlsx';
        $this->writeFile($logFolder, $fileName, $this->workbooks->write($rows));
        $actualFolderPath = $parentPath === ''
            ? $jobFolder->getName()
            : $parentPath . '/' . $jobFolder->getName();
        return [
            'folder' => $actualFolderPath . '/Time logs/' . $fileName,
            'count' => count($jobs),
            'reference' => $reference,
        ];
    }

    private function validateJobConfiguration(
        string $referenceFormat,
        string $yearBase,
        string $folderTemplate,
        string $groupFirstEnd,
        string $groupSize,
        string $groupNameFormat,
    ): array {
        $referenceFormat = trim($referenceFormat);
        $folderTemplate = trim($folderTemplate);
        $groupNameFormat = trim($groupNameFormat);
        $yearBaseValue = filter_var($yearBase, FILTER_VALIDATE_INT);
        $firstEndValue = filter_var($groupFirstEnd, FILTER_VALIDATE_INT);
        $groupSizeValue = filter_var($groupSize, FILTER_VALIDATE_INT);
        if ($referenceFormat === '' || strlen($referenceFormat) > 255 || str_contains($referenceFormat, '/')
            || str_contains($referenceFormat, '\\') || preg_match('/[\x00-\x1f\x7f]/', $referenceFormat)) {
            throw new \DomainException('Enter a valid Job ID format.', 400);
        }
        if ($yearBaseValue === false || $yearBaseValue < 1000 || $yearBaseValue > 9900 || $yearBaseValue % 100 !== 0) {
            throw new \DomainException('The year base must be a century such as 1900 or 2000.', 400);
        }
        if ($firstEndValue === false || $firstEndValue < 1 || $firstEndValue > 999999999
            || $groupSizeValue === false || $groupSizeValue < 1 || $groupSizeValue > 999999999) {
            throw new \DomainException('Enter positive integer values for the Job grouping ranges.', 400);
        }
        if (strlen($folderTemplate) > 1024 || $folderTemplate === ''
            || str_starts_with($folderTemplate, '/') || str_ends_with($folderTemplate, '/')
            || str_contains($folderTemplate, '\\') || preg_match('/[\x00-\x1f\x7f]/', $folderTemplate)) {
            throw new \DomainException('Enter a valid relative Job folder template.', 400);
        }
        $folderTokens = $this->templateTokens($folderTemplate, ['year', 'year2', 'sequence', 'group', 'jobId']);
        $folderParts = explode('/', $folderTemplate);
        if (end($folderParts) !== '{jobId}' || count(array_keys($folderTokens, 'jobId', true)) !== 1) {
            throw new \DomainException('The Job folder template must end with {jobId}.', 400);
        }
        foreach ($folderParts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \DomainException('The Job folder template contains an invalid path component.', 400);
            }
        }
        $groupTokens = $this->templateTokens($groupNameFormat, ['start', 'end']);
        if ($groupNameFormat === '' || strlen($groupNameFormat) > 100
            || str_contains($groupNameFormat, '/') || str_contains($groupNameFormat, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $groupNameFormat)
            || $groupTokens === [] && (trim($groupNameFormat) === '.' || trim($groupNameFormat) === '..')) {
            throw new \DomainException('Enter a valid one-folder-name grouping template.', 400);
        }
        $format = $this->compileReferenceFormat($referenceFormat);
        if (str_contains($folderTemplate, '{year}') && $format['yearLength'] === null) {
            throw new \DomainException('The {year} folder token requires a {year:2} or {year:4} in the Job ID format.', 400);
        }
        if (str_contains($folderTemplate, '{year2}') && $format['yearLength'] === null) {
            throw new \DomainException('The {year2} folder token requires a year in the Job ID format.', 400);
        }
        return [
            'job_reference_format' => $referenceFormat,
            'job_year_base' => (string) $yearBaseValue,
            'job_folder_template' => $folderTemplate,
            'job_group_first_end' => (string) $firstEndValue,
            'job_group_size' => (string) $groupSizeValue,
            'job_group_name_format' => $groupNameFormat,
        ];
    }

    private function templateTokens(string $template, array $allowed): array {
        preg_match_all('/\{([^{}]*)\}/', $template, $matches);
        foreach ($matches[1] as $token) {
            if (!in_array($token, $allowed, true)) {
                throw new \DomainException('The folder template contains an unsupported placeholder: {' . $token . '}.', 400);
            }
        }
        $withoutTokens = preg_replace('/\{[^{}]*\}/', '', $template);
        if (str_contains($withoutTokens, '{') || str_contains($withoutTokens, '}')) {
            throw new \DomainException('The folder template contains unmatched braces.', 400);
        }
        return $matches[1];
    }

    private function compileReferenceFormat(string $format): array {
        $parts = preg_split('/\{(year|sequence):(\d+)\}/', $format, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) < 4) {
            throw new \DomainException('The Job ID format must contain one {sequence:N} placeholder.', 400);
        }
        $pattern = '';
        $yearLength = null;
        $sequenceLength = null;
        for ($index = 0; $index < count($parts); $index += 3) {
            $literal = $parts[$index];
            if (str_contains($literal, '{') || str_contains($literal, '}')) {
                throw new \DomainException('The Job ID format contains an unsupported placeholder.', 400);
            }
            $pattern .= preg_quote($literal, '/');
            if (isset($parts[$index + 2])) {
                $kind = $parts[$index + 1];
                $length = (int) $parts[$index + 2];
                if ($length < 1 || $length > 10
                    || ($kind === 'year' && !in_array($length, [2, 4], true))
                    || ($kind === 'sequence' && $sequenceLength !== null)
                    || ($kind === 'year' && $yearLength !== null)) {
                    throw new \DomainException('The Job ID format has invalid or duplicate placeholders.', 400);
                }
                if ($kind === 'sequence') {
                    $sequenceLength = $length;
                    $pattern .= '(?<sequence>\d{' . $length . '})';
                } else {
                    $yearLength = $length;
                    $pattern .= '(?<year>\d{' . $length . '})';
                }
            }
        }
        if ($sequenceLength === null) {
            throw new \DomainException('The Job ID format must contain one {sequence:N} placeholder.', 400);
        }
        return [
            'pattern' => '/^' . $pattern . '$/D',
            'yearLength' => $yearLength,
        ];
    }

    private function parseJobReference(string $reference, array $settings): array {
        $format = $this->compileReferenceFormat($settings['jobReferenceFormat']);
        if (preg_match($format['pattern'], $reference, $matches) !== 1) {
            throw new \DomainException('The Job ID does not match the configured Job ID format.', 400);
        }
        $sequence = (int) $matches['sequence'];
        if ($sequence < 1) {
            throw new \DomainException('The Job sequence must be greater than zero.', 400);
        }
        $year = null;
        $year2 = null;
        if ($format['yearLength'] !== null) {
            $yearValue = $matches['year'];
            $year = $format['yearLength'] === 2
                ? (string) ((int) $settings['jobYearBase'] + (int) $yearValue)
                : $yearValue;
            $year2 = substr($year, -2);
        }
        return [
            'sequence' => $matches['sequence'],
            'sequenceNumber' => $sequence,
            'year' => $year,
            'year2' => $year2,
            'firstEnd' => (int) $settings['jobGroupFirstEnd'],
            'groupSize' => (int) $settings['jobGroupSize'],
            'groupNameFormat' => $settings['jobGroupNameFormat'],
            'folderTemplate' => $settings['jobFolderTemplate'],
        ];
    }

    private function jobFolderPath(array $parsed, string $reference, array $settings): string {
        $sequence = $parsed['sequenceNumber'];
        if ($sequence <= $parsed['firstEnd']) {
            $start = 1;
            $end = $parsed['firstEnd'];
        } else {
            $start = $parsed['firstEnd'] + 1
                + intdiv($sequence - $parsed['firstEnd'] - 1, $parsed['groupSize']) * $parsed['groupSize'];
            $end = $start + $parsed['groupSize'] - 1;
        }
        $group = strtr($parsed['groupNameFormat'], [
            '{start}' => (string) $start,
            '{end}' => (string) $end,
        ]);
        if ($group === '' || $group === '.' || $group === '..') {
            throw new \DomainException('The Job grouping rule generated an invalid folder name.', 400);
        }
        $path = strtr($parsed['folderTemplate'], [
            '{year}' => $parsed['year'] ?? '',
            '{year2}' => $parsed['year2'] ?? '',
            '{sequence}' => $parsed['sequence'],
            '{group}' => $group,
            '{jobId}' => $reference,
        ]);
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new \DomainException('The configured Job folder template generated an invalid path.', 400);
            }
        }
        return $path;
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
        $settingKey = $key === 'payroll_storage_root' ? 'payrollRoot' : 'jobRoot';
        if ($settings['owner'] === '' || $settings[$settingKey] === '') {
            throw new \DomainException('Configure the ' . $label . ' storage root before generating workbooks.', 400);
        }
        $owner = $this->users->get($settings['owner']);
        if ($owner === null || !$owner->isEnabled()) {
            throw new \DomainException('The configured storage account is unavailable. Save the storage roots again.', 400);
        }
        $path = $settings[$settingKey];
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
            $weekStart = $this->settings()['payrollWeekStart'];
            if (!in_array($weekStart, ['1', '2', '3', '4', '5', '6', '7'], true)) {
                throw new \DomainException('The payroll workweek setting is invalid. Contact your administrator.', 400);
            }
            $daysFromStart = ((int) $selectedDate->format('N') - (int) $weekStart + 7) % 7;
            $start = $selectedDate->modify('-' . $daysFromStart . ' days');
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
