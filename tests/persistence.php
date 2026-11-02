<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Migration\Version000103Date20261102170000;
use OCA\Timesheets\Service\ShiftService;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$nextcloudRoot = $argv[1] ?? '';
if (!is_file($nextcloudRoot . '/lib/base.php')) {
    fwrite(STDERR, "Usage: php tests/persistence.php /path/to/nextcloud\n");
    exit(2);
}

define('OC_CONSOLE', 1);
require_once $nextcloudRoot . '/lib/base.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
});
require_once __DIR__ . '/../lib/Db/Shift.php';
require_once __DIR__ . '/../lib/Db/ShiftMapper.php';
require_once __DIR__ . '/../lib/Service/ShiftService.php';
require_once __DIR__ . '/../lib/Migration/Version000103Date20261102170000.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }

    echo 'PASS: ' . $message . "\n";
}

$db = Server::get(IDBConnection::class);
$query = $db->getQueryBuilder();
$query->select('version')
    ->from('migrations')
    ->where($query->expr()->eq('app', $query->createNamedParameter('timesheets', IQueryBuilder::PARAM_STR)));
$result = $query->executeQuery();
$versions = $result->fetchFirstColumn();
$result->closeCursor();
check(in_array('000103Date20261102170000', $versions, true), 'Nextcloud recorded the initial migration');
$connection = Server::get(Connection::class);
$schema = new SchemaWrapper($connection, new Schema());
$output = new class implements IOutput {
    public function debug(string $message): void {}
    public function info($message) {}
    public function warning($message) {}
    public function startProgress($max = 0) {}
    public function advance($step = 1, $description = '') {}
    public function finishProgress() {}
};
$migration = new Version000103Date20261102170000();
$schemaClosure = static fn () => $schema;
check($migration->changeSchema($output, $schemaClosure, []) === $schema, 'Migration creates a new schema');
$table = $schema->getWrappedSchema()->getTable($connection->getPrefix() . 'timesheets_shifts');
check(array_keys($table->getColumns()) === [
    'id', 'user_id', 'shift_date', 'clock_in', 'clock_out', 'duration_minutes', 'status',
], 'Migration creates all shift columns using the configured table prefix');
check($table->getColumn('id')->getAutoincrement(), 'Shift IDs auto-increment');
check($table->getPrimaryKey()->getColumns() === ['id'], 'Shift ID is the primary key');
check($table->getColumn('user_id')->getNotnull(), 'User ID is required');
check($table->getColumn('user_id')->getLength() === 255, 'User ID length is preserved');
check(!$table->getColumn('clock_out')->getNotnull(), 'Open shifts may have no clock-out');
check((int) $table->getColumn('duration_minutes')->getDefault() === 0, 'Duration defaults to zero');
check($table->getColumn('status')->getDefault() === 'closed', 'Status defaults to closed');
foreach ([
    'ts_shifts_user_idx' => 'user_id',
    'ts_shifts_date_idx' => 'shift_date',
    'ts_shifts_status_idx' => 'status',
] as $index => $column) {
    check($table->getIndex($index)->getColumns() === [$column], 'Index covers ' . $column);
}
$originalSchema = serialize($schema->getWrappedSchema());
check($migration->changeSchema($output, $schemaClosure, []) === null, 'Existing shift table is not recreated');
check(serialize($schema->getWrappedSchema()) === $originalSchema, 'Existing schema is left unchanged');

$failureMapper = new class($db) extends ShiftMapper {
    public RuntimeException $failure;

    public function __construct(IDBConnection $db) {
        parent::__construct($db);
        $this->failure = new RuntimeException('Simulated database failure');
    }

    public function findByUser(string $userId): array {
        throw $this->failure;
    }

    public function insert(Entity $entity): Entity {
        throw $this->failure;
    }
};
$failureService = new ShiftService($failureMapper);
try {
    $failureService->listForUser('regression-test');
    throw new RuntimeException('Read failure was hidden');
} catch (RuntimeException $error) {
    check($error === $failureMapper->failure, 'Service propagates the original read failure');
}
try {
    $failureService->createShift(new Shift());
    throw new RuntimeException('Write failure was hidden');
} catch (RuntimeException $error) {
    check($error === $failureMapper->failure, 'Service propagates the original write failure');
}
try {
    $failureService->listForUser('');
    throw new RuntimeException('Empty user ID was accepted');
} catch (InvalidArgumentException $error) {
    check(true, 'Empty user ID is rejected explicitly');
}

$service = new ShiftService(new ShiftMapper($db));
$userId = 'timesheets-test-' . bin2hex(random_bytes(12));
$db->beginTransaction();
try {
    check($service->listForUser($userId) === [], 'A user without shifts has an empty list');
    $closed = new Shift();
    $closed->setUserId($userId);
    $closed->setShiftDate('2026-11-01');
    $closed->setClockIn('2026-11-01 08:00:00');
    $closed->setClockOut('2026-11-01 10:05:00');
    $closed->setDurationMinutes(125);
    $closed->setStatus('closed');
    check($service->createShift($closed) === $closed, 'Insert returns the saved Shift entity');
    check(is_int($closed->getId()) && $closed->getId() > 0, 'Insert assigns an integer ID');

    $open = new Shift();
    $open->setUserId($userId);
    $open->setClockIn('2026-11-02 08:00:00');
    $open->setStatus('open');
    $service->createShift($open);

    $shifts = $service->listForUser($userId);
    check(count($shifts) === 2, 'Mapper retrieves both saved shifts');
    check($shifts[0]->getId() === $open->getId(), 'Mapper sorts newest clock-in first');
    check($shifts[0]->getClockOut() === null, 'Open shift retains a null clock-out');
    check($shifts[0]->getDurationMinutes() === 0, 'Default duration is hydrated as an integer');
    check($shifts[1]->getDurationMinutes() === 125, 'Saved duration is hydrated as an integer');
    check($shifts[1]->getShiftDate() === '2026-11-01', 'Shift date survives the database round trip');
    check($shifts[1]->getClockOut() === '2026-11-01 10:05:00', 'Clock-out survives the database round trip');
    check($service->listForUser($userId . '-other') === [], 'Mapper isolates shifts by user');
} finally {
    $db->rollBack();
}
check($service->listForUser($userId) === [], 'Regression test rows are rolled back');

echo "Persistence checks passed.\n";
