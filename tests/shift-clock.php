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
$service = new ShiftClockService($mapper, $config, $clock, Server::get(\OCA\Timesheets\Service\AccessService::class));
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
}
echo "Shift clock checks passed; test shifts and account removed, tracking setting restored.\n";
