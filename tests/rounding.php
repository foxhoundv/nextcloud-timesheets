<?php

declare(strict_types=1);

use OCA\Timesheets\Controller\PageController;
use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Service\AccessService;
use OCA\Timesheets\Service\ClockTransaction;
use OCA\Timesheets\Service\JobClockService;
use OCA\Timesheets\Service\RoundingService;
use OCA\Timesheets\Service\ShiftClockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;

require __DIR__ . '/persistence.php';
$clockMigration = new \OCA\Timesheets\Migration\Version000106Date20261102190000($db);
$clockMigration->changeSchema($output, $schemaClosure, []);
$jobMigration = new \OCA\Timesheets\Migration\Version000112Date20261102210000();
$jobMigration->changeSchema($output, $schemaClosure, []);
$roundingMigration = new \OCA\Timesheets\Migration\Version000115Date20261103100000();
$roundingMigration->changeSchema($output, $schemaClosure, []);
foreach (['timesheets_shifts', 'timesheets_jobs'] as $name) {
    $table = $schema->getWrappedSchema()->getTable($connection->getPrefix() . $name);
    check(!$table->getColumn('rounding_interval')->getNotnull()
        && !$table->getColumn('rounded_seconds')->getNotnull()
        && $table->getColumn('rounded_seconds')->getDefault() === null,
        'Additive migration leaves existing ' . $name . ' snapshots null without rewriting history');
}
$query = $db->getQueryBuilder();
$query->select('version')->from('migrations')
    ->where($query->expr()->eq('app', $query->createNamedParameter('timesheets')))
    ->andWhere($query->expr()->eq('version', $query->createNamedParameter('000115Date20261103100000')));
$result = $query->executeQuery();
check($result->fetchOne() !== false, 'Nextcloud recorded the rounding migration');
$result->closeCursor();
$rounding = Server::get(RoundingService::class);
$config = Server::get(IConfig::class);
$keys = ['shift_tracking', 'job_tracking', 'access_policy', 'job_require_shift', 'shift_end_job_policy',
    'shift_rounding_enabled', 'job_rounding_enabled', 'shift_rounding', 'job_rounding'];
$previous = [];
$existing = $config->getAppKeys('timesheets');
foreach ($keys as $key) {
    $previous[$key] = in_array($key, $existing, true) ? $config->getAppValue('timesheets', $key) : null;
}
$time = new class implements ITimeFactory {
    public int $timestamp = 0;
    public function getTime(): int { return $this->timestamp; }
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . $this->timestamp); }
    public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime { return new DateTime($time, $timezone); }
    public function withTimeZone(DateTimeZone $timezone): static { return $this; }
    public function getTimeZone(?string $timezone = null): DateTimeZone { return new DateTimeZone($timezone ?? 'UTC'); }
};
$id = 'timesheets-round-' . bin2hex(random_bytes(8));
$user = Server::get(IUserManager::class)->createUser($id, bin2hex(random_bytes(24)));
check($user !== false, 'Rounding test account created');
$shifts = new ShiftMapper($db);
$jobs = new JobMapper($db);
$access = Server::get(AccessService::class);
$transaction = new ClockTransaction($db);
$shiftClock = new ShiftClockService($shifts, $config, $time, $access, $transaction, $jobs, $rounding);
$jobClock = new JobClockService($jobs, $shifts, $shiftClock, $access, $config, $time, $transaction, $rounding);
$session = Server::get(\OCP\IUserSession::class);
$oldUser = $session->getUser();
try {
    foreach (['shift', 'job'] as $type) {
        $config->deleteAppValue('timesheets', $type . '_rounding_enabled');
        check($rounding->capture($type, 449) === ['interval' => 0, 'seconds' => 449],
            'Absent enable preference leaves ' . $type . ' rounding off');
        $config->setAppValue('timesheets', $type . '_rounding_enabled', '1');
        foreach ([5, 10, 15, 30] as $minutes) {
            $config->setAppValue('timesheets', $type . '_rounding', (string) $minutes);
            $step = $minutes * 60;
            foreach ([0 => 0, intdiv($step, 2) - 1 => 0, intdiv($step, 2) => $step,
                intdiv($step, 2) + 1 => $step, $step => $step, $step + intdiv($step, 2) => $step * 2,
                86400 + intdiv($step, 2) => 86400 + $step] as $seconds => $expected) {
                check($rounding->capture($type, $seconds) === ['interval' => $minutes, 'seconds' => $expected],
                    $type . ' nearest ' . $minutes . ' minutes: ' . $seconds . ' seconds -> ' . $expected);
            }
            for ($seconds = 0; $seconds <= $step * 3; $seconds++) {
                $lower = intdiv($seconds, $step) * $step;
                $upper = $lower + $step;
                $expected = abs($seconds - $lower) < abs($upper - $seconds) ? $lower : $upper;
                if ($rounding->capture($type, $seconds)['seconds'] !== $expected) {
                    throw new RuntimeException('Nearest-interval distance comparison failed.');
                }
            }
            check(true, $type . ' ' . $minutes . '-minute rounding matches nearest-distance oracle for every second in three intervals');
        }
    }
    foreach ([['shift', -1], ['unsupported', 1]] as [$type, $seconds]) {
        try {
            $rounding->capture($type, $seconds);
            throw new LogicException('Invalid rounding input accepted');
        } catch (InvalidArgumentException $error) {
            check(true, 'Invalid rounding input rejected explicitly');
        }
    }
    $config->setAppValue('timesheets', 'access_policy', '{"restricted":false,"grants":[]}');
    $config->setAppValue('timesheets', 'shift_tracking', '1');
    $config->setAppValue('timesheets', 'job_tracking', '1');
    $config->setAppValue('timesheets', 'job_require_shift', '0');
    $config->setAppValue('timesheets', 'shift_end_job_policy', 'block');
    $config->setAppValue('timesheets', 'shift_rounding_enabled', '0');
    $config->setAppValue('timesheets', 'job_rounding_enabled', '0');
    $time->timestamp = strtotime('2026-11-03 08:00:00 UTC');
    $shift = $shiftClock->clockIn($id);
    $job = $jobClock->clockIn($id, 'SO-rounded');
    check($shifts->findByUser($id)[0]->getRoundedSeconds() === null
        && $jobs->findByUser($id)[0]->getRoundingInterval() === null,
        'Active intervals have no finalized rounding snapshot');
    $config->setAppValue('timesheets', 'shift_rounding_enabled', '1');
    $config->setAppValue('timesheets', 'shift_rounding', '15');
    $config->setAppValue('timesheets', 'job_rounding_enabled', '1');
    $config->setAppValue('timesheets', 'job_rounding', '5');
    $time->timestamp += 150;
    $jobClock->clockOut($id, $job->getId());
    $time->timestamp += 300;
    $shiftClock->clockOut($id, $shift->getId());
    $savedShift = $shifts->findByUser($id)[0];
    $savedJob = $jobs->findByUser($id)[0];
    check($savedShift->getDurationSeconds() === 450 && $savedShift->getDurationMinutes() === 7
        && $savedShift->getRoundedSeconds() === 900 && $savedShift->getRoundingInterval() === 15,
        'Shift saves exact actual duration and nearest half-up result atomically using clock-out preferences');
    check($savedJob->getDurationSeconds() === 150 && $savedJob->getRoundedSeconds() === 300
        && $savedJob->getRoundingInterval() === 5 && $savedJob->getClockOut() === '2026-11-03 08:02:30',
        'Job independently saves its own interval without changing clock timestamps');
    check(is_int($savedShift->getRoundedSeconds()) && is_int($savedJob->getRoundingInterval()),
        'Saved rounding fields hydrate as integers');
    $config->setAppValue('timesheets', 'shift_rounding', '30');
    $config->setAppValue('timesheets', 'job_rounding', '30');
    $config->setAppValue('timesheets', 'shift_rounding_enabled', '0');
    $config->setAppValue('timesheets', 'job_rounding_enabled', '0');
    $session->setUser($user);
    $page = Server::get(PageController::class);
    $params = $page->index()->getParams();
    check($params['entries'][0]['total'] === '0h 07m' && $params['entries'][0]['reported'] === '0h 15m'
        && $params['entries'][0]['roundingInterval'] === 15
        && $params['jobEntries'][0]['reported'] === '0h 05m',
        'Completed history retains saved rounding after settings change');
    check($params['breakdown']['jobSeconds'] === 150 && $params['breakdown']['shopSeconds'] === 300,
        'Job/shop breakdown uses actual overlaps, never rounded durations');
    $stats = array_column($params['stats'], 'value', 'label');
    check($stats['Recorded time'] === '0h 07m' && $stats['Reported shift time'] === '0h 15m'
        && $stats['Recorded job time'] === '0h 02m' && $stats['Reported job time'] === '0h 05m',
        'All-date actual and reported shift/job totals remain separate');
    $html = (new \OCP\AppFramework\Http\TemplateResponse('timesheets', 'main', $params, 'blank'))->render();
    check(str_contains($html, 'Nearest 15 minutes; halfway up') && str_contains($html, 'Nearest 5 minutes; halfway up')
        && str_contains($html, 'Reported duration'), 'Rendered history identifies each saved rounding rule and result');
    check(!$shifts->closeShift($savedShift, '2026-11-03 10:00:00', 7200, 30, 7200)
        && !$jobs->closeJob($savedJob, '2026-11-03 10:00:00', 7200, 30, 7200)
        && $shifts->findByUser($id)[0]->getRoundedSeconds() === 900
        && $jobs->findByUser($id)[0]->getRoundedSeconds() === 300,
        'Stale conditional closes cannot overwrite actual time or saved rounding');

    foreach ([[true, false], [false, true], [false, false], [true, true]] as [$shiftEnabled, $jobEnabled]) {
        $time->timestamp += 3600;
        $config->setAppValue('timesheets', 'shift_rounding_enabled', $shiftEnabled ? '1' : '0');
        $config->setAppValue('timesheets', 'job_rounding_enabled', $jobEnabled ? '1' : '0');
        $config->setAppValue('timesheets', 'shift_rounding', '10');
        $config->setAppValue('timesheets', 'job_rounding', '30');
        $shift = $shiftClock->clockIn($id);
        $job = $jobClock->clockIn($id, 'SO-independent');
        $time->timestamp += 301;
        $jobClock->clockOut($id, $job->getId());
        $shiftClock->clockOut($id, $shift->getId());
        check($shifts->findByUser($id)[0]->getRoundedSeconds() === ($shiftEnabled ? 600 : 301)
            && $jobs->findByUser($id)[0]->getRoundedSeconds() === ($jobEnabled ? 0 : 301),
            'Independent rounding switches cover shift=' . (int) $shiftEnabled . ', job=' . (int) $jobEnabled);
    }
    $time->timestamp += 3600;
    $config->setAppValue('timesheets', 'shift_rounding_enabled', '1');
    $shift = $shiftClock->clockIn($id);
    $time->timestamp += 449;
    $config->setAppValue('timesheets', 'shift_rounding', '12');
    try {
        $shiftClock->clockOut($id, $shift->getId());
        throw new LogicException('Corrupt persisted rounding preference accepted');
    } catch (RuntimeException $error) {
        check($shiftClock->current($id)['id'] === $shift->getId()
            && $shifts->findByUser($id)[0]->getRoundedSeconds() === null,
            'Invalid stored rounding preference surfaces error and leaves active interval unchanged');
    }
    $config->setAppValue('timesheets', 'shift_rounding', '15');
    $config->setAppValue('timesheets', 'shift_rounding_enabled', 'broken');
    try {
        $shiftClock->clockOut($id, $shift->getId());
        throw new LogicException('Invalid rounding switch accepted');
    } catch (RuntimeException $error) {
        check($shiftClock->current($id) !== null, 'Invalid saved enable switch explicitly rejects close without data loss');
    }
    $config->setAppValue('timesheets', 'shift_rounding_enabled', '0');
    $config->setAppValue('timesheets', 'shift_tracking', '0');
    $config->setAppValue('timesheets', 'access_policy', '{"restricted":true,"grants":[]}');
    $shiftClock->clockOut($id, $shift->getId());
    check($shifts->findByUser($id)[0]->getRoundedSeconds() === 449
        && $shifts->findByUser($id)[0]->getRoundingInterval() === 0,
        'Revoked/disabled tracking still closes active shift with rounding-off rule captured at close');
    $legacy = new Shift();
    $legacy->setUserId($id);
    $legacy->setShiftDate('2026-10-01');
    $legacy->setDurationMinutes(61);
    $legacy->setStatus('closed');
    $shifts->insert($legacy);
    $params = $page->index()->getParams();
    $legacyEntry = array_values(array_filter($params['entries'], static fn (array $entry): bool => $entry['date'] === '2026-10-01'))[0];
    check($legacyEntry['reported'] === '1h 01m' && $legacyEntry['roundingInterval'] === null,
        'Legacy duration without rounding snapshot is reported as actual, never retroactively rounded');
    $html = (new \OCP\AppFramework\Http\TemplateResponse('timesheets', 'main', $params, 'blank'))->render();
    check(str_contains($html, 'Not captured (actual)') && str_contains($html, 'Off (actual)'),
        'History clearly distinguishes legacy no-rule records from saved rounding-off results');
    $query = $db->getQueryBuilder();
    $query->update('timesheets_shifts')->set('rounding_interval', $query->createNamedParameter(15))
        ->where($query->expr()->eq('id', $query->createNamedParameter($legacy->getId())));
    $query->executeStatement();
    try {
        $page->index();
        throw new LogicException('Incomplete rounding snapshot silently accepted');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'invalid saved rounding'), 'Incomplete saved rounding snapshot surfaces an explicit error');
    }
} finally {
    $session->setUser($oldUser);
    foreach ($jobs->findByUser($id) as $job) {
        $jobs->delete($job);
    }
    foreach ($shifts->findByUser($id) as $shift) {
        $shifts->delete($shift);
    }
    $query = $db->getQueryBuilder();
    $query->delete('timesheets_clock_locks')->where($query->expr()->eq('user_id', $query->createNamedParameter($id)));
    $query->executeStatement();
    Server::get(IAppConfig::class)->clearCache();
    foreach ($previous as $key => $value) {
        if ($value === null) {
            $config->deleteAppValue('timesheets', $key);
        } else {
            $config->setAppValue('timesheets', $key, $value);
        }
        check($config->getAppValue('timesheets', $key, '__absent__') === ($value ?? '__absent__'), 'Restored original setting: ' . $key);
    }
    check($user->delete(), 'Temporary rounding account removed');
    check($jobs->findByUser($id) === [] && $shifts->findByUser($id) === [], 'Rounding fixtures removed');
}
echo "Rounding checks passed.\n";
