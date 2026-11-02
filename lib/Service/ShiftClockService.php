<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Db\JobMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IConfig;

class ShiftClockService {
    public function __construct(
        private ShiftMapper $shiftMapper,
        private IConfig $config,
        private ITimeFactory $time,
        private AccessService $access,
        private ClockTransaction $transaction,
        private JobMapper $jobs,
    ) {
    }

    public function isEnabled(): bool {
        return $this->config->getAppValue('timesheets', 'shift_tracking', '0') === '1';
    }

    public function userTimeZone(string $userId): DateTimeZone {
        return new DateTimeZone($this->config->getUserValue($userId, 'core', 'timezone', 'UTC'));
    }

    public function current(string $userId): ?array {
        $shift = $this->shiftMapper->findOpenForUser($userId);
        if ($shift === null) {
            return null;
        }
        $clockIn = $this->parseTimestamp($shift->getClockIn());
        $zone = new DateTimeZone($shift->getTimeZone());
        return [
            'id' => $shift->getId(),
            'clockIn' => $clockIn->format(DATE_ATOM),
            'startedAt' => $clockIn->setTimezone($zone)->format('Y-m-d H:i:s T'),
            'elapsedSeconds' => max(0, $this->time->getTime() - $clockIn->getTimestamp()),
        ];
    }

    public function clockIn(string $userId): Shift {
        return $this->transaction->run($userId, fn (): Shift => $this->start($userId));
    }

    private function start(string $userId): Shift {
        if (!$this->isEnabled()) {
            throw new \DomainException('Shift tracking is disabled. Ask an administrator to enable it.', 403);
        }
        if (!$this->access->permissions($userId)['shift']) {
            throw new \DomainException('You do not have permission to start shifts. Ask an administrator for access.', 403);
        }
        $zone = $this->userTimeZone($userId);
        $now = $this->now();
        $shift = new Shift();
        $shift->setUserId($userId);
        $shift->setOpenUserId($userId);
        $shift->setTimeZone($zone->getName());
        $shift->setShiftDate($now->setTimezone($zone)->format('Y-m-d'));
        $shift->setClockIn($now->format('Y-m-d H:i:s'));
        $shift->setDurationMinutes(0);
        $shift->setStatus('open');
        try {
            return $this->shiftMapper->insert($shift);
        } catch (Exception $error) {
            if ($error->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $error;
            }
            throw new \DomainException('You already have an open shift. Refresh the page to see it.', 409, $error);
        }
    }

    public function clockOut(string $userId, int $shiftId): void {
        $this->transaction->run($userId, fn () => $this->stop($userId, $shiftId));
    }

    private function stop(string $userId, int $shiftId): void {
        if ($shiftId <= 0) {
            throw new \DomainException('A valid shift ID is required.', 400);
        }
        $shift = $this->shiftMapper->findOpenForUser($userId);
        if ($shift === null || $shift->getId() !== $shiftId) {
            throw new \DomainException('This shift is no longer open. Refresh the page before trying again.', 409);
        }
        if ($this->jobs->findOpenForUser($userId) !== null
            && $this->config->getAppValue('timesheets', 'shift_end_job_policy', 'block') === 'block') {
            throw new \DomainException('End your active job before clocking out of the shift.', 409);
        }
        $now = $this->now();
        $seconds = $now->getTimestamp() - $this->parseTimestamp($shift->getClockIn())->getTimestamp();
        if ($seconds < 0) {
            throw new \RuntimeException('The server clock is earlier than the shift start. The shift was not changed.');
        }
        if (!$this->shiftMapper->closeShift($shift, $now->format('Y-m-d H:i:s'), $seconds)) {
            throw new \DomainException('This shift was changed by another request. Refresh the page.', 409);
        }
    }

    public function parseTimestamp(?string $value): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value ?? '', new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new \RuntimeException('The shift has an invalid timestamp. Ask an administrator to review it.');
        }
        return $date;
    }

    private function now(): DateTimeImmutable {
        return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
    }
}
