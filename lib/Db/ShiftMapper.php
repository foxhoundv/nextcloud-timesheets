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

    public function ensureTable(): void {
        $tableName = $this->getTableName();
        $sql = "CREATE TABLE IF NOT EXISTS `" . $tableName . "` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` VARCHAR(255) NOT NULL,
            `shift_date` DATE DEFAULT NULL,
            `clock_in` DATETIME DEFAULT NULL,
            `clock_out` DATETIME DEFAULT NULL,
            `duration_minutes` INT DEFAULT 0,
            `status` VARCHAR(32) DEFAULT 'closed',
            PRIMARY KEY (`id`),
            KEY `idx_timesheets_user_id` (`user_id`),
            KEY `idx_timesheets_shift_date` (`shift_date`),
            KEY `idx_timesheets_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $this->db->executeStatement($sql);
    }

    public function findByUser(string $userId): array {
        $this->ensureTable();

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('clock_in', 'DESC');

        return $this->findEntities($qb);
    }

    public function insert(Shift $entity): Shift {
        $this->ensureTable();
        return parent::insert($entity);
    }
}
