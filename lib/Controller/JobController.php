<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\JobClockService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class JobController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private JobClockService $clock,
        private IUserSession $userSession,
        private IL10N $l,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function clockIn(string $reference, ?string $country = null, ?string $region = null): JSONResponse {
        return $this->record($reference, null, $country, $region);
    }

    #[NoAdminRequired]
    public function clockOut(int $jobId): JSONResponse {
        return $this->record('', $jobId);
    }

    private function record(string $reference, ?int $jobId, ?string $country = null, ?string $region = null): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => $this->l->t('Please sign in to record time.')], 401);
        }
        $warning = null;
        try {
            if ($jobId === null) {
                $this->clock->clockIn($user->getUID(), $reference, $country, $region);
            } else {
                $warning = $this->clock->clockOut($user->getUID(), $jobId);
            }
        } catch (\DomainException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], $error->getCode());
        }
        return new JSONResponse(['status' => 'ok', 'warning' => $warning === null ? null : $this->l->t($warning)]);
    }
}
