<?php

declare(strict_types=1);

use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Service\AccessService;
use OCA\Timesheets\Service\ClockTransaction;
use OCA\Timesheets\Service\JobClockService;
use OCA\Timesheets\Service\ShiftClockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;

if (PHP_SAPI === 'cli' && ($argv[2] ?? '') === 'worker') {
    define('OC_CONSOLE', 1);
    require $argv[1] . '/lib/base.php';
    set_exception_handler(static function (Throwable $error): void {
        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage());
        exit(1);
    });
    \OC_App::loadApps();
    try {
        if ($argv[4] === 'job') {
            Server::get(JobClockService::class)->clockIn($argv[3], 'race');
        } else {
            Server::get(ShiftClockService::class)->clockOut($argv[3], (int) $argv[5]);
        }
        echo '200';
    } catch (DomainException $error) {
        echo $error->getCode();
    }
    exit(0);
}

require __DIR__ . '/persistence.php';
$migration = new \OCA\Timesheets\Migration\Version000112Date20261102210000();
$migration->changeSchema($output, $schemaClosure, []);
$jobTable = $schema->getWrappedSchema()->getTable($connection->getPrefix() . 'timesheets_jobs');
check($jobTable->getIndex('ts_jobs_open_user')->isUnique()
    && !$jobTable->getColumn('open_user_id')->getNotnull(), 'Job migration permits many closed rows but only one open slot per user');
check($jobTable->hasColumn('duration_seconds') && $jobTable->hasColumn('time_zone')
    && $jobTable->getColumn('job_reference')->getLength() === 255, 'Job migration retains precise duration, timezone, and bounded references');
$query = $db->getQueryBuilder();
$query->select('version')->from('migrations')
    ->where($query->expr()->eq('app', $query->createNamedParameter('timesheets')))
    ->andWhere($query->expr()->eq('version', $query->createNamedParameter('000112Date20261102210000')));
$result = $query->executeQuery();
check($result->fetchOne() !== false, 'Nextcloud recorded the job migration');
$result->closeCursor();
$config = Server::get(IConfig::class);
$previous = [];
foreach (['shift_tracking', 'job_tracking', 'job_require_shift', 'shift_end_job_policy', 'access_policy'] as $key) {
    $previous[$key] = $config->getAppValue('timesheets', $key, '');
}
$time = new class implements ITimeFactory {
    public int $timestamp = 0;
    public function getTime(): int { return $this->timestamp; }
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . $this->timestamp); }
    public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime { return new DateTime($time, $timezone); }
    public function withTimeZone(DateTimeZone $timezone): static { return $this; }
    public function getTimeZone(?string $timezone = null): DateTimeZone { return new DateTimeZone($timezone ?? 'UTC'); }
};
$id = 'timesheets-job-' . bin2hex(random_bytes(8));
$user = Server::get(IUserManager::class)->createUser($id, bin2hex(random_bytes(24)));
check($user !== false, 'Job test account created');
$jobs = new JobMapper($db);
$shifts = new ShiftMapper($db);
$access = Server::get(AccessService::class);
$transaction = new ClockTransaction($db);
$shiftClock = new ShiftClockService($shifts, $config, $time, $access, $transaction, $jobs);
$jobClock = new JobClockService($jobs, $shifts, $shiftClock, $access, $config, $time, $transaction);
function rejects(callable $action, int $code, string $message): void {
    try {
        $action();
        throw new LogicException('Expected rejected action: ' . $message);
    } catch (DomainException $error) {
        check($error->getCode() === $code, $message);
    }
}
try {
    $config->setAppValue('timesheets', 'access_policy', '{"restricted":false,"grants":[]}');
    $config->setAppValue('timesheets', 'job_tracking', '0');
    $time->timestamp = strtotime('2026-11-02 07:55:00 UTC');
    rejects(fn () => $jobClock->clockIn($id, 'SO-1'), 403, 'Disabled job tracking rejects new jobs');
    $config->setAppValue('timesheets', 'job_tracking', '1');
    $config->setAppValue('timesheets', 'shift_tracking', '0');
    $config->setAppValue('timesheets', 'job_require_shift', '1');
    $config->setUserValue($id, 'core', 'timezone', 'America/Los_Angeles');
    foreach (['', " \t ", str_repeat('x', 256), "SO\n1"] as $invalid) {
        rejects(fn () => $jobClock->clockIn($id, $invalid), 400, 'Invalid job reference is rejected');
    }
    $job = $jobClock->clockIn($id, ' SO-1 <sample> ');
    check($job->getJobReference() === 'SO-1 <sample>' && $job->getJobDate() === '2026-11-01',
        'Job-only mode trims reference and saves local start date');
    check($job->getClockIn() === '2026-11-02 07:55:00' && $job->getTimeZone() === 'America/Los_Angeles',
        'Job stores UTC timestamp and original timezone');
    $session = Server::get(\OCP\IUserSession::class);
    $session->setUser($user);
    $page = new \OCA\Timesheets\Controller\PageController('timesheets', Server::get(\OCP\IRequest::class),
        new \OCA\Timesheets\Service\ShiftService($shifts), $session, $shiftClock,
        Server::get(\OCP\IURLGenerator::class), $access, $jobClock, $jobs,
        new \OCA\Timesheets\Service\ShopTimeService($shiftClock));
    $access->save('{"restricted":true,"grants":[]}');
    $params = $page->index()->getParams();
    check($params['entries'] === [] && $params['activeJob']['id'] === $job->getId() && !$params['canStartJob'],
        'Revoked job-only user retains dashboard history and active job without any shifts');
    $config->setAppValue('timesheets', 'access_policy', '{"restricted":false,"grants":[]}');
    rejects(fn () => $jobClock->clockIn($id, 'SO-2'), 409, 'One active job per user');
    rejects(fn () => $jobClock->clockOut($id, 0), 400, 'Job clock-out requires a valid ID');
    rejects(fn () => $jobClock->clockOut($id . '-other', $job->getId()), 409, 'Cross-user job clock-out is rejected');
    $config->setUserValue($id, 'core', 'timezone', 'Asia/Tokyo');
    check(str_contains($jobClock->current($id)['startedAt'], '2026-11-01 23:55:00 PST'), 'Active job keeps original timezone');
    $time->timestamp--;
    try {
        $jobClock->clockOut($id, $job->getId());
        throw new LogicException('Backward clock was accepted');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'server clock'), 'Backward server clock does not change job');
    }
    $time->timestamp = strtotime('2026-11-02 08:05:59 UTC');
    $config->setAppValue('timesheets', 'job_tracking', '0');
    $jobClock->clockOut($id, $job->getId());
    $closed = $jobs->findByUser($id)[0];
    check($closed->getDurationSeconds() === 659 && $closed->getJobDate() === '2026-11-01'
        && $closed->getOpenUserId() === null, 'Job can finish after disabling; exact cross-midnight duration retained');
    check($page->index()->getParams()['jobEntries'][0]['total'] === '0h 10m'
        && str_contains($page->index()->getParams()['jobEntries'][0]['clockIn'], '2026-11-02 16:55:00 JST'),
        'Completed job history uses current timezone and whole minutes');
    rejects(fn () => $jobClock->clockOut($id, $job->getId()), 409, 'Repeated job clock-out is rejected');
    $config->setAppValue('timesheets', 'job_tracking', '1');
    $config->setAppValue('timesheets', 'shift_tracking', '1');
    $config->setAppValue('timesheets', 'shift_end_job_policy', 'block');
    rejects(fn () => $jobClock->clockIn($id, 'SO-2'), 409, 'Combined mode can require an active shift');
    $shift = $shiftClock->clockIn($id);
    $next = $jobClock->clockIn($id, 'SO-2');
    rejects(fn () => $jobClock->clockOut($id, $job->getId()), 409, 'Stale job clock-out cannot close a newer interval');
    rejects(fn () => $shiftClock->clockOut($id, $shift->getId()), 409, 'Default policy blocks shift clock-out with an active job');
    check($shiftClock->current($id)['id'] === $shift->getId(), 'Blocked shift clock-out leaves both intervals active');
    $config->setAppValue('timesheets', 'shift_end_job_policy', 'allow');
    $time->timestamp += 60;
    $shiftClock->clockOut($id, $shift->getId());
    check($shiftClock->current($id) === null && $jobClock->current($id)['id'] === $next->getId(),
        'Allow policy closes only the shift and leaves the job running');
    $time->timestamp += 120;
    $access->save(json_encode(['restricted' => true, 'grants' => []], JSON_THROW_ON_ERROR));
    rejects(fn () => $jobClock->clockIn($id, 'SO-3'), 403, 'Revoked users cannot start new jobs');
    $jobClock->clockOut($id, $next->getId());
    check($jobs->findByUser($id)[0]->getDurationSeconds() === 180, 'Revoked users can end a job including time outside the shift');
    $access->save(json_encode(['restricted' => true, 'grants' => [
        ['type' => 'user', 'id' => $id, 'shift' => false, 'job' => true],
    ]], JSON_THROW_ON_ERROR));
    $independent = $jobClock->clockIn($id, 'SO-job-only');
    check(!$jobClock->requiresShift($id), 'Job-only permission works independently even with both global modes enabled');
    $jobClock->clockOut($id, $independent->getId());
    $access->save(json_encode(['restricted' => true, 'grants' => [
        ['type' => 'user', 'id' => $id, 'shift' => true, 'job' => false],
    ]], JSON_THROW_ON_ERROR));
    rejects(fn () => $jobClock->clockIn($id, 'SO-denied'), 403, 'Shift-only permission does not grant job tracking');
    $config->setAppValue('timesheets', 'access_policy', '{"restricted":false,"grants":[]}');
    $config->setAppValue('timesheets', 'job_require_shift', '0');
    $config->setUserValue($id, 'core', 'timezone', 'America/Los_Angeles');
    $time->timestamp = strtotime('2026-11-01 08:30:00 UTC');
    $dst = $jobClock->clockIn($id, 'SO-DST');
    $time->timestamp += 7200;
    $jobClock->clockOut($id, $dst->getId());
    $found = array_values(array_filter($jobs->findByUser($id), static fn ($row): bool => $row->getId() === $dst->getId()))[0];
    check($found->getDurationSeconds() === 7200, 'Job elapsed time spans daylight-saving changes correctly');
    check(!$jobs->closeJob($dst, '2026-11-01 12:30:00', 14400), 'Conditional close cannot overwrite a completed job');

    $config->setAppValue('timesheets', 'job_require_shift', '1');
    $config->setAppValue('timesheets', 'shift_end_job_policy', 'block');
    $time->timestamp = time() - 60;
    $raceShift = $shiftClock->clockIn($id);
    $processes = [];
    try {
        foreach (['job', 'shift'] as $action) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, $nextcloudRoot, 'worker', $id, $action, (string) $raceShift->getId()],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start concurrent regression worker');
            }
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $result = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            check(proc_close($process) === 0 && $error === '', 'Concurrent clock worker completes without database errors');
            $results[] = $result;
        }
        $processes = [];
        sort($results);
        check($results === ['200', '409'], 'Concurrent job start and shift end cannot both bypass the interaction policy');
        $openJob = $jobs->findOpenForUser($id);
        check(($openJob === null) !== ($shifts->findOpenForUser($id) !== null),
            'Concurrent transitions leave a consistent active-shift/job state');
    } finally {
        foreach ($processes as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
} finally {
    foreach ($jobs->findByUser($id) as $entry) { $jobs->delete($entry); }
    foreach ($shifts->findByUser($id) as $entry) { $shifts->delete($entry); }
    foreach ([$id, $id . '-other'] as $lockId) {
        $query = $db->getQueryBuilder();
        $query->delete('timesheets_clock_locks')->where($query->expr()->eq('user_id', $query->createNamedParameter($lockId)));
        $query->executeStatement();
    }
    check($user->delete(), 'Job test account removed');
    Server::get(IAppConfig::class)->clearCache();
    foreach ($previous as $key => $value) {
        if ($value === '') { $config->deleteAppValue('timesheets', $key); }
        else { $config->setAppValue('timesheets', $key, $value); }
    }
}
echo "Job clock checks passed; fixtures removed and settings restored.\n";
