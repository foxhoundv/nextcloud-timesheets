<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\AccessService;
use OCA\Timesheets\Service\LocationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class LocationController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private LocationService $locations,
        private AccessService $access,
        private IUserSession $userSession,
        private IL10N $l,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function save(string $settings): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->access->isTimesheetsAdmin($user->getUID())) {
            return new JSONResponse(['message' => $this->l->t('You do not have permission to manage Timesheets settings.')], 403);
        }
        try {
            $this->locations->save($settings);
        } catch (\DomainException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], $error->getCode());
        }
        return new JSONResponse(['status' => 'ok']);
    }
}
