<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class AdminController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    public function save(
        string $shift_tracking = '0',
        string $job_tracking = '0',
        string $shift_rounding = '15',
        string $job_rounding = '15',
        string $net_shift_threshold = '0',
    ): JSONResponse {
        $allowedRounding = ['5', '10', '15', '30'];

        $settings = [
            'shift_tracking' => $shift_tracking === '1' ? '1' : '0',
            'job_tracking' => $job_tracking === '1' ? '1' : '0',
            'shift_rounding' => in_array($shift_rounding, $allowedRounding, true) ? $shift_rounding : '15',
            'job_rounding' => in_array($job_rounding, $allowedRounding, true) ? $job_rounding : '15',
            'net_shift_threshold' => max(0, (int) $net_shift_threshold),
        ];

        foreach ($settings as $key => $value) {
            $this->config->setAppValue('timesheets', $key, (string) $value);
        }

        return new JSONResponse([
            'status' => 'ok',
            'saved' => $settings,
        ]);
    }
}
