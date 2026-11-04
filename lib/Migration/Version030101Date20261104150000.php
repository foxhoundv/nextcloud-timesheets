<?php

declare(strict_types=1);

namespace OCA\Timesheets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version030101Date20261104150000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->createTable('timesheets_reset');
        $table->addColumn('id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('locked', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('request', Types::TEXT, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        return $schema;
    }
}
