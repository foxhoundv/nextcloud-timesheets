<?php

declare(strict_types=1);

namespace OCA\Timesheets\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020101Date20261104120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        foreach (['timesheets_shifts', 'timesheets_jobs'] as $name) {
            $schema->getTable($name)->addColumn('work_location', Types::TEXT, ['notnull' => false]);
        }
        return $schema;
    }
}
