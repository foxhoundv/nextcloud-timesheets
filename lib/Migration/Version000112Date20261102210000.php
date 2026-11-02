<?php

declare(strict_types=1);

namespace OCA\Timesheets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000112Date20261102210000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->createTable('timesheets_jobs');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('user_id', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('job_reference', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('job_date', Types::DATE, ['notnull' => true]);
        $table->addColumn('clock_in', Types::DATETIME, ['notnull' => true]);
        $table->addColumn('clock_out', Types::DATETIME, ['notnull' => false]);
        $table->addColumn('duration_seconds', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('time_zone', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('open_user_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['open_user_id'], 'ts_jobs_open_user');
        $table->addIndex(['user_id'], 'ts_jobs_user_idx');

        $locks = $schema->createTable('timesheets_clock_locks');
        $locks->addColumn('user_id', Types::STRING, ['length' => 255, 'notnull' => true]);
        $locks->addColumn('locked', Types::INTEGER, ['default' => 0, 'notnull' => true]);
        $locks->setPrimaryKey(['user_id']);
        return $schema;
    }
}
