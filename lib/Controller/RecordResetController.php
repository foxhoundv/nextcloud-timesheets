<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\RecordResetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class RecordResetController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private RecordResetService $reset,
        private IUserSession $userSession,
        private IL10N $l,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function status(): JSONResponse {
        return $this->respond(fn (string $id): array => $this->reset->status($id));
    }

    #[NoAdminRequired]
    public function approve(string $confirmation, string $snapshot, string $requestId = ''): JSONResponse {
        return $this->respond(fn (string $id): array => $this->reset->approve($id, $confirmation, $snapshot, $requestId));
    }

    #[NoAdminRequired]
    public function cancel(string $requestId): JSONResponse {
        return $this->respond(function (string $id) use ($requestId): array {
            $this->reset->cancel($id, $requestId);
            return ['canceled' => true];
        });
    }

    private function respond(callable $action): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => $this->l->t('Please sign in to manage Timesheets records.')], 401);
        }
        try {
            return new JSONResponse(['status' => 'ok', 'result' => $action($user->getUID())]);
        } catch (\DomainException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], $error->getCode());
        }
    }
}
