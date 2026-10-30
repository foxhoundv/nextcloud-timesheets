<?php

declare(strict_types=1);

namespace OCA\Timesheets\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function __construct(
        private IConfig $config,
    ) {
    }

    public function getForm(): TemplateResponse {
        $settings = [
            'shift_tracking' => $this->config->getAppValue('timesheets', 'shift_tracking', '0') === '1',
            'job_tracking' => $this->config->getAppValue('timesheets', 'job_tracking', '0') === '1',
            'shift_rounding' => $this->config->getAppValue('timesheets', 'shift_rounding', '15'),
            'job_rounding' => $this->config->getAppValue('timesheets', 'job_rounding', '15'),
            'net_shift_threshold' => (int) $this->config->getAppValue('timesheets', 'net_shift_threshold', '0'),
        ];

        return new TemplateResponse('timesheets', 'settings/admin', ['settings' => $settings]);
    }

    public function getSection(): string {
        return 'timesheets';
    }

    public function getPriority(): int {
        return 80;
    }
}
