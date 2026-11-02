<?php

declare(strict_types=1);

namespace OCA\Timesheets\Controller;

use OCA\Timesheets\Service\AccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

class AccessController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private AccessService $access,
        private IL10N $l,
    ) {
        parent::__construct($appName, $request);
    }

    public function save(string $policy): JSONResponse {
        try {
            $this->access->save($policy);
        } catch (\DomainException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 400);
        }
        return new JSONResponse(['status' => 'ok']);
    }

    public function search(string $type, string $query): JSONResponse {
        try {
            return new JSONResponse(['results' => $this->access->search($type, $query)]);
        } catch (\DomainException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 400);
        }
    }
}
