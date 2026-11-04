<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCA\Timesheets\Db\Shift;

class PayrollLocationService {
    public function __construct(private ShiftClockService $clock) {
    }

    public function split(Shift $shift, array $jobs, int $actual, int $reported): array {
        $parts = [];
        $add = static function (?string $location, int $seconds) use (&$parts): void {
            $saved = $location === null ? null : LocationService::decode($location);
            $key = LocationService::key($location);
            if (!isset($parts[$key])) {
                $parts[$key] = [
                    'country' => $saved['country'] ?? 'Not recorded', 'region' => $saved['region'] ?? '',
                    'actual' => 0, 'reported' => 0,
                ];
            }
            $parts[$key]['actual'] += $seconds;
        };
        if ($shift->getClockIn() === null || $shift->getClockOut() === null) {
            $add($shift->getWorkLocation(), $actual);
        } else {
            $start = $this->clock->parseTimestamp($shift->getClockIn())->getTimestamp();
            $end = $this->clock->parseTimestamp($shift->getClockOut())->getTimestamp();
            if ($end - $start !== $actual || $actual < 0 || $reported < 0) {
                throw new \RuntimeException('A saved shift duration does not match its timestamps. Contact your administrator before exporting payroll.');
            }
            $overlaps = [];
            $boundaries = [$start, $end];
            foreach ($jobs as $job) {
                if ($job->getUserId() !== $shift->getUserId() || $job->getWorkLocation() === null) {
                    continue;
                }
                $from = max($start, $this->clock->parseTimestamp($job->getClockIn())->getTimestamp());
                $to = min($end, $job->getClockOut() === null ? $end
                    : $this->clock->parseTimestamp($job->getClockOut())->getTimestamp());
                if ($to > $from) {
                    $overlaps[] = [$from, $to, $job->getWorkLocation()];
                    $boundaries[] = $from;
                    $boundaries[] = $to;
                }
            }
            $boundaries = array_values(array_unique($boundaries));
            sort($boundaries, SORT_NUMERIC);
            for ($i = 1; $i < count($boundaries); $i++) {
                $location = $shift->getWorkLocation();
                $jobLocation = null;
                foreach ($overlaps as [$from, $to, $snapshot]) {
                    if ($from < $boundaries[$i] && $to > $boundaries[$i - 1]) {
                        if ($jobLocation !== null && LocationService::key($jobLocation) !== LocationService::key($snapshot)) {
                            throw new \RuntimeException('Overlapping Jobs have conflicting locations. Contact your administrator before exporting payroll.');
                        }
                        $location = $jobLocation = $snapshot;
                    }
                }
                $add($location, $boundaries[$i] - $boundaries[$i - 1]);
            }
            if ($actual === 0) {
                $add($shift->getWorkLocation(), 0);
            }
        }
        // Allocate saved rounded shift seconds, preserving the total without rounding each region again.
        $remainders = [];
        $allocated = 0;
        foreach ($parts as $key => &$part) {
            $product = $reported * $part['actual'];
            $part['reported'] = $actual === 0 ? 0 : intdiv($product, $actual);
            $remainders[$key] = $actual === 0 ? 0 : $product % $actual;
            $allocated += $part['reported'];
        }
        unset($part);
        arsort($remainders, SORT_NUMERIC);
        foreach (array_keys($remainders) as $key) {
            if ($allocated >= $reported) {
                break;
            }
            $parts[$key]['reported']++;
            $allocated++;
        }
        if ($allocated !== $reported || array_sum(array_column($parts, 'actual')) !== $actual) {
            throw new \RuntimeException('Work-location payroll totals could not be reconciled. Contact your administrator.');
        }
        return $parts;
    }
}
