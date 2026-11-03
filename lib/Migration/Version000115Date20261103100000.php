<?php

declare(strict_types=1);

namespace OCA\Timesheets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000115Date20261103100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        foreach (['timesheets_shifts', 'timesheets_jobs'] as $name) {
            $table = $schema->getTable($name);
            $table->addColumn('rounding_interval', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('rounded_seconds', Types::INTEGER, ['notnull' => false]);
        }
        return $schema;
    }
}
