<?php

declare(strict_types=1);

namespace OCA\Timesheets\Settings;

use OCA\Timesheets\Service\AccessService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function __construct(
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private AccessService $access,
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

        return new TemplateResponse('timesheets', 'settings/admin', [
            'settings' => $settings,
            'saveUrl' => $this->urlGenerator->linkToRoute('timesheets.admin.save'),
            'accessPolicy' => $this->access->policy(),
            'jobRequireShift' => $this->config->getAppValue('timesheets', 'job_require_shift', '0') === '1',
            'shiftEndJobPolicy' => $this->config->getAppValue('timesheets', 'shift_end_job_policy', 'block'),
            'accessSaveUrl' => $this->urlGenerator->linkToRoute('timesheets.access.save'),
            'accessDirectory' => [
                'group' => $this->access->directory('group'),
                'user' => $this->access->directory('user'),
            ],
        ]);
    }

    public function getSection(): string {
        return 'timesheets';
    }

    public function getPriority(): int {
        return 80;
    }
}
