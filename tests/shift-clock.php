<?php

declare(strict_types=1);

use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Migration\Version000106Date20261102190000;
use OCA\Timesheets\Service\ShiftClockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;

require __DIR__ . '/persistence.php';

$clock = new class implements ITimeFactory {
    public int $timestamp = 0;
    public function getTime(): int {
        return $this->timestamp;
    }
    public function now(): DateTimeImmutable {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
    public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime {
        return new DateTime($time, $timezone);
    }
    public function withTimeZone(DateTimeZone $timezone): static {
        return $this;
    }
    public function getTimeZone(?string $timezone = null): DateTimeZone {
        return new DateTimeZone($timezone ?? 'UTC');
    }
};
$config = Server::get(IConfig::class);
$previousAccess = $config->getAppValue('timesheets', 'access_policy', '');
$previousTracking = in_array('shift_tracking', $config->getAppKeys('timesheets'), true)
    ? $config->getAppValue('timesheets', 'shift_tracking') : null;
$testUserId = 'timesheets-clock-' . bin2hex(random_bytes(8));
$user = Server::get(IUserManager::class)->createUser($testUserId, bin2hex(random_bytes(24)));
check($user !== false, 'Clock test account created');
$mapper = new ShiftMapper($db);
$service = new ShiftClockService($mapper, $config, $clock, Server::get(\OCA\Timesheets\Service\AccessService::class),
    Server::get(\OCA\Timesheets\Service\ClockTransaction::class), Server::get(\OCA\Timesheets\Db\JobMapper::class));
$saved = [];

function expectConflict(callable $action, int $code, string $message): void {
    try {
        $action();
    } catch (DomainException $error) {
        check($error->getCode() === $code, $message);
        return;
    }
    throw new RuntimeException($message . ': expected a rejected action');
}

try {
    $config->setAppValue('timesheets', 'access_policy', '{"restricted":false,"grants":[]}');
    $config->setAppValue('timesheets', 'shift_tracking', '0');
    expectConflict(fn () => $service->clockIn($testUserId), 403, 'Disabled tracking rejects clock-in');
    $config->setAppValue('timesheets', 'shift_tracking', '1');
    $config->setUserValue($testUserId, 'core', 'timezone', 'America/Los_Angeles');
    $clock->timestamp = strtotime('2026-11-02 07:55:00 UTC');
    $shift = $service->clockIn($testUserId);
    $saved[] = $shift;
    check($shift->getShiftDate() === '2026-11-01', 'Shift date uses the clock-in timezone, not UTC date');
    check($shift->getClockIn() === '2026-11-02 07:55:00', 'Clock-in timestamp is stored as UTC');
    check($shift->getTimeZone() === 'America/Los_Angeles', 'Clock-in timezone is retained');
    expectConflict(fn () => $service->clockIn($testUserId), 409, 'Database uniqueness prevents a second open shift');
    expectConflict(fn () => $service->clockOut($testUserId, 0), 400, 'Clock-out rejects invalid IDs');
    expectConflict(fn () => $service->clockOut($testUserId . '-other', $shift->getId()), 409, 'Another account cannot close the shift');
    $config->setUserValue($testUserId, 'core', 'timezone', 'Asia/Tokyo');
    check(str_contains($service->current($testUserId)['startedAt'], '2026-11-01 23:55:00 PST'),
        'An open shift keeps its clock-in timezone after a preference change');
    $clock->timestamp -= 1;
    try {
        $service->clockOut($testUserId, $shift->getId());
        throw new LogicException('Backward server clock was accepted');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'server clock'), 'Backward server clock is rejected without changing the shift');
    }
    check($mapper->findOpenForUser($testUserId) !== null, 'Failed clock-out leaves the shift open');
    $clock->timestamp = strtotime('2026-11-02 08:05:59 UTC');
    $config->setAppValue('timesheets', 'shift_tracking', '0');
    $service->clockOut($testUserId, $shift->getId());
    $closed = $mapper->findByUser($testUserId)[0];
    check($closed->getDurationSeconds() === 659 && $closed->getDurationMinutes() === 10,
        'Actual seconds are retained and displayed minutes truncate without rounding');
    check($closed->getShiftDate() === '2026-11-01', 'Cross-midnight shift stays attributed to its start date');
    check($closed->getClockOut() === '2026-11-02 08:05:59', 'Clock-out is stored as UTC');
    check($closed->getStatus() === 'closed' && $closed->getOpenUserId() === null, 'Clock-out closes the shift and releases its unique open slot');
    expectConflict(fn () => $service->clockOut($testUserId, $shift->getId()), 409, 'Repeated clock-out is rejected');
    $config->setAppValue('timesheets', 'shift_tracking', '1');
    $config->setUserValue($testUserId, 'core', 'timezone', 'America/Los_Angeles');
    $clock->timestamp = strtotime('2026-11-01 08:30:00 UTC');
    $next = $service->clockIn($testUserId);
    $saved[] = $next;
    expectConflict(fn () => $service->clockOut($testUserId, $shift->getId()), 409, 'A stale clock-out cannot close a new shift');
    check($mapper->findOpenForUser($testUserId)->getId() === $next->getId(), 'New shift survives a stale request');
    $clock->timestamp = strtotime('2026-11-01 10:30:00 UTC');
    $service->clockOut($testUserId, $next->getId());
    $byId = [];
    foreach ($mapper->findByUser($testUserId) as $entry) {
        $byId[$entry->getId()] = $entry;
    }
    check($byId[$next->getId()]->getDurationSeconds() === 7200, 'Daylight-saving clock change retains the full actual duration');
    check(!$mapper->closeShift($next, '2026-11-01 11:30:00', 10800),
        'Conditional update prevents a concurrent or repeated close from overwriting duration');
    check($service->current($testUserId) === null, 'No active shift remains after clock-out');

    $previousMinutes = array_sum(array_map(static fn (Shift $entry): int => $entry->getDurationMinutes(),
        $mapper->findByUser($testUserId)));
    $config->setUserValue($testUserId, 'core', 'timezone', 'UTC');
    $workPeriods = [];
    foreach ([
        ['08:00:00', '10:00:00'],
        ['10:15:00', '12:00:00'],
        ['12:45:00', '17:00:00'],
    ] as [$start, $end]) {
        $clock->timestamp = strtotime('2026-11-03 ' . $start . ' UTC');
        check($service->current($testUserId) === null, 'Time off between shifts does not create an active shift');
        $period = $service->clockIn($testUserId);
        $saved[] = $period;
        $workPeriods[] = $period->getId();
        check($service->current($testUserId)['elapsedSeconds'] === 0,
            'Returning to work starts a fresh timer without including the preceding gap');
        $clock->timestamp = strtotime('2026-11-03 ' . $end . ' UTC');
        $service->clockOut($testUserId, $period->getId());
    }
    $periods = array_values(array_filter($mapper->findByUser($testUserId),
        static fn (Shift $entry): bool => in_array($entry->getId(), $workPeriods, true)));
    check(count($periods) === 3 && count(array_unique($workPeriods)) === 3,
        'Work periods separated by breaks have distinct saved shifts');
    check(array_sum(array_map(static fn (Shift $entry): int => $entry->getDurationSeconds(), $periods)) === 28800,
        'Eight hours of work excludes both breaks from the nine-hour span, to the second');
    check(array_sum(array_map(static fn (Shift $entry): int => $entry->getDurationMinutes(), $periods)) === 480,
        'Completed work minutes exclude the 15-minute and 45-minute gaps');
    check(count(array_filter($periods, static fn (Shift $entry): bool =>
        $entry->getShiftDate() === '2026-11-03' && $entry->getStatus() === 'closed')) === 3,
        'Multiple work periods on the same date remain separate completed history records');
    $session = Server::get(\OCP\IUserSession::class);
    $session->setUser($user);
    $page = new \OCA\Timesheets\Controller\PageController('timesheets', Server::get(\OCP\IRequest::class),
        new \OCA\Timesheets\Service\ShiftService($mapper), $session, $service,
        Server::get(\OCP\IURLGenerator::class), Server::get(\OCA\Timesheets\Service\AccessService::class),
        Server::get(\OCA\Timesheets\Service\JobClockService::class), Server::get(\OCA\Timesheets\Db\JobMapper::class));
    $params = $page->index()->getParams();
    $totalMinutes = $previousMinutes + 480;
    check($params['stats'][0]['value'] === sprintf('%dh %02dm', intdiv($totalMinutes, 60), $totalMinutes % 60),
        'Dashboard recorded-time total sums work periods without adding break gaps');
    check(array_column(array_slice($params['entries'], 0, 3), 'total') === ['4h 15m', '1h 45m', '2h 00m']
        && $params['activeShift'] === null,
        'Dashboard shows each work period separately with no paused or active break session');

    $migration = new Version000106Date20261102190000($db);
    $migration->preSchemaChange($output, $schemaClosure, []);
    check(true, 'Clock migration accepts users without duplicate open shifts');
    foreach ([1, 2] as $number) {
        $legacy = new Shift();
        $legacy->setUserId($testUserId);
        $legacy->setStatus('open');
        $legacy->setClockIn('2026-10-01 08:00:00');
        $saved[] = $mapper->insert($legacy);
    }
    try {
        $migration->preSchemaChange($output, $schemaClosure, []);
        throw new LogicException('Duplicate legacy open shifts were accepted');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'multiple open shifts'),
            'Clock migration rejects duplicate legacy open shifts rather than discarding records');
    }
} finally {
    foreach ($saved as $entry) {
        $mapper->delete($entry);
    }
    Server::get(IAppConfig::class)->clearCache();
    if ($previousAccess === '') {
        $config->deleteAppValue('timesheets', 'access_policy');
    } else {
        $config->setAppValue('timesheets', 'access_policy', $previousAccess);
    }
    if ($previousTracking === null) {
        $config->deleteAppValue('timesheets', 'shift_tracking');
    } else {
        $config->setAppValue('timesheets', 'shift_tracking', $previousTracking);
    }
    if (!$user->delete()) {
        throw new RuntimeException('Failed to remove clock test account');
    }
    $cleanup = $db->getQueryBuilder();
    $cleanup->delete('timesheets_clock_locks')
        ->where($cleanup->expr()->eq('user_id', $cleanup->createNamedParameter($testUserId)));
    $cleanup->executeStatement();
}
echo "Shift clock checks passed; test shifts and account removed, tracking setting restored.\n";
