<?php

declare(strict_types=1);

namespace OCA\Timesheets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000103Date20261102170000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('timesheets_shifts')) {
            return null;
        }

        $table = $schema->createTable('timesheets_shifts');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'length' => 255,
            'notnull' => true,
        ]);
        $table->addColumn('shift_date', Types::DATE, [
            'notnull' => false,
        ]);
        $table->addColumn('clock_in', Types::DATETIME, [
            'notnull' => false,
        ]);
        $table->addColumn('clock_out', Types::DATETIME, [
            'notnull' => false,
        ]);
        $table->addColumn('duration_minutes', Types::INTEGER, [
            'notnull' => false,
            'default' => 0,
        ]);
        $table->addColumn('status', Types::STRING, [
            'length' => 32,
            'notnull' => false,
            'default' => 'closed',
        ]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'ts_shifts_user_idx');
        $table->addIndex(['shift_date'], 'ts_shifts_date_idx');
        $table->addIndex(['status'], 'ts_shifts_status_idx');

        return $schema;
    }
}
