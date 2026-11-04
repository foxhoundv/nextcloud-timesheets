<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCP\IDBConnection;

class ClockTransaction {
    public function __construct(private IDBConnection $db) {
    }

    public function run(string $userId, callable $action, ?string $jobReference = null): mixed {
        $this->db->insertIgnoreConflict('*PREFIX*timesheets_clock_locks', ['user_id' => $userId, 'locked' => 0]);
        $lockIds = [$userId];
        if ($jobReference !== null) {
            $jobLock = 'job:' . hash('sha256', $jobReference);
            $this->db->insertIgnoreConflict('*PREFIX*timesheets_clock_locks', ['user_id' => $jobLock, 'locked' => 0]);
            $lockIds[] = $jobLock;
        }
        $this->db->beginTransaction();
        try {
            // Hold the user and optional Job workbook locks through the database commit.
            foreach ($lockIds as $lockId) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('timesheets_clock_locks')->set('locked', $qb->createNamedParameter(1))
                    ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($lockId)));
                $qb->executeStatement();
            }
            $result = $action();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
