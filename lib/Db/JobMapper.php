<?php

declare(strict_types=1);

namespace OCA\Timesheets\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class JobMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'timesheets_jobs', Job::class);
    }

    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('clock_in', 'DESC')->addOrderBy('id', 'DESC');
        return $this->findEntities($qb);
    }

    public function findOpenForUser(string $userId): ?Job {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('open_user_id', $qb->createNamedParameter($userId)));
        return $this->findEntities($qb)[0] ?? null;
    }

    public function closeJob(Job $job, string $clockOut, int $seconds): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('clock_out', $qb->createNamedParameter($clockOut))
            ->set('duration_seconds', $qb->createNamedParameter($seconds, IQueryBuilder::PARAM_INT))
            ->set('open_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($job->getId(), IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($job->getUserId())))
            ->andWhere($qb->expr()->eq('open_user_id', $qb->createNamedParameter($job->getUserId())))
            ->andWhere($qb->expr()->isNull('clock_out'));
        return $qb->executeStatement() === 1;
    }
}
