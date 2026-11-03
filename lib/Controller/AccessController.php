<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\AccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class AccessController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private AccessService $access,
        private IL10N $l,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function save(string $policy): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->access->isTimesheetsAdmin($user->getUID())) {
            return new JSONResponse(['message' => $this->l->t('You do not have permission to manage Timesheets settings.')], 403);
        }
        try {
            $this->access->save($policy, $user->getUID());
        } catch (\DomainException $error) {
            $status = in_array($error->getCode(), [400, 403], true) ? $error->getCode() : 400;
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], $status);
        }
        return new JSONResponse(['status' => 'ok']);
    }

    #[NoAdminRequired]
    public function search(string $type, string $query): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->access->isTimesheetsAdmin($user->getUID())) {
            return new JSONResponse(['message' => $this->l->t('You do not have permission to manage Timesheets settings.')], 403);
        }
        try {
            return new JSONResponse(['results' => $this->access->search($type, $query)]);
        } catch (\DomainException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 400);
        }
    }
}
