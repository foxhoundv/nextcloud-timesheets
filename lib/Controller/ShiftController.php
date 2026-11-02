<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\ShiftClockService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class ShiftController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ShiftClockService $clock,
        private IUserSession $userSession,
        private IL10N $l,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function clockIn(): JSONResponse {
        return $this->record(null);
    }

    #[NoAdminRequired]
    public function clockOut(int $shiftId): JSONResponse {
        return $this->record($shiftId);
    }

    private function record(?int $shiftId): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => $this->l->t('Please sign in to record time.')], 401);
        }
        try {
            if ($shiftId === null) {
                $this->clock->clockIn($user->getUID());
            } else {
                $this->clock->clockOut($user->getUID(), $shiftId);
            }
        } catch (\DomainException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], $error->getCode());
        }
        return new JSONResponse(['status' => 'ok']);
    }
}
