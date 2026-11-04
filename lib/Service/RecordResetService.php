<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class RecordResetService {
    public function __construct(
        private IDBConnection $db,
        private ClockTransaction $transaction,
        private AccessService $access,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
    }

    public function status(string $actorId): array {
        return $this->transaction->exclusive(function () use ($actorId): array {
            $this->authorize($actorId);
            $current = $this->current();
            return $current + ['approvedByYou' => in_array($actorId, $current['approved'], true)];
        });
    }

    public function approve(string $actorId, string $confirmation, string $snapshot, string $requestId): array {
        if ($confirmation !== 'DELETE') {
            throw new \DomainException('Type DELETE exactly to approve deletion.', 400);
        }
        // Return errors after commit so expired or invalidated approvals are actually cleared.
        $result = $this->transaction->exclusive(function () use ($actorId, $snapshot, $requestId): array {
            $this->authorize($actorId);
            $current = $this->current();
            if (!hash_equals($current['snapshot'], $snapshot) || $current['requestId'] !== $requestId
                || $current['notice'] !== null) {
                return ['error' => 'The records, administrators, or deletion request changed. Review the current status and confirm again.'];
            }
            if ($current['counts']['shifts'] + $current['counts']['jobs'] === 0) {
                return ['error' => 'There are no Timesheets database records to delete.'];
            }
            $request = $this->readRequest();
            if ($request === null) {
                $request = [
                    'id' => bin2hex(random_bytes(16)),
                    'snapshot' => $current['snapshot'],
                    'administrators' => $current['administrators'],
                    'approved' => [],
                    'expiresAt' => $this->time->getTime() + 86400,
                ];
            }
            if (!in_array($actorId, $request['approved'], true)) {
                $request['approved'][] = $actorId;
                sort($request['approved'], SORT_STRING);
            }
            if ($request['approved'] !== $current['administrators']) {
                $this->writeRequest($request);
                $current = $this->current();
                return $current + ['approvedByYou' => true];
            }
            $latest = $this->snapshot();
            if ($latest['snapshot'] !== $current['snapshot']) {
                $this->writeRequest(null);
                return ['error' => 'Records or administrator membership changed before deletion. All approvals have been reset.'];
            }
            foreach (['jobs', 'shifts'] as $kind) {
                $affected = $this->db->getQueryBuilder()->delete('timesheets_' . $kind)->executeStatement();
                if ($affected !== $current['counts'][$kind]) {
                    throw new \RuntimeException('The Timesheets record count changed during deletion. No records were deleted; refresh and try again.');
                }
            }
            $this->writeRequest(null);
            $this->logger->warning('All Timesheets database time records deleted with unanimous administrator approval.', [
                'app' => 'timesheets', 'actor' => $actorId, 'administrators' => $request['approved'],
                'requestId' => $request['id'], 'deleted' => $current['counts'],
            ]);
            return ['deleted' => true, 'counts' => $current['counts']];
        });
        if (isset($result['error'])) {
            throw new \DomainException($result['error'], 409);
        }
        return $result;
    }

    public function cancel(string $actorId, string $requestId): void {
        $this->transaction->exclusive(function () use ($actorId, $requestId): void {
            $this->authorize($actorId);
            $request = $this->readRequest();
            if ($request === null || $request['id'] !== $requestId) {
                throw new \DomainException('This deletion request is no longer pending. Refresh its status.', 409);
            }
            $this->writeRequest(null);
            $this->logger->info('Timesheets record deletion request canceled.', ['app' => 'timesheets', 'actor' => $actorId]);
        });
    }

    private function authorize(string $actorId): void {
        if (!$this->access->isTimesheetsAdmin($actorId)) {
            throw new \DomainException('You do not have permission to delete Timesheets records.', 403);
        }
    }

    private function current(): array {
        $current = $this->snapshot();
        $request = $this->readRequest();
        $notice = null;
        if ($request !== null && ($request['snapshot'] !== $current['snapshot'] || $request['expiresAt'] <= $this->time->getTime())) {
            $notice = $request['expiresAt'] <= $this->time->getTime()
                ? 'The deletion request expired. All administrators must approve again.'
                : 'Time records or administrator membership changed. All deletion approvals have been reset.';
            $this->writeRequest(null);
            $request = null;
        }
        return $current + [
            'requestId' => $request['id'] ?? '',
            'approved' => $request['approved'] ?? [],
            'expiresAt' => $request['expiresAt'] ?? null,
            'notice' => $notice,
            'deleted' => false,
        ];
    }

    private function snapshot(): array {
        $administrators = $this->access->administrators();
        $hash = hash_init('sha256');
        hash_update($hash, json_encode($administrators, JSON_THROW_ON_ERROR));
        $counts = ['shifts' => 0, 'jobs' => 0, 'activeShifts' => 0, 'activeJobs' => 0];
        foreach (['shifts', 'jobs'] as $kind) {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('*')->from('timesheets_' . $kind)->orderBy('id', 'ASC')->executeQuery();
            try {
                while ($row = $result->fetch()) {
                    ksort($row, SORT_STRING);
                    hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
                    $counts[$kind]++;
                    if ($row['open_user_id'] !== null) {
                        $counts[$kind === 'shifts' ? 'activeShifts' : 'activeJobs']++;
                    }
                }
            } finally {
                $result->closeCursor();
            }
        }
        return ['snapshot' => hash_final($hash), 'administrators' => $administrators, 'counts' => $counts];
    }

    private function readRequest(): ?array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('request')->from('timesheets_reset')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))->executeQuery();
        try {
            $row = $result->fetch();
            if ($row === false) {
                throw new \RuntimeException('The Timesheets reset coordination row is missing.');
            }
            return $row['request'] === null ? null : json_decode($row['request'], true, 32, JSON_THROW_ON_ERROR);
        } finally {
            $result->closeCursor();
        }
    }

    private function writeRequest(?array $request): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('timesheets_reset')
            ->set('request', $qb->createNamedParameter($request === null ? null : json_encode($request, JSON_THROW_ON_ERROR)))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))->executeStatement();
    }
}
