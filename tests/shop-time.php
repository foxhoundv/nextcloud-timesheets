<?php

declare(strict_types=1);

use OCA\Timesheets\Controller\PageController;
use OCA\Timesheets\Db\Job;
use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Service\ShopTimeService;
use OCP\Server;

require __DIR__ . '/persistence.php';

$calculator = Server::get(ShopTimeService::class);
function shopShift(?int $id, string $start, ?string $end): Shift {
    $shift = new Shift();
    if ($id !== null) {
        $shift->setId($id);
    }
    $shift->setShiftDate(substr($start, 0, 10));
    $shift->setClockIn($start);
    $shift->setClockOut($end);
    $shift->setTimeZone('UTC');
    $shift->setStatus($end === null ? 'open' : 'closed');
    $seconds = $end === null ? 0 : strtotime($end . ' UTC') - strtotime($start . ' UTC');
    $shift->setDurationSeconds($seconds);
    $shift->setDurationMinutes(intdiv($seconds, 60));
    return $shift;
}
function shopJob(string $start, ?string $end): Job {
    $job = new Job();
    $job->setJobReference('SO-shop');
    $job->setJobDate(substr($start, 0, 10));
    $job->setClockIn($start);
    $job->setClockOut($end);
    $job->setTimeZone('UTC');
    $job->setDurationSeconds($end === null ? 0 : strtotime($end . ' UTC') - strtotime($start . ' UTC'));
    return $job;
}
function shopCase(ShopTimeService $calculator, array $shifts, array $jobs, array $expected, string $message): void {
    $result = $calculator->calculate($shifts, $jobs);
    check($result['shifts'] === $expected, $message);
    check($result['jobSeconds'] === array_sum(array_column($expected, 'jobSeconds'))
        && $result['shopSeconds'] === array_sum(array_column($expected, 'shopSeconds')),
        $message . ': totals sum exact seconds');
}
function shopParts(int $total, int $job): array {
    return ['totalSeconds' => $total, 'jobSeconds' => $job, 'shopSeconds' => $total - $job];
}
$shift = shopShift(1, '2026-11-03 08:00:00', '2026-11-03 17:00:00');
shopCase($calculator, [], [], [], 'Empty history has zero totals');
shopCase($calculator, [$shift], [], [1 => shopParts(32400, 0)], 'Shift-only work is entirely shop time');
shopCase($calculator, [], [shopJob('2026-11-03 09:00:00', null)], [], 'Job-only work never becomes shop or shift time');
shopCase($calculator, [$shift], [
    shopJob('2026-11-03 09:00:00', '2026-11-03 10:00:00'),
    shopJob('2026-11-03 15:00:00', '2026-11-03 16:00:00'),
], [1 => shopParts(32400, 7200)], 'Several jobs subtract only their actual intervals');
shopCase($calculator, [$shift], [
    shopJob('2026-11-03 07:00:00', '2026-11-03 09:00:00'),
    shopJob('2026-11-03 16:00:00', '2026-11-03 18:00:00'),
], [1 => shopParts(32400, 7200)], 'Jobs crossing either shift boundary are clipped');
shopCase($calculator, [$shift], [
    shopJob('2026-11-03 06:00:00', '2026-11-03 08:00:00'),
    shopJob('2026-11-03 17:00:00', '2026-11-03 19:00:00'),
], [1 => shopParts(32400, 0)], 'Touching boundaries do not overlap');
shopCase($calculator, [$shift], [
    shopJob('2026-11-03 07:00:00', '2026-11-03 18:00:00'),
], [1 => shopParts(32400, 32400)], 'A covering job leaves zero shop time');
shopCase($calculator, [$shift], [
    shopJob('2026-11-03 11:00:00', '2026-11-03 13:00:00'),
    shopJob('2026-11-03 09:00:00', '2026-11-03 12:00:00'),
    shopJob('2026-11-03 10:00:00', '2026-11-03 11:00:00'),
    shopJob('2026-11-03 13:00:00', '2026-11-03 14:00:00'),
], [1 => shopParts(32400, 18000)], 'Unsorted overlapping and nested legacy jobs count their union only');
shopCase($calculator, [$shift], [
    shopJob('2026-11-03 16:00:00', null),
], [1 => shopParts(32400, 3600)], 'An active job contributes only until completed shift end');
shopCase($calculator, [$shift], [
    shopJob('2026-11-04 08:00:00', null),
], [1 => shopParts(32400, 0)], 'Later active jobs do not change earlier completed shifts');
shopCase($calculator, [
    shopShift(2, '2026-11-03 13:00:00', '2026-11-03 17:00:00'),
    shopShift(1, '2026-11-03 08:00:00', '2026-11-03 12:00:00'),
    shopShift(3, '2026-11-04 08:00:00', null),
], [shopJob('2026-11-03 11:00:00', '2026-11-03 14:00:00')], [
    1 => shopParts(14400, 3600), 2 => shopParts(14400, 3600),
], 'Job crossing a break contributes to each completed shift but not the gap or active shift');
shopCase($calculator, [shopShift(1, '2026-11-03 23:00:00', '2026-11-04 01:00:00')], [
    shopJob('2026-11-03 23:30:00', '2026-11-04 00:30:00'),
], [1 => shopParts(7200, 3600)], 'Midnight overlap stays with its original shift');
$dst = shopShift(1, '2026-11-01 08:30:00', '2026-11-01 10:30:00');
$dst->setTimeZone('America/Los_Angeles');
shopCase($calculator, [$dst], [
    shopJob('2026-11-01 09:00:00', '2026-11-01 10:00:00'),
], [1 => shopParts(7200, 3600)], 'DST repeated local hour does not change UTC elapsed seconds');
shopCase($calculator, [
    shopShift(1, '2026-11-03 08:00:00', '2026-11-03 08:00:59'),
    shopShift(2, '2026-11-03 09:00:00', '2026-11-03 09:00:59'),
], [
    shopJob('2026-11-03 08:00:00', '2026-11-03 08:00:31'),
    shopJob('2026-11-03 09:00:00', '2026-11-03 09:00:31'),
], [1 => shopParts(59, 31), 2 => shopParts(59, 31)], 'Sub-minute precision is summed before display truncation');
shopCase($calculator, [shopShift(1, '2026-11-03 08:00:00', '2026-11-03 08:00:00')], [
    shopJob('2026-11-03 08:00:00', null),
], [1 => shopParts(0, 0)], 'Zero-length shift never produces negative shop time');
$legacy = shopShift(1, '2026-11-03 08:00:00', '2026-11-03 09:00:00');
$legacy->setClockIn(null);
$result = $calculator->calculate([$legacy], []);
check($result['shifts'] === [] && $result['unavailableShifts'] === 1,
    'Legacy shift without endpoints is explicitly unavailable rather than fabricated shop time');
foreach ([
    [[$shift], [shopJob('2026-11-03 10:00:00', '2026-11-03 09:00:00')]],
    [[shopShift(1, '2026-11-03 10:00:00', '2026-11-03 09:00:00')], []],
    [[$shift], [shopJob('invalid', null)]],
] as [$invalidShifts, $invalidJobs]) {
    try {
        $calculator->calculate($invalidShifts, $invalidJobs);
        throw new LogicException('Invalid interval was accepted');
    } catch (RuntimeException $error) {
        check(true, 'Invalid interval surfaces an explicit error');
    }
}

// Compare the interval algorithm with an independent per-second occupancy model.
mt_srand(114);
for ($trial = 0; $trial < 200; $trial++) {
    $randomJobs = [];
    $occupied = array_fill(0, 300, false);
    for ($i = 0; $i < 12; $i++) {
        $start = mt_rand(0, 299);
        $end = mt_rand($start, 300);
        $randomJobs[] = shopJob(gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end));
        for ($second = $start; $second < $end; $second++) {
            $occupied[$second] = true;
        }
    }
    $randomShifts = [];
    for ($i = 1; $i <= 5; $i++) {
        $start = mt_rand(0, 299);
        $end = mt_rand($start, 300);
        $randomShifts[] = shopShift($i, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end));
    }
    $result = $calculator->calculate($randomShifts, $randomJobs);
    foreach ($randomShifts as $entry) {
        $start = strtotime($entry->getClockIn() . ' UTC');
        $end = strtotime($entry->getClockOut() . ' UTC');
        $expected = count(array_filter(array_slice($occupied, $start, $end - $start)));
        if ($result['shifts'][$entry->getId()] !== shopParts($end - $start, $expected)) {
            throw new RuntimeException('Occupancy oracle mismatch on trial ' . $trial);
        }
    }
}
check(true, '200 deterministic randomized scenarios match per-second occupancy, including overlapping shifts');

$users = Server::get(\OCP\IUserManager::class);
$id = 'timesheets-shop-' . bin2hex(random_bytes(8));
$user = $users->createUser($id, bin2hex(random_bytes(24)));
check($user !== false, 'Shop-time dashboard account created');
$mapper = new ShiftMapper($db);
$jobMapper = new JobMapper($db);
$session = Server::get(\OCP\IUserSession::class);
$previousUser = $session->getUser();
try {
    $db->beginTransaction();
    try {
        $saved = shopShift(null, '2026-11-03 08:00:00', '2026-11-03 17:00:00');
        $saved->setUserId($id);
        $mapper->insert($saved);
        $job = shopJob('2026-11-03 16:00:00', null);
        $job->setUserId($id);
        $job->setOpenUserId($id);
        $jobMapper->insert($job);
        $otherJob = shopJob('2026-11-03 08:00:00', '2026-11-03 17:00:00');
        $otherJob->setUserId($id . '-other');
        $jobMapper->insert($otherJob);
        $legacy = shopShift(null, '2026-11-03 08:00:00', '2026-11-03 09:00:00');
        $legacy->setClockIn(null);
        $legacy->setUserId($id);
        $mapper->insert($legacy);
        $before = serialize([$mapper->findByUser($id), $jobMapper->findByUser($id)]);
        $session->setUser($user);
        $page = Server::get(PageController::class);
        $params = $page->index()->getParams();
        $entries = array_column($params['entries'], null, 'clockIn');
        $entry = $entries['2026-11-03 08:00:00 UTC'];
        check($entry['total'] === '9h 00m' && $entry['jobTime'] === '1h 00m' && $entry['shopTime'] === '8h 00m',
            'Dashboard clips own active job and excludes another user job');
        $stats = array_column($params['stats'], 'value', 'label');
        check($stats['Recorded time'] === '10h 00m' && $stats['Job time in shifts'] === '1h 00m'
            && $stats['Shop time'] === '8h 00m' && $params['breakdown']['unavailableShifts'] === 1,
            'Dashboard retains legacy saved duration and explicitly reports incomplete breakdown coverage');
        check(serialize([$mapper->findByUser($id), $jobMapper->findByUser($id)]) === $before,
            'Shop-time projection never changes saved shift/job intervals');
        $html = (new \OCP\AppFramework\Http\TemplateResponse('timesheets', 'main', $params, 'blank'))->render();
        check(str_contains($html, 'Job time in shift') && str_contains($html, '8h 00m')
            && str_contains($html, 'Unavailable') && str_contains($html, 'lack recorded start or end times'),
            'Rendered dashboard includes breakdown columns and explicit legacy warning');
        $mapper->delete($saved);
        $mapper->delete($legacy);
        $jobMapper->delete($job);
        foreach (['08', '09'] as $hour) {
            $shortShift = shopShift(null, '2026-11-03 ' . $hour . ':00:00', '2026-11-03 ' . $hour . ':00:59');
            $shortShift->setUserId($id);
            $mapper->insert($shortShift);
            $shortJob = shopJob('2026-11-03 ' . $hour . ':00:00', '2026-11-03 ' . $hour . ':00:31');
            $shortJob->setUserId($id);
            $jobMapper->insert($shortJob);
        }
        $open = shopShift(null, '2026-11-03 10:00:00', null);
        $open->setUserId($id);
        $open->setOpenUserId($id);
        $mapper->insert($open);
        $params = $page->index()->getParams();
        $stats = array_column($params['stats'], 'value', 'label');
        check($stats['Recorded time'] === '0h 01m' && $stats['Job time in shifts'] === '0h 01m'
            && $stats['Shop time'] === '0h 00m'
            && array_column(array_slice($params['entries'], 1), 'total') === ['0h 00m', '0h 00m'],
            'Dashboard totals sum exact seconds before truncating, rather than summing displayed minutes');
        check($params['entries'][0]['isOpen'] && $params['entries'][0]['jobTime'] === null
            && $params['entries'][0]['shopTime'] === null && count($params['breakdown']['shifts']) === 2,
            'Active shift has no finalized breakdown and is excluded from all breakdown totals');
    } finally {
        $db->rollBack();
    }
    check($mapper->findByUser($id) === [] && $jobMapper->findByUser($id) === [],
        'Database-backed shop-time fixtures are rolled back');
} finally {
    $session->setUser($previousUser);
    check($user->delete(), 'Shop-time dashboard account removed');
}
echo "Shop time checks passed.\n";
