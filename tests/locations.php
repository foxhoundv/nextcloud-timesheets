<?php

declare(strict_types=1);

use OCA\Timesheets\Db\Job;
use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Service\AccessService;
use OCA\Timesheets\Service\ClockTransaction;
use OCA\Timesheets\Service\JobClockService;
use OCA\Timesheets\Service\LocationService;
use OCA\Timesheets\Service\PayrollLocationService;
use OCA\Timesheets\Service\RoundingService;
use OCA\Timesheets\Service\ShiftClockService;
use OCA\Timesheets\Service\StorageService;
use OCA\Timesheets\Service\WorkbookWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = $argv[1] ?? '';
if (!is_file($root . '/lib/base.php')) {
    fwrite(STDERR, "Usage: php tests/locations.php /path/to/nextcloud\n");
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
        throw new LogicException('Expected rejected action: ' . $message);
    } catch (DomainException $error) {
        check($error->getCode() === $code, $message);
    }
}
$config = Server::get(IConfig::class);
$previous = [];
$keys = $config->getAppKeys('timesheets');
foreach (['shift_tracking', 'job_tracking', 'access_policy', 'job_require_shift', 'shift_end_job_policy',
    'shift_rounding_enabled', 'job_rounding_enabled', 'net_shift_threshold'] as $key) {
    $previous[$key] = in_array($key, $keys, true) ? $config->getAppValue('timesheets', $key) : null;
}
$id = 'timesheets-location-' . bin2hex(random_bytes(8));
$user = Server::get(IUserManager::class)->createUser($id, bin2hex(random_bytes(24)));
check($user !== false, 'Location test account created');
$db = Server::get(IDBConnection::class);
$shifts = new ShiftMapper($db);
$jobs = new JobMapper($db);
$locations = Server::get(LocationService::class);
$time = new class implements ITimeFactory {
    public int $timestamp = 1793779200;
    public function getTime(): int { return $this->timestamp; }
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . $this->timestamp); }
    public function getDateTime(string $time = 'now', ?DateTimeZone $timezone = null): DateTime { return new DateTime($time, $timezone); }
    public function withTimeZone(DateTimeZone $timezone): static { return $this; }
    public function getTimeZone(?string $timezone = null): DateTimeZone { return new DateTimeZone($timezone ?? 'UTC'); }
};
$shiftClock = new ShiftClockService($shifts, $config, $time, Server::get(AccessService::class),
    new ClockTransaction($db), $jobs, Server::get(RoundingService::class), $locations);
$jobClock = new JobClockService($jobs, $shifts, $shiftClock, Server::get(AccessService::class), $config,
    $time, new ClockTransaction($db), Server::get(RoundingService::class), Server::get(StorageService::class),
    Server::get(LoggerInterface::class), $locations);
require_once __DIR__ . '/JobStorageFixture.php';
$fixture = new JobStorageFixture($id, ['SO0001', 'SO0002']);
try {
    foreach (['shift_tracking', 'job_tracking'] as $key) {
        $config->setAppValue('timesheets', $key, '1');
    }
    $config->setAppValue('timesheets', 'access_policy', '{"restricted":false,"grants":[]}');
    $config->setAppValue('timesheets', 'job_require_shift', '0');
    $config->setAppValue('timesheets', 'shift_end_job_policy', 'block');
    foreach (['shift_rounding_enabled', 'job_rounding_enabled', 'net_shift_threshold'] as $key) {
        $config->setAppValue('timesheets', $key, '0');
    }
    check(!$locations->settings()['shiftEnabled'] && $locations->capture('shift', null, null) === null,
        'Location tracking is optional and disabled by default');
    rejects(fn () => $locations->capture('shift', 'US', 'Oregon'), 409, 'Disabled tracking rejects stale submitted locations');
    $settings = [
        'shiftEnabled' => true, 'jobEnabled' => true, 'multipleCountries' => false,
        'multipleRegions' => true, 'workingCountry' => 'US', 'countries' => [],
        'regions' => ['US' => ['Oregon', 'Idaho']], 'defaultRegion' => 'Oregon',
    ];
    $locations->save(json_encode($settings, JSON_THROW_ON_ERROR));
    check($locations->options()['countries']['US']['regions'] === ['Idaho', 'Oregon'],
        'One working country provides an alphabetized state dropdown');
    $saved = $config->getAppValue('timesheets', 'work_locations');
    foreach ([
        array_replace($settings, ['workingCountry' => 'ZZ']),
        array_replace($settings, ['regions' => ['US' => ['Not a state']]]),
        array_replace($settings, ['multipleCountries' => true, 'countries' => ['US']]),
        array_replace($settings, ['regions' => ['US' => []]]),
        array_replace($settings, ['defaultRegion' => 'Washington']),
        array_replace($settings, ['shiftEnabled' => '1']),
        array_replace($settings, ['regions' => ['US' => [['Oregon']]]]),
    ] as $invalid) {
        rejects(fn () => $locations->save(json_encode($invalid, JSON_THROW_ON_ERROR)), 400, 'Invalid location configuration is rejected');
        check($config->getAppValue('timesheets', 'work_locations') === $saved, 'Invalid configuration does not overwrite saved settings');
    }
    rejects(fn () => $locations->save('{invalid'), 400, 'Malformed JSON is rejected explicitly');
    rejects(fn () => $shiftClock->clockIn($id, 'US', 'Washington'), 400, 'Unselected state cannot be submitted directly');
    rejects(fn () => $shiftClock->clockIn($id, 'CA', 'Oregon'), 400, 'Unselected country cannot be submitted directly');
    rejects(fn () => $shiftClock->clockIn($id), 400, 'Selectable regions require a valid location at clock-in');
    check($shifts->findByUser($id) === [], 'Rejected locations create no shift intervals');
    $time->timestamp = strtotime('2026-11-03 08:00:00 UTC');
    $shift = $shiftClock->clockIn($id, 'US', 'Oregon');
    $oregon = $shift->getWorkLocation();
    check(LocationService::label($oregon) === 'United States / Oregon', 'Shift captures its country and region snapshot');
    $time->timestamp += 3600;
    $job = $jobClock->clockIn($id, 'SO0001', 'US', 'Idaho');
    $idaho = $job->getWorkLocation();
    check(LocationService::decode($idaho)['region'] === 'Idaho', 'Job can override the inherited shift location');
    $time->timestamp += 7200;
    $jobClock->clockOut($id, $job->getId());
    $time->timestamp += 3600;
    $shiftClock->clockOut($id, $shift->getId());
    $closed = $shifts->findByUser($id)[0];
    $allocator = new PayrollLocationService($shiftClock);
    $parts = array_values($allocator->split($closed, $jobs->findByUser($id), 14400, 14400));
    check(array_sum(array_column($parts, 'actual')) === 14400 && array_column($parts, 'actual', 'region') === [
        'Oregon' => 7200, 'Idaho' => 7200,
    ], 'Four-hour shift splits into two Oregon and two Idaho hours without double counting');
    check(array_sum(array_column($parts, 'reported')) === 14400, 'Location totals reconcile with saved reported payroll time');

    $writer = new class extends WorkbookWriter {
        public array $rows = [];
        public array $payrollRows = [];
        public function write(array $rows): string {
            $this->rows = $rows;
            if (($rows[1][0] ?? '') === 'Account') {
                $this->payrollRows[$rows[1][1]] = $rows;
            }
            return parent::write($rows);
        }
    };
    $storage = new StorageService($config, Server::get(\OCP\Files\IRootFolder::class), Server::get(IUserManager::class),
        $shifts, $jobs, $shiftClock, $writer, Server::get(LoggerInterface::class), $allocator);
    $export = $storage->generatePayroll('weekly', '2026-11-03');
    check($export['count'] >= 1 && in_array(['United States', 'Oregon', '2:00:00', '2:00:00'], $writer->payrollRows[$id] ?? [], true)
        && in_array(['United States', 'Idaho', '2:00:00', '2:00:00'], $writer->payrollRows[$id] ?? [], true),
        'Generated weekly workbook contains separate, reconciled Oregon and Idaho totals');
    $storage->synchronizeJob('SO0001');
    check(in_array('Country', $writer->rows[3], true) && in_array('Idaho', $writer->rows[4], true),
        'Job workbook includes the captured country and region');

    rejects(fn () => $jobClock->clockIn($id, 'SO0001', 'US', 'Oregon'), 409,
        'The same Job ID cannot be assigned a conflicting physical location');
    $settings['jobEnabled'] = false;
    $locations->save(json_encode($settings, JSON_THROW_ON_ERROR));
    $next = $shiftClock->clockIn($id, 'US', 'Idaho');
    $inherited = $jobClock->clockIn($id, 'SO0001');
    check($inherited->getWorkLocation() === $next->getWorkLocation(), 'Job without location selection inherits its active shift');
    $settings['shiftEnabled'] = false;
    $locations->save(json_encode($settings, JSON_THROW_ON_ERROR));
    $time->timestamp += 60;
    $jobClock->clockOut($id, $inherited->getId());
    $shiftClock->clockOut($id, $next->getId());
    check($jobs->findByUser($id)[0]->getWorkLocation() === $idaho
        && $shiftClock->current($id) === null, 'Disabling location tracking preserves snapshots and still permits clock-out');

    $settings['jobEnabled'] = true;
    $locations->save(json_encode($settings, JSON_THROW_ON_ERROR));
    $defaultShift = $shiftClock->clockIn($id);
    check($defaultShift->getWorkLocation() === $oregon, 'Nonselectable shift uses the administrator default location');
    $shiftClock->clockOut($id, $defaultShift->getId());
    $settings['multipleCountries'] = true;
    $settings['countries'] = ['US', 'CA'];
    $settings['regions']['CA'] = ['British Columbia', 'Alberta'];
    $locations->save(json_encode($settings, JSON_THROW_ON_ERROR));
    $canada = $locations->capture('job', 'CA', 'British Columbia');
    check(LocationService::label($canada) === 'Canada / British Columbia', 'Other countries support named provinces and regions');
    check(count($locations->options()['countries']) === 2, 'Only selected countries appear in employee choices');
    $settings['multipleRegions'] = false;
    $locations->save(json_encode($settings, JSON_THROW_ON_ERROR));
    check(LocationService::label($locations->capture('job', 'CA', null)) === 'Canada',
        'Multiple countries can be tracked without region tracking');
    check(LocationService::label($locations->capture('job', 'US', null, $oregon)) === 'United States',
        'Turning off region selection allows new Jobs to inherit only the country from an older active shift');
    check($shifts->findByUser($id)[count($shifts->findByUser($id)) - 1]->getWorkLocation() === $oregon,
        'Changing allowed countries and regions never relabels historical intervals');

    $synthetic = new Shift();
    $synthetic->setUserId($id);
    $synthetic->setClockIn('2026-11-03 08:00:00');
    $synthetic->setClockOut('2026-11-03 08:00:03');
    $synthetic->setWorkLocation($oregon);
    $slice = new Job();
    $slice->setUserId($id);
    $slice->setClockIn('2026-11-03 08:00:01');
    $slice->setClockOut('2026-11-03 08:00:02');
    $slice->setWorkLocation($idaho);
    $rounded = array_values($allocator->split($synthetic, [$slice], 3, 5));
    check(array_sum(array_column($rounded, 'reported')) === 5
        && array_column($rounded, 'reported', 'region') === ['Oregon' => 3, 'Idaho' => 2],
        'Fractional proportional allocations use largest remainders and reconcile to the second');
    $slice->setClockIn('2026-11-03 07:00:00');
    $slice->setClockOut(null);
    $clipped = array_values($allocator->split($synthetic, [$slice], 3, 3));
    check(count($clipped) === 1 && $clipped[0]['region'] === 'Idaho' && $clipped[0]['actual'] === 3,
        'An ongoing Job crossing shift boundaries contributes only its overlapping seconds');
    $slice->setClockIn('2026-11-03 09:00:00');
    check(array_values($allocator->split($synthetic, [$slice], 3, 3))[0]['region'] === 'Oregon',
        'Job time outside shifts is never added to payroll');
    $synthetic->setWorkLocation(null);
    $legacy = array_values($allocator->split($synthetic, [], 3, 3));
    check($legacy[0]['country'] === 'Not recorded', 'Legacy entries remain explicitly unassigned rather than receiving the current default');
    $synthetic->setClockIn(null);
    $synthetic->setClockOut(null);
    check(array_values($allocator->split($synthetic, [], 7500, 7500))[0]['actual'] === 7500,
        'Legacy records without timestamps keep their saved payroll duration');
    $synthetic->setClockIn('2026-11-03 08:00:00');
    $synthetic->setClockOut('2026-11-03 08:00:00');
    check(array_sum(array_column($allocator->split($synthetic, [], 0, 0), 'actual')) === 0,
        'Zero-duration intervals remain valid and reconcile');
    $synthetic->setClockOut('2026-11-03 08:00:03');
    $slice->setClockIn('2026-11-03 08:00:00');
    $slice->setClockOut('2026-11-03 08:00:03');
    $conflict = clone $slice;
    $conflict->setWorkLocation($oregon);
    try {
        $allocator->split($synthetic, [$slice, $conflict], 3, 3);
        throw new LogicException('Conflicting locations must not be silently allocated');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'conflicting locations'), 'Ambiguous overlapping Job locations stop the payroll export explicitly');
    }
    $foreign = clone $slice;
    $foreign->setUserId($id . '-other');
    $synthetic->setWorkLocation($oregon);
    check(array_values($allocator->split($synthetic, [$foreign], 3, 3))[0]['region'] === 'Oregon',
        'Another employee cannot change this employee payroll location');
    $renamedCountry = json_encode(['countryCode' => 'US', 'country' => 'Older country label', 'region' => 'Oregon'], JSON_THROW_ON_ERROR);
    check(LocationService::key($renamedCountry) === LocationService::key($oregon)
        && LocationService::label($renamedCountry) === 'Older country label / Oregon',
        'Jurisdiction identity is stable while original country labels remain unchanged in history');
    for ($case = 1; $case <= 20; $case++) {
        $start = strtotime('2026-11-03 08:00:00 UTC');
        $seconds = 60 + $case * 17;
        $from = ($case * 7) % $seconds;
        $to = min($seconds, $from + $case * 9);
        $synthetic->setClockOut(gmdate('Y-m-d H:i:s', $start + $seconds));
        $slice->setClockIn(gmdate('Y-m-d H:i:s', $start + $from));
        $slice->setClockOut(gmdate('Y-m-d H:i:s', $start + $to));
        $reported = $case % 2 === 0 ? 0 : $seconds + 37;
        $allocation = array_values($allocator->split($synthetic, [$slice], $seconds, $reported));
        $byRegion = array_column($allocation, 'actual', 'region');
        check(($byRegion['Idaho'] ?? 0) === $to - $from
            && ($byRegion['Oregon'] ?? 0) === $seconds - ($to - $from)
            && array_sum(array_column($allocation, 'reported')) === $reported,
            'Boundary and rounded allocation reconciles exactly for generated case ' . $case);
    }
} finally {
    foreach ($jobs->findByUser($id) as $job) {
        $jobs->delete($job);
    }
    foreach ($shifts->findByUser($id) as $shift) {
        $shifts->delete($shift);
    }
    $fixture->cleanup();
    Server::get(IAppConfig::class)->clearCache();
    foreach ($previous as $key => $value) {
        if ($value === null) {
            $config->deleteAppValue('timesheets', $key);
        } else {
            $config->setAppValue('timesheets', $key, $value);
        }
    }
    $query = $db->getQueryBuilder();
    $query->delete('timesheets_clock_locks')->where($query->expr()->eq('user_id', $query->createNamedParameter($id)));
    $query->executeStatement();
    check($user->delete(), 'Location test account and fixtures removed; original settings restored');
}
echo "Work-location checks passed.\n";
