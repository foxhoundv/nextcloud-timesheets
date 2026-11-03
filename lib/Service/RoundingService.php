<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCP\IConfig;

class RoundingService {
    public function __construct(private IConfig $config) {
    }

    /** @return array{interval: int, seconds: int} */
    public function capture(string $type, int $seconds): array {
        if (!in_array($type, ['shift', 'job'], true) || $seconds < 0) {
            throw new \InvalidArgumentException('A valid tracking type and nonnegative duration are required.');
        }
        $enabled = $this->config->getAppValue('timesheets', $type . '_rounding_enabled', '0');
        if (!in_array($enabled, ['0', '1'], true)) {
            throw new \RuntimeException('Invalid rounding preference. Ask an administrator to review it.');
        }
        if ($enabled === '0') {
            return ['interval' => 0, 'seconds' => $seconds];
        }
        $interval = $this->config->getAppValue('timesheets', $type . '_rounding', '15');
        if (!in_array($interval, ['5', '10', '15', '30'], true)) {
            throw new \RuntimeException('Invalid rounding interval. Ask an administrator to review it.');
        }
        $step = (int) $interval * 60;
        $remainder = $seconds % $step;
        $rounded = $seconds - $remainder;
        if ($remainder >= intdiv($step, 2)) {
            $rounded += $step;
        }
        return ['interval' => (int) $interval, 'seconds' => $rounded];
    }
}
