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

        return new TemplateResponse('timesheets', 'main', [
            'stats' => $stats,
            'entries' => $entries,
        ]);
    }

    private function buildStats(array $shifts): array {
        $recordedMinutes = 0;
        $completedShiftCount = 0;
        $openShiftCount = 0;

        foreach ($shifts as $shift) {
            $duration = (int) ($shift->getDurationMinutes() ?? 0);
            $status = (string) ($shift->getStatus() ?? 'closed');

            if ($status === 'open') {
                $openShiftCount++;
            } else {
                $completedShiftCount++;
                $recordedMinutes += $duration;
            }
        }

        return [
            ['label' => 'Recorded time', 'value' => $this->formatMinutes($recordedMinutes), 'description' => 'Total saved duration of completed shifts, across all dates.'],
            ['label' => 'Completed shifts', 'value' => (string) $completedShiftCount, 'description' => 'Shifts that have been closed.'],
            ['label' => 'Open shifts', 'value' => (string) $openShiftCount, 'description' => 'Shifts that have not been closed.'],
        ];
    }

    private function buildEntries(array $shifts): array {
        $entries = [];

        foreach ($shifts as $shift) {
            $status = (string) ($shift->getStatus() ?? 'closed');
            $durationMinutes = (int) ($shift->getDurationMinutes() ?? 0);

            $entries[] = [
                'date' => (string) ($shift->getShiftDate() ?? '—'),
                'total' => $status === 'open' ? null : $this->formatMinutes($durationMinutes),
                'status' => $status === 'open' ? 'In progress' : 'Closed',
                'isOpen' => $status === 'open',
            ];
        }

        return $entries;
    }

    private function formatMinutes(int $minutes): string {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%dh %02dm', $hours, $mins);
    }
}