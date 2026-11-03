<?php

declare(strict_types=1);

namespace OCA\Timesheets\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ShiftMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'timesheets_shifts', Shift::class);
    }

    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('clock_in', 'DESC');

        return $this->findEntities($qb);
    }

    public function findCompletedBetween(string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->gte('shift_date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('shift_date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->isNotNull('clock_out'))
            ->orderBy('user_id', 'ASC')
            ->addOrderBy('shift_date', 'ASC')
            ->addOrderBy('clock_in', 'ASC');
        return $this->findEntities($qb);
    }

    public function findOpenForUser(string $userId): ?Shift {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('open_user_id', $qb->createNamedParameter($userId)));
        $shifts = $this->findEntities($qb);
        return $shifts[0] ?? null;
    }

    public function closeShift(Shift $shift, string $clockOut, int $seconds, int $roundingInterval, int $roundedSeconds): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('clock_out', $qb->createNamedParameter($clockOut))
            ->set('duration_seconds', $qb->createNamedParameter($seconds, IQueryBuilder::PARAM_INT))
            ->set('duration_minutes', $qb->createNamedParameter(intdiv($seconds, 60), IQueryBuilder::PARAM_INT))
            ->set('rounding_interval', $qb->createNamedParameter($roundingInterval, IQueryBuilder::PARAM_INT))
            ->set('rounded_seconds', $qb->createNamedParameter($roundedSeconds, IQueryBuilder::PARAM_INT))
            ->set('status', $qb->createNamedParameter('closed'))
            ->set('open_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($shift->getId(), IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($shift->getUserId())))
            ->andWhere($qb->expr()->eq('open_user_id', $qb->createNamedParameter($shift->getUserId())))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')));
        return $qb->executeStatement() === 1;
    }
}
