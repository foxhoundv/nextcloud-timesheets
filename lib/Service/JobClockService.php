<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use DateTimeImmutable;
use OCA\Timesheets\Db\Job;
use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\ShiftMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class JobClockService {
    public function __construct(
        private JobMapper $jobs,
        private ShiftMapper $shifts,
        private ShiftClockService $shiftClock,
        private AccessService $access,
        private IConfig $config,
        private ITimeFactory $time,
        private ClockTransaction $transaction,
        private RoundingService $rounding,
        private StorageService $storage,
        private LoggerInterface $logger,
        private LocationService $locations,
    ) {
    }

    public function isEnabled(): bool {
        return $this->config->getAppValue('timesheets', 'job_tracking', '0') === '1';
    }

    public function requiresShift(string $userId): bool {
        $permissions = $this->access->permissions($userId);
        return $this->isEnabled() && $this->shiftClock->isEnabled()
            && $permissions['job'] && $permissions['shift']
            && $this->config->getAppValue('timesheets', 'job_require_shift', '0') === '1';
    }

    public function current(string $userId): ?array {
        $job = $this->jobs->findOpenForUser($userId);
        if ($job === null) {
            return null;
        }
        $start = $this->shiftClock->parseTimestamp($job->getClockIn());
        return [
            'id' => $job->getId(),
            'reference' => $job->getJobReference(),
            'startedAt' => $start->setTimezone(new \DateTimeZone($job->getTimeZone()))->format('Y-m-d H:i:s T'),
            'elapsedSeconds' => max(0, $this->time->getTime() - $start->getTimestamp()),
            'location' => LocationService::label($job->getWorkLocation()),
        ];
    }

    public function clockIn(string $userId, string $reference, ?string $country = null, ?string $region = null): Job {
        $reference = trim($reference);
        if ($reference === '' || !mb_check_encoding($reference, 'UTF-8')
            || mb_strlen($reference) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $reference)) {
            throw new \DomainException('Enter a job reference of 1 to 255 characters without control characters.', 400);
        }
        return $this->transaction->run($userId, function () use ($userId, $reference, $country, $region): Job {
            if (!$this->isEnabled() || !$this->access->permissions($userId)['job']) {
                throw new \DomainException('Job tracking is disabled or you do not have permission to start jobs.', 403);
            }
            if ($this->jobs->findOpenForUser($userId) !== null) {
                throw new \DomainException('You already have an active job. End it before starting another.', 409);
            }
            if ($this->requiresShift($userId) && $this->shifts->findOpenForUser($userId) === null) {
                throw new \DomainException('Clock into a shift before starting a job.', 409);
            }
            $zone = $this->shiftClock->userTimeZone($userId);
            $now = new DateTimeImmutable('@' . $this->time->getTime());
            $job = new Job();
            $job->setUserId($userId);
            $job->setOpenUserId($userId);
            $job->setJobReference($reference);
            $job->setTimeZone($zone->getName());
            $job->setJobDate($now->setTimezone($zone)->format('Y-m-d'));
            $job->setClockIn($now->format('Y-m-d H:i:s'));
            $location = $this->locations->capture('job', $country, $region, $this->shifts->findOpenForUser($userId)?->getWorkLocation());
            if ($location !== null) {
                foreach ($this->jobs->findByReference($reference) as $previous) {
                    if ($previous->getWorkLocation() !== null
                        && LocationService::key($previous->getWorkLocation()) !== LocationService::key($location)) {
                        throw new \DomainException('This Job ID already has a different recorded work location. Choose its existing location or contact your administrator.', 409);
                    }
                }
            }
            $job->setWorkLocation($location);
            $job = $this->jobs->insert($job);
            $this->storage->synchronizeJob($reference);
            return $job;
        }, $reference);
    }

    public function clockOut(string $userId, int $jobId): ?string {
        if ($jobId <= 0) {
            throw new \DomainException('A valid job interval ID is required.', 400);
        }
        $active = $this->jobs->findOpenForUser($userId);
        if ($active === null || $active->getId() !== $jobId) {
            throw new \DomainException('This job interval is no longer active. Refresh the page before trying again.', 409);
        }
        return $this->transaction->run($userId, function () use ($userId, $jobId): ?string {
            $job = $this->jobs->findOpenForUser($userId);
            if ($job === null || $job->getId() !== $jobId) {
                throw new \DomainException('This job interval is no longer active. Refresh the page before trying again.', 409);
            }
            $now = new DateTimeImmutable('@' . $this->time->getTime());
            $seconds = $now->getTimestamp() - $this->shiftClock->parseTimestamp($job->getClockIn())->getTimestamp();
            if ($seconds < 0) {
                throw new \RuntimeException('The server clock is earlier than the job start. The job was not changed.');
            }
            $rounding = $this->rounding->capture('job', $seconds);
            if (!$this->jobs->closeJob($job, $now->format('Y-m-d H:i:s'), $seconds, $rounding['interval'], $rounding['seconds'])) {
                throw new \DomainException('This job interval was changed by another request. Refresh the page.', 409);
            }
            try {
                $this->storage->synchronizeJob($job->getJobReference());
            } catch (\DomainException $error) {
                $this->logger->error('Unable to synchronize the Job workbook during clock-out.', [
                    'app' => 'timesheets', 'jobId' => $jobId, 'exception' => $error,
                ]);
                return 'You are clocked out and your time is saved, but the Job workbook could not be updated. Contact your administrator. The next successful Job clock-in or clock-out will rebuild it from saved records.';
            }
            return null;
        }, $active->getJobReference());
    }
}
