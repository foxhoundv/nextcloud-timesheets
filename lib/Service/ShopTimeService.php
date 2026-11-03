<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCA\Timesheets\Db\Job;
use OCA\Timesheets\Db\Shift;

class ShopTimeService {
    public function __construct(private ShiftClockService $clock) {
    }

    /**
     * @param Shift[] $shifts
     * @param Job[] $jobs
     * @return array{shifts: array<int, array{totalSeconds: int, jobSeconds: int, shopSeconds: int}>, jobSeconds: int, shopSeconds: int, unavailableShifts: int}
     */
    public function calculate(array $shifts, array $jobs): array {
        $intervals = [];
        foreach ($jobs as $job) {
            $start = $this->clock->parseTimestamp($job->getClockIn())->getTimestamp();
            $end = $job->getClockOut() === null
                ? PHP_INT_MAX : $this->clock->parseTimestamp($job->getClockOut())->getTimestamp();
            if ($end < $start) {
                throw new \RuntimeException('A job ends before it starts. Ask an administrator to review it.');
            }
            $intervals[] = [$start, $end];
        }
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        $result = ['shifts' => [], 'jobSeconds' => 0, 'shopSeconds' => 0, 'unavailableShifts' => 0];
        $completed = [];
        foreach ($shifts as $shift) {
            if ($shift->getStatus() === 'open') {
                continue;
            }
            if ($shift->getClockIn() === null || $shift->getClockOut() === null) {
                $result['unavailableShifts']++;
                continue;
            }
            $start = $this->clock->parseTimestamp($shift->getClockIn())->getTimestamp();
            $end = $this->clock->parseTimestamp($shift->getClockOut())->getTimestamp();
            if ($end < $start) {
                throw new \RuntimeException('A shift ends before it starts. Ask an administrator to review it.');
            }
            $completed[] = [$start, $end, $shift->getId()];
        }
        usort($completed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $cursor = 0;
        foreach ($completed as [$start, $end, $id]) {
            while (isset($merged[$cursor]) && $merged[$cursor][1] <= $start) {
                $cursor++;
            }
            $jobSeconds = 0;
            for ($i = $cursor; isset($merged[$i]) && $merged[$i][0] < $end; $i++) {
                $jobSeconds += max(0, min($end, $merged[$i][1]) - max($start, $merged[$i][0]));
            }
            $shopSeconds = $end - $start - $jobSeconds;
            $result['shifts'][$id] = [
                'totalSeconds' => $end - $start,
                'jobSeconds' => $jobSeconds,
                'shopSeconds' => $shopSeconds,
            ];
            $result['jobSeconds'] += $jobSeconds;
            $result['shopSeconds'] += $shopSeconds;
        }
        return $result;
    }
}
