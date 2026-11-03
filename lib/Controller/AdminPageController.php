<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\AccessService;
use OCA\Timesheets\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

class AdminPageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private AccessService $access,
        private AdminSettings $settings,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->access->isTimesheetsAdmin($user->getUID())) {
            $response = new TemplateResponse('timesheets', 'denied', [
                'denialMessage' => 'Ask a Nextcloud administrator to delegate Timesheets administration to you or one of your groups.',
            ], 'user');
            $response->setStatus(403);
            return $response;
        }

        return new TemplateResponse(
            'timesheets',
            'settings/admin',
            $this->settings->getFormParameters($this->access->isNextcloudAdmin($user->getUID())),
            'user',
        );
    }
}
