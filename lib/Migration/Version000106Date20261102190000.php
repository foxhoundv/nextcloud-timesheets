<?php

declare(strict_types=1);

namespace OCA\Timesheets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000106Date20261102190000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('timesheets_shifts')) {
            return;
        }

        $query = $this->db->getQueryBuilder();
        $query->select('user_id')
            ->from('timesheets_shifts')
            ->where($query->expr()->eq('status', $query->createNamedParameter('open')))
            ->groupBy('user_id')
            ->having($query->expr()->gt($query->func()->count('id'), $query->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        $result = $query->executeQuery();
        try {
            if ($result->fetch() !== false) {
                throw new \RuntimeException('Timesheets has multiple open shifts for a user. Resolve duplicate open shifts before upgrading.');
            }
        } finally {
            $result->closeCursor();
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('timesheets_shifts');
        $table->addColumn('open_user_id', Types::STRING, [
            'length' => 255,
            'notnull' => false,
        ]);
        $table->addUniqueIndex(['open_user_id'], 'ts_shifts_open_user');
        $table->addColumn('time_zone', Types::STRING, [
            'length' => 64,
            'notnull' => true,
            'default' => 'UTC',
        ]);
        $table->addColumn('duration_seconds', Types::INTEGER, [
            'notnull' => false,
        ]);
        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $query = $this->db->getQueryBuilder();
        $query->update('timesheets_shifts')
            ->set('open_user_id', 'user_id')
            ->where($query->expr()->eq('status', $query->createNamedParameter('open')));
        $query->executeStatement();
    }
}
