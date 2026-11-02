<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCP\IDBConnection;

class ClockTransaction {
    public function __construct(private IDBConnection $db) {
    }

    public function run(string $userId, callable $action): mixed {
        $this->db->insertIgnoreConflict('*PREFIX*timesheets_clock_locks', ['user_id' => $userId, 'locked' => 0]);
        $this->db->beginTransaction();
        try {
            // All shift/job transitions take this row lock before reading either active interval.
            $qb = $this->db->getQueryBuilder();
            $qb->update('timesheets_clock_locks')->set('locked', $qb->createNamedParameter(1))
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $qb->executeStatement();
            $result = $action();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
