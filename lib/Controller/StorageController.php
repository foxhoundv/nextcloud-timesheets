<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\AccessService;
use OCA\Timesheets\Service\StorageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IUserSession;

class StorageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private AccessService $access,
        private StorageService $storage,
        private IL10N $l,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function save(
        string $payrollRoot,
        string $jobRoot,
        string $jobReferenceFormat = 'SO{year:2}{sequence:4}',
        string $jobYearBase = '2000',
        string $jobFolderTemplate = '{year}/{group}/{jobId}',
        string $jobGroupFirstEnd = '99',
        string $jobGroupSize = '100',
        string $jobGroupNameFormat = '{start}-{end}',
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->access->isTimesheetsAdmin($user->getUID())) {
            return $this->denied();
        }
        try {
            $this->storage->saveRoots(
                $user->getUID(),
                $payrollRoot,
                $jobRoot,
                $jobReferenceFormat,
                $jobYearBase,
                $jobFolderTemplate,
                $jobGroupFirstEnd,
                $jobGroupSize,
                $jobGroupNameFormat,
            );
        } catch (\DomainException $error) {
            return $this->error($error);
        }
        return new JSONResponse(['status' => 'ok']);
    }

    #[NoAdminRequired]
    public function payroll(string $period, string $date): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->access->isTimesheetsAdmin($user->getUID())) {
            return $this->denied();
        }
        try {
            $report = $this->storage->generatePayroll($period, $date);
        } catch (\DomainException $error) {
            return $this->error($error);
        }
        if ($report['count'] === 0) {
            return new JSONResponse([
                'status' => 'ok',
                'message' => $this->l->t('No completed shifts were found for that period; no workbooks were created.'),
                'files' => [],
            ]);
        }
        return new JSONResponse([
            'status' => 'ok',
            'message' => $this->l->t('Generated %s employee workbook(s) for %s through %s.',
                [$report['count'], $report['start'], $report['end']]),
            'files' => $report['files'],
        ]);
    }

    #[NoAdminRequired]
    public function job(string $reference): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->access->isTimesheetsAdmin($user->getUID())) {
            return $this->denied();
        }
        try {
            $report = $this->storage->generateJobReport($reference);
        } catch (\DomainException $error) {
            return $this->error($error);
        }
        return new JSONResponse([
            'status' => 'ok',
            'message' => $this->l->t('Generated the Job time log with %s completed interval(s).',
                [$report['count']]),
            'file' => $report['folder'],
        ]);
    }

    private function denied(): JSONResponse {
        return new JSONResponse([
            'message' => $this->l->t('You do not have permission to manage Timesheets storage and reports.'),
        ], 403);
    }

    private function error(\DomainException $error): JSONResponse {
        $status = in_array($error->getCode(), [400, 404, 409], true) ? $error->getCode() : 400;
        return new JSONResponse(['message' => $this->l->t($error->getMessage())], $status);
    }
}
