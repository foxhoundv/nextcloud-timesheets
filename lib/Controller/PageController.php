<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\ShiftService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ShiftService $shiftService,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : '';

        $shifts = $this->shiftService->listForUser($userId);
        $stats = $this->buildStats($shifts);

        $entries = $this->buildEntries($shifts);

        if ($entries === []) {
            $entries = [
                ['date' => 'No shifts yet', 'shift' => 'Waiting for clock-ins', 'total' => '0.0h', 'status' => 'Open'],
            ];
        }

        return new TemplateResponse('timesheets', 'main', [
            'stats' => $stats,
            'entries' => $entries,
        ]);
    }

    private function buildStats(array $shifts): array {
        $weekMinutes = 0;
        $jobMinutes = 0;
        $shopMinutes = 0;
        $openShiftCount = 0;

        foreach ($shifts as $shift) {
            $duration = (int) ($shift->getDurationMinutes() ?? 0);
            $status = (string) ($shift->getStatus() ?? 'closed');

            if ($status === 'open') {
                $openShiftCount++;
            }

            $weekMinutes += $duration;
            $shopMinutes += $duration;
        }

        return [
            ['label' => 'This week', 'value' => $this->formatMinutes($weekMinutes)],
            ['label' => 'Job time', 'value' => $this->formatMinutes($jobMinutes)],
            ['label' => 'Shop time', 'value' => $this->formatMinutes($shopMinutes)],
            ['label' => 'Open shifts', 'value' => (string) $openShiftCount],
        ];
    }

    private function buildEntries(array $shifts): array {
        $entries = [];

        foreach ($shifts as $shift) {
            $status = (string) ($shift->getStatus() ?? 'closed');
            $durationMinutes = (int) ($shift->getDurationMinutes() ?? 0);

            $entries[] = [
                'date' => (string) ($shift->getShiftDate() ?? '—'),
                'shift' => 'Shift ' . $status,
                'total' => $this->formatMinutes($durationMinutes),
                'status' => $status === 'open' ? 'In progress' : 'Closed',
            ];
        }

        return $entries;
    }

    private function formatMinutes(int $minutes): string {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%d.%02dh', $hours, $mins);
    }
}