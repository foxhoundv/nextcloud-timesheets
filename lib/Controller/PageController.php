<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\ShiftService;
use OCA\Timesheets\Service\ShiftClockService;
use OCA\Timesheets\Service\AccessService;
use OCA\Timesheets\Service\JobClockService;
use OCA\Timesheets\Service\ShopTimeService;
use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\Job;
use OCA\Timesheets\Db\Shift;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IURLGenerator;

class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ShiftService $shiftService,
        private IUserSession $userSession,
        private ShiftClockService $clock,
        private IURLGenerator $urlGenerator,
        private AccessService $access,
        private JobClockService $jobClock,
        private JobMapper $jobs,
        private ShopTimeService $shopTime,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : '';

        $shifts = $this->shiftService->listForUser($userId);
        $jobs = $this->jobs->findByUser($userId);
        $permissions = $this->access->permissions($userId);
        if (!$permissions['access'] && $shifts === [] && $jobs === []) {
            $response = new TemplateResponse('timesheets', 'denied');
            $response->setStatus(403);
            return $response;
        }
        $breakdown = $this->shopTime->calculate($shifts, $jobs);
        $stats = $this->buildStats($shifts, $jobs, $breakdown);

        $entries = $this->buildEntries($shifts, $userId, $breakdown['shifts']);

        return new TemplateResponse('timesheets', 'main', [
            'stats' => $stats,
            'entries' => $entries,
            'breakdown' => $breakdown,
            'trackingEnabled' => $this->clock->isEnabled(),
            'canStartShift' => $permissions['shift'],
            'activeShift' => $this->clock->current($userId),
            'clockInUrl' => $this->urlGenerator->linkToRoute('timesheets.shift.clockIn'),
            'clockOutUrl' => $this->urlGenerator->linkToRoute('timesheets.shift.clockOut'),
            'jobTrackingEnabled' => $this->jobClock->isEnabled(),
            'canStartJob' => $permissions['job'],
            'jobRequiresShift' => $this->jobClock->requiresShift($userId),
            'activeJob' => $this->jobClock->current($userId),
            'jobEntries' => $this->jobEntries($jobs, $userId),
            'jobClockInUrl' => $this->urlGenerator->linkToRoute('timesheets.job.clockIn'),
            'jobClockOutUrl' => $this->urlGenerator->linkToRoute('timesheets.job.clockOut'),
            'canManageSettings' => $user !== null && $this->access->isTimesheetsAdmin($userId),
            'adminSettingsUrl' => $this->urlGenerator->linkToRoute('timesheets.adminpage.index'),
        ]);
    }

    private function jobEntries(array $jobs, string $userId): array {
        $entries = [];
        foreach ($jobs as $job) {
            $open = $job->getClockOut() === null;
            $zone = $open ? new \DateTimeZone($job->getTimeZone()) : $this->clock->userTimeZone($userId);
            $entries[] = [
                'reference' => $job->getJobReference(),
                'date' => $job->getJobDate(),
                'clockIn' => $this->clock->parseTimestamp($job->getClockIn())->setTimezone($zone)->format('Y-m-d H:i:s T'),
                'clockOut' => $open ? null : $this->clock->parseTimestamp($job->getClockOut())->setTimezone($zone)->format('Y-m-d H:i:s T'),
                'total' => $open ? null : $this->formatMinutes(intdiv($job->getDurationSeconds(), 60)),
                'reported' => $open ? null : $this->formatMinutes(intdiv($this->reportedSeconds($job, $job->getDurationSeconds()), 60)),
                'roundingInterval' => $open ? null : $job->getRoundingInterval(),
            ];
        }
        return $entries;
    }

    private function buildStats(array $shifts, array $jobs, array $breakdown): array {
        $recordedSeconds = 0;
        $reportedShiftSeconds = 0;
        $recordedJobSeconds = 0;
        $reportedJobSeconds = 0;
        $completedShiftCount = 0;
        $openShiftCount = 0;

        foreach ($shifts as $shift) {
            $duration = $breakdown['shifts'][$shift->getId()]['totalSeconds']
                ?? $shift->getDurationSeconds() ?? (int) ($shift->getDurationMinutes() ?? 0) * 60;
            $status = (string) ($shift->getStatus() ?? 'closed');

            if ($status === 'open') {
                $openShiftCount++;
            } else {
                $completedShiftCount++;
                $recordedSeconds += $duration;
                $reportedShiftSeconds += $this->reportedSeconds($shift, $duration);
            }
        }
        foreach ($jobs as $job) {
            if ($job->getClockOut() !== null) {
                $recordedJobSeconds += $job->getDurationSeconds();
                $reportedJobSeconds += $this->reportedSeconds($job, $job->getDurationSeconds());
            }
        }

        return [
            ['label' => 'Recorded time', 'value' => $this->formatMinutes(intdiv($recordedSeconds, 60)), 'description' => 'Total saved duration of completed shifts, across all dates.'],
            ['label' => 'Completed shifts', 'value' => (string) $completedShiftCount, 'description' => 'Shifts that have been closed.'],
            ['label' => 'Open shifts', 'value' => (string) $openShiftCount, 'description' => 'Shifts that have not been closed.'],
            ['label' => 'Job time in shifts', 'value' => $this->formatMinutes(intdiv($breakdown['jobSeconds'], 60)), 'description' => 'Job time overlapping completed shifts with recorded start and end times, across all dates.'],
            ['label' => 'Shop time', 'value' => $this->formatMinutes(intdiv($breakdown['shopSeconds'], 60)), 'description' => 'Time in completed shifts with recorded start and end times that was not spent on jobs, across all dates.'],
            ['label' => 'Reported shift time', 'value' => $this->formatMinutes(intdiv($reportedShiftSeconds, 60)), 'description' => 'Completed shifts using each saved rounding rule; actual time where rounding was off or no rule was captured.'],
            ['label' => 'Recorded job time', 'value' => $this->formatMinutes(intdiv($recordedJobSeconds, 60)), 'description' => 'Actual duration of completed jobs, including time outside shifts. Do not add to shift time.'],
            ['label' => 'Reported job time', 'value' => $this->formatMinutes(intdiv($reportedJobSeconds, 60)), 'description' => 'Completed jobs using each saved rounding rule, including time outside shifts; actual time where no rounding applies.'],
        ];
    }

    private function buildEntries(array $shifts, string $userId, array $breakdowns): array {
        $entries = [];
        $userZone = $this->clock->userTimeZone($userId);

        foreach ($shifts as $shift) {
            $status = (string) ($shift->getStatus() ?? 'closed');
            $durationMinutes = (int) ($shift->getDurationMinutes() ?? 0);
            $breakdown = $breakdowns[$shift->getId()] ?? null;
            if ($breakdown !== null) {
                $durationMinutes = intdiv($breakdown['totalSeconds'], 60);
            }
            $zone = $status === 'open' ? new \DateTimeZone($shift->getTimeZone() ?? 'UTC') : $userZone;
            $clockIn = $shift->getClockIn() === null ? null : $this->clock->parseTimestamp($shift->getClockIn())->setTimezone($zone);
            $clockOut = $shift->getClockOut() === null ? null : $this->clock->parseTimestamp($shift->getClockOut())->setTimezone($zone);

            $entries[] = [
                'date' => (string) ($shift->getShiftDate() ?? '—'),
                'total' => $status === 'open' ? null : $this->formatMinutes($durationMinutes),
                'reported' => $status === 'open' ? null : $this->formatMinutes(intdiv($this->reportedSeconds($shift,
                    $breakdown['totalSeconds'] ?? $shift->getDurationSeconds() ?? $durationMinutes * 60), 60)),
                'roundingInterval' => $status === 'open' ? null : $shift->getRoundingInterval(),
                'status' => $status === 'open' ? 'In progress' : 'Closed',
                'isOpen' => $status === 'open',
                'jobTime' => $breakdown === null ? null : $this->formatMinutes(intdiv($breakdown['jobSeconds'], 60)),
                'shopTime' => $breakdown === null ? null : $this->formatMinutes(intdiv($breakdown['shopSeconds'], 60)),
                'clockIn' => $clockIn?->format('Y-m-d H:i:s T'),
                'clockOut' => $clockOut?->format('Y-m-d H:i:s T'),
            ];
        }

        return $entries;
    }

    private function reportedSeconds(Shift|Job $interval, int $actual): int {
        $rule = $interval->getRoundingInterval();
        $seconds = $interval->getRoundedSeconds();
        if ($rule === null && $seconds === null) {
            return $actual;
        }
        if (!in_array($rule, [0, 5, 10, 15, 30], true) || $seconds === null || $seconds < 0) {
            throw new \RuntimeException('An interval has invalid saved rounding data. Ask an administrator to review it.');
        }
        return $seconds;
    }

    private function formatMinutes(int $minutes): string {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%dh %02dm', $hours, $mins);
    }
}