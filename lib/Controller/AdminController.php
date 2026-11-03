<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IL10N;

class AdminController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IL10N $l,
    ) {
        parent::__construct($appName, $request);
    }

    public function save(
        string $shift_tracking = '0',
        string $job_tracking = '0',
        string $shift_rounding = '15',
        string $job_rounding = '15',
        string $net_shift_threshold = '0',
        ?string $job_require_shift = null,
        ?string $shift_end_job_policy = null,
        ?string $shift_rounding_enabled = null,
        ?string $job_rounding_enabled = null,
    ): JSONResponse {
        if (($job_require_shift !== null && !in_array($job_require_shift, ['0', '1'], true))
            || ($shift_end_job_policy !== null && !in_array($shift_end_job_policy, ['block', 'allow'], true))) {
            return new JSONResponse(['message' => $this->l->t('Choose valid shift/job interaction settings.')], 400);
        }
        $allowedRounding = ['5', '10', '15', '30'];
        if (!in_array($shift_rounding, $allowedRounding, true)
            || !in_array($job_rounding, $allowedRounding, true)
            || ($shift_rounding_enabled !== null && !in_array($shift_rounding_enabled, ['0', '1'], true))
            || ($job_rounding_enabled !== null && !in_array($job_rounding_enabled, ['0', '1'], true))) {
            return new JSONResponse(['message' => $this->l->t('Choose valid rounding settings.')], 400);
        }

        $settings = [
            'shift_tracking' => $shift_tracking === '1' ? '1' : '0',
            'job_tracking' => $job_tracking === '1' ? '1' : '0',
            'shift_rounding' => $shift_rounding,
            'job_rounding' => $job_rounding,
            'net_shift_threshold' => max(0, (int) $net_shift_threshold),
        ];
        if ($job_require_shift !== null) {
            $settings['job_require_shift'] = $job_require_shift;
        }
        if ($shift_end_job_policy !== null) {
            $settings['shift_end_job_policy'] = $shift_end_job_policy;
        }
        if ($shift_rounding_enabled !== null) {
            $settings['shift_rounding_enabled'] = $shift_rounding_enabled;
        }
        if ($job_rounding_enabled !== null) {
            $settings['job_rounding_enabled'] = $job_rounding_enabled;
        }

        foreach ($settings as $key => $value) {
            $this->config->setAppValue('timesheets', $key, (string) $value);
        }

        return new JSONResponse([
            'status' => 'ok',
            'saved' => $settings,
        ]);
    }
}
