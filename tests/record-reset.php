<?php

declare(strict_types=1);

use OCA\Timesheets\Db\Job;
use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Service\AccessService;
use OCA\Timesheets\Service\ClockTransaction;
use OCA\Timesheets\Service\RecordResetService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\NullLogger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = $argv[1] ?? '';
if (!is_file($root . '/lib/base.php')) {
    fwrite(STDERR, "Usage: php tests/record-reset.php /path/to/nextcloud\n");
    exit(2);
}
define('OC_CONSOLE', 1);
require_once $root . '/lib/base.php';
\OC_App::loadApps();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
});
function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ' . $message . "\n";
}
function rejects(callable $action, int $code, string $message): void {
    try {
        $action();
        throw new LogicException('Expected rejection: ' . $message);
    } catch (DomainException $error) {
        check($error->getCode() === $code, $message);
    }
}
function fingerprint(IDBConnection $db, array $tables): string {
    $context = hash_init('sha256');
    foreach ($tables as $table => $order) {
        $result = $db->getQueryBuilder()->select('*')->from($table)->orderBy($order, 'ASC')->executeQuery();
        try {
            while ($row = $result->fetch()) {
                ksort($row);
                hash_update($context, json_encode($row, JSON_THROW_ON_ERROR));
            }
        } finally {
            $result->closeCursor();
        }
    }
    return hash_final($context);
}
$db = Server::get(IDBConnection::class);
if (!$db instanceof \OC\DB\ConnectionAdapter || $db->getInner()->getTransactionNestingLevel() !== 0) {
    throw new RuntimeException('Reset tests require a verified Nextcloud connection with no enclosing transaction.');
}
$transaction = new ClockTransaction($db);
$access = new class(Server::get(IConfig::class), Server::get(IUserManager::class),
    Server::get(IGroupManager::class), $transaction) extends AccessService {
    public array $ids = ['reset-admin-a', 'reset-admin-b'];
    public function administrators(): array { $ids = $this->ids; sort($ids); return $ids; }
    public function isTimesheetsAdmin(string $userId): bool { return in_array($userId, $this->ids, true); }
};
$time = new class implements ITimeFactory {
    public int $timestamp = 1793810000;
    public function getTime(): int { return $this->timestamp; }
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . $this->timestamp); }
    public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime { return new DateTime($time, $timezone); }
    public function withTimeZone(DateTimeZone $timezone): static { return $this; }
    public function getTimeZone(?string $timezone = null): DateTimeZone { return new DateTimeZone($timezone ?? 'UTC'); }
};
$service = new RecordResetService($db, $transaction, $access, $time, new NullLogger());
$shifts = new ShiftMapper($db);
$jobs = new JobMapper($db);
$tables = ['timesheets_shifts' => 'id', 'timesheets_jobs' => 'id', 'timesheets_reset' => 'id'];
$original = fingerprint($db, $tables);
$configBefore = fingerprint($db, ['appconfig' => 'configkey']);
$sentinel = tempnam(sys_get_temp_dir(), 'timesheets-reset-sentinel-');
if ($sentinel === false || file_put_contents($sentinel, 'Existing Excel file must not be modified') === false) {
    throw new RuntimeException('Unable to create a reset filesystem sentinel.');
}
$sentinelHash = hash_file('sha256', $sentinel);
// Every delete below is inside this outer transaction; service commits only its nested transaction.
$db->beginTransaction();
try {
    $transaction->exclusive(static function () use ($db): void {
        $qb = $db->getQueryBuilder();
        $qb->update('timesheets_reset')->set('request', $qb->createNamedParameter(null))->executeStatement();
    });
    check($db->getInner()->getTransactionNestingLevel() === 1, 'Service transactions cannot commit the rollback-isolated test scope');
    $shift = new Shift();
    $shift->setUserId('reset-test-user');
    $shift->setShiftDate('2026-11-04');
    $shift->setClockIn('2026-11-04 08:00:00');
    $shift->setStatus('open');
    $shift->setOpenUserId('reset-test-user');
    $shift->setTimeZone('UTC');
    $shift = $shifts->insert($shift);
    $closed = new Shift();
    $closed->setUserId('reset-test-user');
    $closed->setShiftDate('2026-11-03');
    $closed->setClockIn('2026-11-03 08:00:00');
    $closed->setClockOut('2026-11-03 09:00:00');
    $closed->setDurationSeconds(3600);
    $closed->setDurationMinutes(60);
    $closed->setStatus('closed');
    $closed = $shifts->insert($closed);
    $job = new Job();
    $job->setUserId('reset-test-user');
    $job->setOpenUserId('reset-test-user');
    $job->setJobReference('RESET-TEST');
    $job->setJobDate('2026-11-04');
    $job->setClockIn('2026-11-04 08:30:00');
    $job->setTimeZone('UTC');
    $jobs->insert($job);
    $initial = $service->status('reset-admin-a');
    check($initial['counts']['activeShifts'] >= 1 && $initial['counts']['activeJobs'] >= 1,
        'Deletion preview includes active shifts and Jobs');
    rejects(fn () => $service->status('ordinary-user'), 403, 'Ordinary users cannot inspect deletion approval state');
    rejects(fn () => $service->approve('ordinary-user', 'DELETE', $initial['snapshot'], ''), 403, 'Ordinary users cannot vote');
    foreach (['delete', ' DELETE', 'DELETE ', ''] as $confirmation) {
        rejects(fn () => $service->approve('reset-admin-a', $confirmation, $initial['snapshot'], ''), 400,
            'Only the exact case-sensitive DELETE confirmation is accepted');
    }
    $pending = $service->approve('reset-admin-a', 'DELETE', $initial['snapshot'], '');
    check(!$pending['deleted'] && $pending['approved'] === ['reset-admin-a']
        && $pending['approvedByYou'] && $pending['expiresAt'] === $time->timestamp + 86400,
        'First administrator approval creates a 24-hour request without deleting records');
    check($shifts->findOpenForUser('reset-test-user') !== null, 'Active timer survives partial approval');
    $duplicate = $service->approve('reset-admin-a', 'DELETE', $pending['snapshot'], $pending['requestId']);
    check($duplicate['approved'] === ['reset-admin-a'], 'Repeated approval by one administrator cannot satisfy the quorum');
    rejects(fn () => $service->cancel('ordinary-user', $pending['requestId']), 403, 'Ordinary users cannot cancel requests');
    $service->cancel('reset-admin-b', $pending['requestId']);
    check($service->status('reset-admin-a')['requestId'] === '', 'Any administrator can cancel all pending approvals');
    rejects(fn () => $service->approve('reset-admin-b', 'DELETE', $pending['snapshot'], $pending['requestId']), 409,
        'A canceled request cannot be replayed even when records are unchanged');

    $fresh = $service->status('reset-admin-a');
    $pending = $service->approve('reset-admin-a', 'DELETE', $fresh['snapshot'], '');
    $closed->setDurationSeconds(3601);
    $shifts->update($closed);
    rejects(fn () => $service->approve('reset-admin-b', 'DELETE', $pending['snapshot'], $pending['requestId']), 409,
        'Changed existing logs invalidate all approvals before the last vote');
    check($service->status('reset-admin-a')['approved'] === [], 'Invalidation is committed even when approval returns a conflict');
    $fresh = $service->status('reset-admin-a');
    $pending = $service->approve('reset-admin-a', 'DELETE', $fresh['snapshot'], '');
    $transaction->run('reset-test-user', fn () => $shifts->update($closed));
    check($service->status('reset-admin-a')['requestId'] === '',
        'Successful clock transactions immediately invalidate pending approvals, even if the saved snapshot is unchanged');
    $fresh = $service->status('reset-admin-a');
    $pending = $service->approve('reset-admin-a', 'DELETE', $fresh['snapshot'], '');
    $extra = new Shift();
    $extra->setUserId('reset-test-user');
    $extra->setShiftDate('2026-11-02');
    $extra->setStatus('closed');
    $extra->setDurationMinutes(5);
    $shifts->insert($extra);
    $changed = $service->status('reset-admin-b');
    check($changed['approved'] === [] && $changed['notice'] !== null, 'New logs reset approvals with an explicit status message');

    $fresh = $service->status('reset-admin-a');
    $pending = $service->approve('reset-admin-a', 'DELETE', $fresh['snapshot'], '');
    $access->ids[] = 'reset-admin-c';
    rejects(fn () => $service->approve('reset-admin-b', 'DELETE', $pending['snapshot'], $pending['requestId']), 409,
        'Added administrators invalidate earlier votes');
    $fresh = $service->status('reset-admin-a');
    $pending = $service->approve('reset-admin-a', 'DELETE', $fresh['snapshot'], '');
    $access->ids = ['reset-admin-a', 'reset-admin-b'];
    rejects(fn () => $service->approve('reset-admin-b', 'DELETE', $pending['snapshot'], $pending['requestId']), 409,
        'Removed administrators also invalidate earlier votes');
    $fresh = $service->status('reset-admin-a');
    $pending = $service->approve('reset-admin-a', 'DELETE', $fresh['snapshot'], '');
    $time->timestamp += 86400;
    rejects(fn () => $service->approve('reset-admin-b', 'DELETE', $pending['snapshot'], $pending['requestId']), 409,
        'Requests expire at exactly 24 hours and cannot be completed with stale votes');
    check($service->status('reset-admin-a')['requestId'] === '', 'Expiration clears persisted approval state');

    $fresh = $service->status('reset-admin-a');
    $pending = $service->approve('reset-admin-a', 'DELETE', $fresh['snapshot'], '');
    $beforeFailure = fingerprint($db, $tables);
    $failingLogger = new class extends NullLogger {
        public function warning(string|\Stringable $message, array $context = []): void {
            throw new RuntimeException('Simulated failure after database deletes, before commit.');
        }
    };
    $failingService = new RecordResetService($db, $transaction, $access, $time, $failingLogger);
    try {
        $failingService->approve('reset-admin-b', 'DELETE', $pending['snapshot'], $pending['requestId']);
        throw new LogicException('Expected a transaction failure after deletion.');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'Simulated failure')
            && fingerprint($db, $tables) === $beforeFailure,
            'A failure after both delete statements restores all records and previous approvals atomically');
    }
    $deleted = $service->approve('reset-admin-b', 'DELETE', $pending['snapshot'], $pending['requestId']);
    check($deleted['deleted'] && $service->status('reset-admin-a')['counts'] === [
        'shifts' => 0, 'jobs' => 0, 'activeShifts' => 0, 'activeJobs' => 0,
    ], 'Unanimous approval deletes every completed and active shift/Job database log');
    check($shifts->findOpenForUser('reset-test-user') === null && $jobs->findOpenForUser('reset-test-user') === null,
        'Deletion resets active timers rather than leaving orphan intervals');
    check($configBefore === fingerprint($db, ['appconfig' => 'configkey']), 'Settings and administrator assignments are untouched');
    check(hash_file('sha256', $sentinel) === $sentinelHash, 'Existing filesystem sentinel is not modified');
    rejects(fn () => $service->approve('reset-admin-a', 'DELETE', $service->status('reset-admin-a')['snapshot'], ''), 409,
        'Empty database cannot create a misleading successful deletion request');

    $extra = new Shift();
    $extra->setUserId('reset-test-user');
    $extra->setShiftDate('2026-11-04');
    $extra->setStatus('closed');
    $extra->setDurationMinutes(5);
    $shifts->insert($extra);
    $access->ids = ['reset-admin-a'];
    $fresh = $service->status('reset-admin-a');
    check($service->approve('reset-admin-a', 'DELETE', $fresh['snapshot'], '')['deleted'],
        'A sole administrator deletes immediately after typing DELETE and approving');
    check($db->getInner()->getTransactionNestingLevel() === 1, 'Even successful full deletion remains rollback-isolated');
} finally {
    $db->rollBack();
    unlink($sentinel);
}
check($db->getInner()->getTransactionNestingLevel() === 0 && fingerprint($db, $tables) === $original,
    'All original database logs, active timers and pending approval state restored byte-for-byte by test rollback');
echo "Record reset checks passed without persistently deleting any server time records.\n";
