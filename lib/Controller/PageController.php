<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
    ) {
        parent::__construct($appName, $request);
    }

    public function index(): TemplateResponse {
        $stats = [
            ['label' => 'This week', 'value' => '32.5h'],
            ['label' => 'Job time', 'value' => '18.0h'],
            ['label' => 'Shop time', 'value' => '14.5h'],
            ['label' => 'Open shifts', 'value' => '2'],
        ];

        $entries = [
            ['date' => '2026-10-29', 'shift' => 'Morning shift', 'total' => '7.5h', 'status' => 'Closed'],
            ['date' => '2026-10-28', 'shift' => 'Swing shift', 'total' => '8.0h', 'status' => 'Closed'],
            ['date' => '2026-10-27', 'shift' => 'Open shift', 'total' => '4.0h', 'status' => 'In progress'],
        ];

        return new TemplateResponse('timesheets', 'main', [
            'stats' => $stats,
            'entries' => $entries,
        ]);
    }
}