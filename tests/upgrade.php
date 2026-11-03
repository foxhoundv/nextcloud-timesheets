<?php

declare(strict_types=1);

use OCA\Timesheets\Controller\PageController;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Service\ShiftClockService;
use OCA\Timesheets\Service\ShiftService;
use OCA\Timesheets\Settings\AdminSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$nextcloudRoot = $argv[1] ?? '';
$phase = $argv[2] ?? '';
$stateFile = $argv[3] ?? '';
if (!is_file($nextcloudRoot . '/lib/base.php')
    || !in_array($phase, ['prepare', 'verify', 'cleanup'], true) || $stateFile === '') {
    fwrite(STDERR, "Usage: php tests/upgrade.php /path/to/nextcloud prepare|verify|cleanup /private/state.json\n");
    exit(2);
}

define('OC_CONSOLE', 1);
require_once $nextcloudRoot . '/lib/base.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage()
        . "\nIf preparation started, run the cleanup phase with the same state file.\n");
    exit(1);
});
\OC_App::loadApps();

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ' . $message . "\n";
}

function snapshot(Shift $shift): array {
    return [
        'id' => $shift->getId(),
        'userId' => $shift->getUserId(),
        'shiftDate' => $shift->getShiftDate(),
        'clockIn' => $shift->getClockIn(),
        'clockOut' => $shift->getClockOut(),
        'durationMinutes' => $shift->getDurationMinutes(),
        'durationSeconds' => $shift->getDurationSeconds(),
        'status' => $shift->getStatus(),
        'openUserId' => $shift->getOpenUserId(),
        'timeZone' => $shift->getTimeZone(),
    ];
}

function jobSnapshot(\OCA\Timesheets\Db\Job $job): array {
    return [
        'id' => $job->getId(), 'reference' => $job->getJobReference(), 'date' => $job->getJobDate(),
        'clockIn' => $job->getClockIn(), 'clockOut' => $job->getClockOut(),
        'durationSeconds' => $job->getDurationSeconds(), 'timeZone' => $job->getTimeZone(),
        'openUserId' => $job->getOpenUserId(),
    ];
}

$config = Server::get(IConfig::class);
$users = Server::get(IUserManager::class);
$db = Server::get(IDBConnection::class);
$mapper = new ShiftMapper($db);
$access = Server::get(\OCA\Timesheets\Service\AccessService::class);
$clock = new ShiftClockService($mapper, $config, Server::get(ITimeFactory::class), $access,
    Server::get(\OCA\Timesheets\Service\ClockTransaction::class), Server::get(\OCA\Timesheets\Db\JobMapper::class),
    Server::get(\OCA\Timesheets\Service\RoundingService::class));
$preferences = [
    'shift_tracking' => '1',
    'job_tracking' => '1',
    'shift_rounding' => '5',
    'job_rounding' => '30',
    'shift_rounding_enabled' => '1',
    'job_rounding_enabled' => '0',
    'net_shift_threshold' => '487',
    'storage_owner' => '',
    'payroll_storage_root' => 'payroll-share/timesheets',
    'sales_order_storage_root' => 'sales-orders',
];

if ($phase === 'prepare') {
    $previous = [];
    $keys = $config->getAppKeys('timesheets');
    foreach ($preferences as $key => $value) {
        $previous[$key] = in_array($key, $keys, true) ? $config->getAppValue('timesheets', $key) : null;
    }
    $state = [
        'userId' => 'timesheets-upgrade-' . bin2hex(random_bytes(12)),
        'previous' => $previous,
        'version' => $config->getAppValue('timesheets', 'installed_version'),
        'previousAccess' => $config->getAppValue('timesheets', 'access_policy', ''),
        'previousCombined' => [
            'job_require_shift' => $config->getAppValue('timesheets', 'job_require_shift', ''),
            'shift_end_job_policy' => $config->getAppValue('timesheets', 'shift_end_job_policy', ''),
        ],
    ];
    $preferences['storage_owner'] = $state['userId'];
    $oldMask = umask(0077);
    try {
        $handle = fopen($stateFile, 'x');
    } finally {
        umask($oldMask);
    }
    if ($handle === false) {
        throw new RuntimeException('Cannot create a new state file; do not overwrite a pending upgrade test.');
    }
    try {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        check(fwrite($handle, $json) === strlen($json), 'Original settings saved before any test changes');
    } finally {
        fclose($handle);
    }
    $user = $users->createUser($state['userId'], bin2hex(random_bytes(24)));
    check($user !== false, 'Temporary upgrade account created');
    foreach ($preferences as $key => $value) {
        $config->setAppValue('timesheets', $key, $value);
    }
    $state['accessPolicy'] = json_encode(['restricted' => true, 'grants' => [
        ['type' => 'user', 'id' => $state['userId'], 'shift' => true, 'job' => true, 'admin' => true],
    ]], JSON_THROW_ON_ERROR);
    $access->save($state['accessPolicy']);
    $config->setUserValue($state['userId'], 'core', 'timezone', 'America/Los_Angeles');
    $history = new Shift();
    $history->setUserId($state['userId']);
    $history->setShiftDate('2026-10-01');
    $history->setClockIn('2026-10-01 15:00:00');
    $history->setClockOut('2026-10-01 17:05:37');
    $history->setDurationMinutes(125);
    $history->setDurationSeconds(7537);
    $history->setTimeZone('America/Los_Angeles');
    $history->setStatus('closed');
    $mapper->insert($history);
    $clock->clockIn($state['userId']);
    $jobClock = Server::get(\OCA\Timesheets\Service\JobClockService::class);
    $jobClock->clockIn($state['userId'], 'upgrade-history');
    $jobClock->clockOut($state['userId'], $jobClock->current($state['userId'])['id']);
    $jobClock->clockIn($state['userId'], 'upgrade-active');
    $state['jobs'] = array_map('jobSnapshot', Server::get(\OCA\Timesheets\Db\JobMapper::class)->findByUser($state['userId']));
    $state['activeJob'] = $jobClock->current($state['userId']);
    $config->setAppValue('timesheets', 'job_require_shift', '1');
    $config->setAppValue('timesheets', 'shift_end_job_policy', 'allow');
    $state['combined'] = ['job_require_shift' => '1', 'shift_end_job_policy' => 'allow'];
    $state['shifts'] = array_map('snapshot', $mapper->findByUser($state['userId']));
    $state['roundingSnapshots'] = [
        'shifts' => array_map(static fn ($entry): array => [$entry->getId(), $entry->getRoundingInterval(), $entry->getRoundedSeconds()],
            $mapper->findByUser($state['userId'])),
        'jobs' => array_map(static fn ($entry): array => [$entry->getId(), $entry->getRoundingInterval(), $entry->getRoundedSeconds()],
            Server::get(\OCA\Timesheets\Db\JobMapper::class)->findByUser($state['userId'])),
        'settings' => [
            'shift_rounding_enabled' => $config->getAppValue('timesheets', 'shift_rounding_enabled', '0'),
            'job_rounding_enabled' => $config->getAppValue('timesheets', 'job_rounding_enabled', '0'),
            'net_shift_threshold' => $config->getAppValue('timesheets', 'net_shift_threshold', '0'),
        ],
    ];
    $state['active'] = $clock->current($state['userId']);
    $json = json_encode($state, JSON_THROW_ON_ERROR);
    check(file_put_contents($stateFile, $json, LOCK_EX) === strlen($json),
        'History and active shift saved for verification in a new process after upgrade');
    echo "Upgrade fixtures prepared. Upgrade the app, then run verify; always run cleanup afterwards.\n";
    exit(0);
}

$state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($state) || !is_string($state['userId'] ?? null)
    || !preg_match('/^timesheets-upgrade-[a-f0-9]{24}$/D', $state['userId'])
    || !is_array($state['previous'] ?? null)
    || array_keys($state['previous']) !== array_keys($preferences)) {
    throw new RuntimeException('Invalid upgrade test state; refusing to change data.');
}
$preferences['storage_owner'] = $state['userId'];

if ($phase === 'cleanup') {
    $jobMapper = Server::get(\OCA\Timesheets\Db\JobMapper::class);
    foreach ($jobMapper->findByUser($state['userId']) as $job) {
        $jobMapper->delete($job);
    }
    $query = $db->getQueryBuilder();
    $query->delete('timesheets_shifts')
        ->where($query->expr()->eq('user_id', $query->createNamedParameter($state['userId'])));
    $query->executeStatement();
    $user = $users->get($state['userId']);
    check($user === null || $user->delete(), 'Temporary upgrade account removed');
    Server::get(IAppConfig::class)->clearCache();
    foreach ($state['previous'] as $key => $value) {
        if ($value === null) {
            $config->deleteAppValue('timesheets', $key);
        } else {
            $config->setAppValue('timesheets', $key, $value);
        }
    }
    if (($state['previousAccess'] ?? '') === '') {
        $config->deleteAppValue('timesheets', 'access_policy');
    } else {
        $config->setAppValue('timesheets', 'access_policy', $state['previousAccess']);
    }
    foreach ($state['previousCombined'] ?? [] as $key => $value) {
        if ($value === '') {
            $config->deleteAppValue('timesheets', $key);
        } else {
            $config->setAppValue('timesheets', $key, $value);
        }
        check($config->getAppValue('timesheets', $key, '') === $value, 'Original combined tracking preference restored: ' . $key);
    }
    foreach ($state['previous'] as $key => $value) {
        check($config->getAppValue('timesheets', $key, '__absent__') === ($value ?? '__absent__'),
            'Original administration preference restored: ' . $key);
    }
    check($mapper->findByUser($state['userId']) === [], 'Temporary upgrade shifts removed');
    $query = $db->getQueryBuilder();
    $query->delete('timesheets_clock_locks')
        ->where($query->expr()->eq('user_id', $query->createNamedParameter($state['userId'])));
    $query->executeStatement();
    check(unlink($stateFile), 'Upgrade test state removed');
    echo "Upgrade cleanup passed.\n";
    exit(0);
}

check(version_compare($config->getAppValue('timesheets', 'installed_version'), $state['version'], '>'),
    'Verification runs after an actual app version upgrade');
if (isset($state['accessPolicy'])) {
    check($config->getAppValue('timesheets', 'access_policy') === $state['accessPolicy'],
        'Restricted access mode and tracking assignments survive upgrade');
    check($access->isTimesheetsAdmin($state['userId']),
        'Delegated administrator assignment survives upgrade');
}
foreach ($preferences as $key => $value) {
    check($config->getAppValue('timesheets', $key) === $value, 'Non-default setting survives upgrade: ' . $key);
}
$settings = new AdminSettings($config, Server::get(IURLGenerator::class), $access,
    Server::get(\OCA\Timesheets\Service\StorageService::class));
check($settings->getForm()->getParams()['settings'] === [
    'shift_tracking' => true,
    'job_tracking' => true,
    'shift_rounding' => '5',
    'job_rounding' => '30',
    'net_shift_threshold' => 487,
], 'Administration page still receives every non-default setting');
check($settings->getForm()->getParams()['shiftRoundingEnabled']
    && !$settings->getForm()->getParams()['jobRoundingEnabled'],
    'Independent rounding switches retain their explicit non-default states through upgrade');
check($settings->getForm()->getParams()['storage'] === [
    'owner' => $state['userId'],
    'payrollRoot' => 'payroll-share/timesheets',
    'salesOrderRoot' => 'sales-orders',
], 'Configured storage roots survive upgrade');
check(array_map('snapshot', $mapper->findByUser($state['userId'])) === $state['shifts'],
    'Historical and active shift fields are unchanged by upgrade');
foreach ([
    'shifts' => $mapper->findByUser($state['userId']),
    'jobs' => Server::get(\OCA\Timesheets\Db\JobMapper::class)->findByUser($state['userId']),
] as $kind => $intervals) {
    $snapshots = array_map(static fn ($entry): array => [$entry->getId(), $entry->getRoundingInterval(), $entry->getRoundedSeconds()], $intervals);
    if (isset($state['roundingSnapshots'])) {
        check($snapshots === $state['roundingSnapshots'][$kind], 'Saved rounding snapshots survive upgrade: ' . $kind);
    } else {
        check(count(array_filter($snapshots, static fn (array $row): bool => $row[1] !== null || $row[2] !== null)) === 0,
            'Pre-rounding historical and active intervals retain null snapshots: ' . $kind);
    }
}
foreach ($state['roundingSnapshots']['settings'] ?? ['shift_rounding_enabled' => '0', 'job_rounding_enabled' => '0'] as $key => $value) {
    check($config->getAppValue('timesheets', $key, '0') === $value, 'Rounding enable preference preserved without implicit activation: ' . $key);
}
$active = $clock->current($state['userId']);
check($active !== null && $active['id'] === $state['active']['id']
    && $active['clockIn'] === $state['active']['clockIn']
    && $active['startedAt'] === $state['active']['startedAt'], 'The same clocked-in session remains active');
check($active['elapsedSeconds'] > $state['active']['elapsedSeconds']
    && abs($active['elapsedSeconds'] - (time() - strtotime($active['clockIn']))) <= 2,
    'Elapsed time includes time spent upgrading instead of restarting at zero');
$user = $users->get($state['userId']);
check($user !== null, 'Account survives upgrade');
$session = Server::get(IUserSession::class);
$session->setUser($user);
$page = new PageController('timesheets', Server::get(IRequest::class), new ShiftService($mapper),
    $session, $clock, Server::get(IURLGenerator::class), $access,
    Server::get(\OCA\Timesheets\Service\JobClockService::class), Server::get(\OCA\Timesheets\Db\JobMapper::class),
    Server::get(\OCA\Timesheets\Service\ShopTimeService::class));
$params = $page->index()->getParams();
check($params['activeShift']['id'] === $active['id'] && $params['trackingEnabled'],
    'Dashboard still receives the active clock-out control and elapsed time');
check(count($params['entries']) === 2 && $params['entries'][1]['total'] === '2h 05m'
    && $params['entries'][1]['clockIn'] === '2026-10-01 08:00:00 PDT'
    && $params['entries'][1]['clockOut'] === '2026-10-01 10:05:37 PDT',
    'Historical time log remains visible with original timestamps and duration');
check($params['entries'][1]['jobTime'] === '0h 00m'
    && $params['entries'][1]['shopTime'] === '2h 05m'
    && $params['breakdown']['shopSeconds'] === 7537
    && count($params['breakdown']['shifts']) === 1,
    'Completed historical shift gains an exact read-only breakdown while active shift stays excluded');
if (isset($state['jobs'])) {
    $jobMapper = Server::get(\OCA\Timesheets\Db\JobMapper::class);
    check(array_map('jobSnapshot', $jobMapper->findByUser($state['userId'])) === $state['jobs'],
        'Historical and active job intervals survive upgrade unchanged');
    foreach ($state['combined'] as $key => $value) {
        check($config->getAppValue('timesheets', $key) === $value, 'Combined tracking preference survives upgrade: ' . $key);
    }
    check($params['activeJob'] !== null && count($params['jobEntries']) === 2,
        'Dashboard retains active job and job history after upgrade');
    check($params['activeJob']['id'] === $state['activeJob']['id']
        && $params['activeJob']['elapsedSeconds'] > $state['activeJob']['elapsedSeconds'],
        'Active job keeps counting through upgrade and restart');
    Server::get(\OCA\Timesheets\Service\JobClockService::class)->clockOut($state['userId'], $params['activeJob']['id']);
    $job = $jobMapper->findByUser($state['userId'])[0];
    check($job->getDurationSeconds() === strtotime($job->getClockOut() . ' UTC') - strtotime($job->getClockIn() . ' UTC'),
        'Job clock-out after upgrade retains the full elapsed interval');
}
$clock->clockOut($state['userId'], $active['id']);
$closed = $mapper->findByUser($state['userId'])[0];
check($closed->getStatus() === 'closed' && $clock->current($state['userId']) === null,
    'A session started before upgrade can still be clocked out');
check($closed->getDurationSeconds() === strtotime($closed->getClockOut() . ' UTC')
    - strtotime($closed->getClockIn() . ' UTC'), 'Clock-out saves the entire interval including the upgrade');
echo "Upgrade persistence checks passed. Run cleanup to restore original settings and remove fixtures.\n";
