<?php

declare(strict_types=1);

use OCA\Timesheets\Db\Job;
use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Service\StorageService;
use OCA\Timesheets\Service\JobClockService;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Server;

if (PHP_SAPI === 'cli' && ($argv[2] ?? '') === 'worker') {
    define('OC_CONSOLE', 1);
    require $argv[1] . '/lib/base.php';
    \OC_App::loadApps();
    try {
        $workerClock = Server::get(JobClockService::class);
        if ($argv[4] === 'in') {
            echo $workerClock->clockIn($argv[3], 'SO260200')->getId();
        } else {
            $warning = $workerClock->clockOut($argv[3], (int) $argv[5]);
            if ($warning !== null) {
                throw new RuntimeException($warning);
            }
            echo 'closed';
        }
    } catch (Throwable $error) {
        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage());
        exit(1);
    }
    exit(0);
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$nextcloudRoot = $argv[1] ?? '';
if (!is_file($nextcloudRoot . '/lib/base.php')) {
    fwrite(STDERR, "Usage: php tests/storage.php /path/to/nextcloud\n");
    exit(2);
}

define('OC_CONSOLE', 1);
require_once $nextcloudRoot . '/lib/base.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
});
\OC_App::loadApps();

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

function workbookSheet(string $content): string {
    $path = tempnam(sys_get_temp_dir(), 'timesheets-test-xlsx-');
    if ($path === false || file_put_contents($path, $content) === false) {
        throw new RuntimeException('Unable to stage a generated workbook for validation.');
    }
    $archive = new ZipArchive();
    try {
        if ($archive->open($path) !== true) {
            throw new RuntimeException('Generated workbook is not a valid ZIP package.');
        }
        $parts = [
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/worksheets/sheet1.xml',
        ];
        foreach ($parts as $part) {
            $xml = $archive->getFromName($part);
            if ($xml === false) {
                throw new RuntimeException('Generated workbook is missing ' . $part . '.');
            }
            $document = new DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('Generated workbook part ' . $part . ' is not valid XML.');
            }
        }
        $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            throw new RuntimeException('Generated workbook is missing its worksheet.');
        }
        return $sheet;
    } finally {
        if ($archive->status === ZipArchive::ER_OK) {
            $archive->close();
        }
        unlink($path);
    }
}

function concurrentClocks(string $root, array $workers): array {
    $processes = [];
    try {
        foreach ($workers as [$userId, $action, $jobId]) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, $root, 'worker', $userId, $action, (string) $jobId],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start a concurrent Job workbook worker.');
            }
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            check(proc_close($process) === 0 && $error === '', 'Concurrent workbook worker completes: ' . $error);
            $results[] = $output;
        }
        return $results;
    } finally {
        foreach ($processes as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}

$config = Server::get(IConfig::class);
$configKeys = [
    'storage_owner',
    'payroll_storage_root',
    'sales_order_storage_root',
    'job_reference_format',
    'job_year_base',
    'job_folder_template',
    'job_group_first_end',
    'job_group_size',
    'job_group_name_format',
    'payroll_week_start',
    'work_locations',
    'job_tracking',
    'shift_tracking',
    'job_require_shift',
    'access_policy',
];
$existingKeys = $config->getAppKeys('timesheets');
$previous = [];
foreach ($configKeys as $key) {
    $previous[$key] = in_array($key, $existingKeys, true) ? $config->getAppValue('timesheets', $key) : null;
}
$users = Server::get(IUserManager::class);
$userId = 'timesheets-storage-' . bin2hex(random_bytes(8));
$otherUserId = $userId . '-other';
$user = $users->createUser($userId, bin2hex(random_bytes(24)));
check($user !== false, 'Storage test account created');
$otherUser = $users->createUser($otherUserId, bin2hex(random_bytes(24)));
check($otherUser !== false, 'Second payroll account created');
$shiftMapper = Server::get(ShiftMapper::class);
$jobMapper = Server::get(JobMapper::class);
$storage = Server::get(StorageService::class);
$rootFolder = Server::get(IRootFolder::class);
$baseFolder = null;
$testShifts = [];
$testJobs = [];

try {
    $config->deleteAppValue('timesheets', 'work_locations');
    $baseName = 'timesheets-storage-' . bin2hex(random_bytes(6));
    $baseFolder = $rootFolder->getUserFolder($userId)->newFolder($baseName);
    check($baseFolder instanceof Folder, 'Test storage root created in the account files');
    $baseFolder->newFolder('Payroll');
    $jobRoot = $baseFolder->newFolder('Sales Order');
    $yearFolder = $jobRoot->newFolder('2026');
    $firstGroup = $yearFolder->newFolder('1-99');
    $orderFolder = $firstGroup->newFolder('SO260001 - Customer');
    $storage->saveRoots($userId, $baseName . '/Payroll', $baseName . '/Sales Order', payrollWeekStart: '1');
    check($storage->settings() === [
        'owner' => $userId,
        'payrollRoot' => $baseName . '/Payroll',
        'jobRoot' => $baseName . '/Sales Order',
        'jobReferenceFormat' => 'SO{year:2}{sequence:4}',
        'jobYearBase' => '2000',
        'jobFolderTemplate' => '{year}/{group}/{jobId}',
        'jobGroupFirstEnd' => '99',
        'jobGroupSize' => '100',
        'jobGroupNameFormat' => '{start}-{end}',
        'payrollWeekStart' => '1',
    ], 'Writable shared roots are saved with their owning account');
    rejects(fn () => $storage->saveRoots($userId, '../outside', $baseName . '/Sales Order'), 400,
        'Storage roots reject parent-directory traversal');
    check($storage->settings()['payrollRoot'] === $baseName . '/Payroll',
        'Invalid storage paths do not overwrite saved roots');
    rejects(fn () => $storage->saveRoots($userId, $baseName . '/missing', $baseName . '/Sales Order'), 400,
        'Storage roots must point to existing folders');
    rejects(fn () => $storage->saveRoots(
        $userId,
        $baseName . '/Payroll',
        $baseName . '/Sales Order',
        'SO{year:2}{sequence:4}',
        '2000',
        '../{jobId}',
    ), 400, 'Job folder templates reject traversal before saving any settings');
    check($storage->settings()['jobFolderTemplate'] === '{year}/{group}/{jobId}',
        'Invalid Job folder templates do not overwrite the saved configuration');

    $shift = new Shift();
    $shift->setUserId($userId);
    $shift->setShiftDate('2026-11-03');
    $shift->setClockIn('2026-11-03 16:00:00');
    $shift->setClockOut('2026-11-03 18:02:30');
    $shift->setDurationMinutes(122);
    $shift->setDurationSeconds(7350);
    $shift->setRoundedSeconds(7200);
    $shift->setRoundingInterval(15);
    $shift->setTimeZone('UTC');
    $shift->setStatus('closed');
    $testShifts[] = $shiftMapper->insert($shift);

    $otherShift = new Shift();
    $otherShift->setUserId($otherUserId);
    $otherShift->setShiftDate('2026-11-05');
    $otherShift->setClockIn('2026-11-05 08:00:00');
    $otherShift->setClockOut('2026-11-05 16:00:00');
    $otherShift->setDurationMinutes(480);
    $otherShift->setDurationSeconds(28800);
    $otherShift->setRoundedSeconds(28800);
    $otherShift->setRoundingInterval(0);
    $otherShift->setTimeZone('UTC');
    $otherShift->setStatus('closed');
    $testShifts[] = $shiftMapper->insert($otherShift);

    $outOfRangeShift = new Shift();
    $outOfRangeShift->setUserId($userId);
    $outOfRangeShift->setShiftDate('2026-10-31');
    $outOfRangeShift->setClockIn('2026-10-31 08:00:00');
    $outOfRangeShift->setClockOut('2026-10-31 09:00:00');
    $outOfRangeShift->setDurationMinutes(60);
    $outOfRangeShift->setDurationSeconds(3600);
    $outOfRangeShift->setRoundedSeconds(3600);
    $outOfRangeShift->setRoundingInterval(0);
    $outOfRangeShift->setTimeZone('UTC');
    $outOfRangeShift->setStatus('closed');
    $testShifts[] = $shiftMapper->insert($outOfRangeShift);

    $openShift = new Shift();
    $openShift->setUserId($userId);
    $openShift->setShiftDate('2026-11-04');
    $openShift->setClockIn('2026-11-04 08:00:00');
    $openShift->setDurationMinutes(0);
    $openShift->setTimeZone('UTC');
    $openShift->setStatus('open');
    $testShifts[] = $shiftMapper->insert($openShift);
    foreach (['2026-11-01', '2026-11-07', '2026-11-08'] as $index => $boundaryDate) {
        $boundaryShift = new Shift();
        $boundaryShift->setUserId($userId);
        $boundaryShift->setShiftDate($boundaryDate);
        $boundaryShift->setClockIn($boundaryDate . ' 08:00:00');
        $boundaryShift->setClockOut($boundaryDate . ' 08:01:0' . ($index + 1));
        $boundaryShift->setDurationMinutes(1);
        $boundaryShift->setDurationSeconds(61 + $index);
        $boundaryShift->setRoundedSeconds(61 + $index);
        $boundaryShift->setRoundingInterval(0);
        $boundaryShift->setTimeZone('UTC');
        $boundaryShift->setStatus('closed');
        $testShifts[] = $shiftMapper->insert($boundaryShift);
    }

    $weekly = $storage->generatePayroll('weekly', '2026-11-05');
    $weeklyEmployeeFiles = [
        rawurlencode($userId) . '/timesheet-weekly-2026-11-02-2026-11-08.xlsx',
        rawurlencode($otherUserId) . '/timesheet-weekly-2026-11-02-2026-11-08.xlsx',
    ];
    check($weekly['start'] === '2026-11-02' && $weekly['end'] === '2026-11-08'
        && $weekly['count'] >= 2 && array_diff($weeklyEmployeeFiles, $weekly['files']) === [],
        'Weekly payroll generation uses Monday through Sunday and creates one workbook per employee: '
            . json_encode($weekly, JSON_THROW_ON_ERROR));
    $payrollFile = $rootFolder->getUserFolder($userId)->get($baseName . '/Payroll/' . rawurlencode($userId)
        . '/timesheet-weekly-2026-11-02-2026-11-08.xlsx');
    $payrollXml = workbookSheet($payrollFile->getContent());
    check(str_contains($payrollXml, 'Employee timesheet')
        && str_contains($payrollXml, '2:02:30')
        && str_contains($payrollXml, '2:00:00')
        && !str_contains($payrollXml, '1:00:00'),
        'Payroll workbook includes completed period shifts and actual/reported totals, excluding open and out-of-period shifts');
    $regeneratedWeekly = $storage->generatePayroll('weekly', '2026-11-05');
    check($regeneratedWeekly['files'] === $weekly['files']
        && str_contains(workbookSheet($payrollFile->getContent()), '2:00:00'),
        'Regenerating a payroll period replaces its existing workbooks');
    $monthly = $storage->generatePayroll('monthly', '2026-11-19');
    $monthlyEmployeeFiles = [
        rawurlencode($userId) . '/timesheet-monthly-2026-11-01-2026-11-30.xlsx',
        rawurlencode($otherUserId) . '/timesheet-monthly-2026-11-01-2026-11-30.xlsx',
    ];
    check($monthly['start'] === '2026-11-01' && $monthly['end'] === '2026-11-30'
        && $monthly['count'] >= 2 && array_diff($monthlyEmployeeFiles, $monthly['files']) === [],
        'Monthly payroll generation uses the selected calendar month');
    rejects(fn () => $storage->generatePayroll('biweekly', '2026-11-05'), 400,
        'Bi-weekly exports remain unavailable until their pay-period anchor is configured');
    rejects(fn () => $storage->generatePayroll('weekly', '2026-02-30'), 400,
        'Payroll generation rejects invalid dates');
    foreach ([
        ['7', '2026-11-05', '2026-11-01', '2026-11-07'],
        ['7', '2026-11-07', '2026-11-01', '2026-11-07'],
        ['7', '2026-11-08', '2026-11-08', '2026-11-14'],
        ['3', '2026-01-01', '2025-12-31', '2026-01-06'],
        ['4', '2026-03-01', '2026-02-26', '2026-03-04'],
    ] as [$firstDay, $date, $expectedStart, $expectedEnd]) {
        $storage->saveRoots($userId, $baseName . '/Payroll', $baseName . '/Sales Order',
            payrollWeekStart: $firstDay);
        $period = $storage->generatePayroll('weekly', $date);
        check($period['start'] === $expectedStart && $period['end'] === $expectedEnd,
            'Configured weekly boundaries include both endpoints and cross months/years: ' . $date);
    }
    rejects(fn () => $storage->saveRoots($userId, $baseName . '/Payroll', $baseName . '/Sales Order',
        payrollWeekStart: '0'), 400, 'Invalid first weekday is rejected without changing settings');
    check($storage->settings()['payrollWeekStart'] === '4', 'Rejected weekday leaves the saved workweek intact');
    $storage->saveRoots($userId, $baseName . '/Payroll', $baseName . '/Sales Order');
    check($storage->settings()['payrollWeekStart'] === '4', 'Omitted weekday preserves the previously saved workweek');
    $storage->saveRoots($userId, $baseName . '/Payroll', $baseName . '/Sales Order', payrollWeekStart: '7');
    $sunday = $storage->generatePayroll('weekly', '2026-11-05');
    $sundayXml = workbookSheet($rootFolder->getUserFolder($userId)->get(
        $baseName . '/Payroll/' . rawurlencode($userId) . '/timesheet-weekly-2026-11-01-2026-11-07.xlsx'
    )->getContent());
    check(str_contains($sundayXml, '2026-11-01 08:00:00')
        && str_contains($sundayXml, '2026-11-07 08:00:00')
        && !str_contains($sundayXml, '2026-11-08') && $sunday['count'] >= 2,
        'Configured payroll includes first/last-day shifts and excludes the next payroll week');
    check($storage->generatePayroll('monthly', '2026-11-05')['start'] === '2026-11-01',
        'Monthly payroll remains independent of the weekly start setting');

    $job = new Job();
    $job->setUserId($userId);
    $job->setJobReference('SO260001');
    $job->setJobDate('2026-11-03');
    $job->setClockIn('2026-11-03 17:00:00');
    $job->setClockOut('2026-11-03 17:30:00');
    $job->setDurationSeconds(1800);
    $job->setRoundedSeconds(2700);
    $job->setRoundingInterval(15);
    $job->setTimeZone('UTC');
    $testJobs[] = $jobMapper->insert($job);

    $similarJob = new Job();
    $similarJob->setUserId($otherUserId);
    $similarJob->setJobReference('SO260002');
    $similarJob->setJobDate('2026-11-03');
    $similarJob->setClockIn('2026-11-03 18:00:00');
    $similarJob->setClockOut('2026-11-03 18:30:00');
    $similarJob->setDurationSeconds(1800);
    $similarJob->setRoundedSeconds(1800);
    $similarJob->setRoundingInterval(0);
    $similarJob->setTimeZone('UTC');
    $testJobs[] = $jobMapper->insert($similarJob);

    $openJob = new Job();
    $openJob->setUserId($userId);
    $openJob->setJobReference('SO260001');
    $openJob->setJobDate('2026-11-04');
    $openJob->setClockIn('2026-11-04 08:00:00');
    $openJob->setTimeZone('UTC');
    $testJobs[] = $jobMapper->insert($openJob);

    $jobReport = $storage->synchronizeJob('SO260001');
    check($jobReport['count'] === 2
        && $jobReport['folder'] === '2026/1-99/SO260001 - Customer/Time logs/Time logs.xlsx',
        'Job report resolves the configured year and first-group path and matches the Job ID folder prefix');
    $jobFile = $rootFolder->getUserFolder($userId)->get(
        $baseName . '/Sales Order/2026/1-99/SO260001 - Customer/Time logs/Time logs.xlsx'
    );
    $jobXml = workbookSheet($jobFile->getContent());
    check(str_contains($jobXml, 'SO260001')
        && str_contains($jobXml, '0:30:00')
        && str_contains($jobXml, '0:45:00')
        && !str_contains($jobXml, 'SO260002'),
        'Job workbook includes exact-ID closed and active intervals without finalizing open durations');
    $yearFolder->newFolder('100-199')->newFolder('SO260100 - Another Customer');
    check($storage->synchronizeJob('SO260100')['folder']
        === '2026/100-199/SO260100 - Another Customer/Time logs/Time logs.xlsx',
        'Sequence 100 starts the next 100-number grouping folder');
    $yearFolder->get('1-99')->newFolder('SO260099 - Last first-group Job');
    check($storage->synchronizeJob('SO260099')['folder']
        === '2026/1-99/SO260099 - Last first-group Job/Time logs/Time logs.xlsx',
        'Sequence 99 remains in the first grouping folder');
    $firstGroup->newFolder('SO260001 Secondary');
    rejects(fn () => $storage->synchronizeJob('SO260001'), 409,
        'Ambiguous Job ID folder prefix matches are rejected');
    rejects(fn () => $storage->synchronizeJob('SO260003'), 404,
        'Missing Job folders are explicitly rejected');
    rejects(fn () => $storage->synchronizeJob('NOT-A-JOB'), 400,
        'Job IDs that do not match the configured format are rejected');
    rejects(fn () => $storage->synchronizeJob('SO260000'), 400,
        'Zero is rejected as a Job sequence number');

    $customGroup = $jobRoot->newFolder('Jobs')->newFolder('1926')->newFolder('batch-10-to-19');
    $customGroup->newFolder('AB260012 - Customer');
    $storage->saveRoots(
        $userId,
        $baseName . '/Payroll',
        $baseName . '/Sales Order',
        'AB{year:2}{sequence:4}',
        '1900',
        'Jobs/{year}/{group}/{jobId}',
        '9',
        '10',
        'batch-{start}-to-{end}',
    );
    check($storage->synchronizeJob('AB260012')['folder']
        === 'Jobs/1926/batch-10-to-19/AB260012 - Customer/Time logs/Time logs.xlsx',
        'Custom Job ID, folder path, century, and grouping formats support other business structures');
    $jobMapper->delete($openJob);
    array_pop($testJobs);
    $config->setAppValue('timesheets', 'job_tracking', '1');
    $config->setAppValue('timesheets', 'shift_tracking', '0');
    $config->setAppValue('timesheets', 'job_require_shift', '0');
    $config->setAppValue('timesheets', 'access_policy', '{"restricted":false,"grants":[]}');
    $storage->saveRoots($userId, $baseName . '/Payroll', $baseName . '/Sales Order');
    $clock = Server::get(JobClockService::class);
    rejects(fn () => $clock->clockIn($userId, 'SO260003'), 404,
        'Missing folder prevents Job clock-in');
    check($jobMapper->findOpenForUser($userId) === null, 'Failed clock-in leaves no active database interval');
    $automaticFolder = $yearFolder->newFolder('200-299')->newFolder('SO260200 - Automatic');
    $first = $clock->clockIn($userId, 'SO260200');
    $testJobs[] = $first;
    $automaticFile = $automaticFolder->get('Time logs/Time logs.xlsx');
    $activeXml = workbookSheet($automaticFile->getContent());
    check(str_contains($activeXml, 'Active') && str_contains($activeXml, 'Interval ID')
        && str_contains($activeXml, $userId) && !str_contains($activeXml, '>Closed<'),
        'First clock-in creates a workbook with an interval ID and active start row');
    $second = $clock->clockIn($otherUserId, 'SO260200');
    $testJobs[] = $second;
    $bothXml = workbookSheet($automaticFile->getContent());
    check(substr_count($bothXml, '>Active<') === 2
        && str_contains($bothXml, $userId) && str_contains($bothXml, $otherUserId),
        'Second employee clock-in preserves both active rows in the same workbook');
    check($clock->clockOut($otherUserId, $second->getId()) === null, 'Second employee clock-out updates the workbook');
    $document = new DOMDocument();
    $document->loadXML(workbookSheet($automaticFile->getContent()), LIBXML_NONET);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach ([[$first, 'Active'], [$second, 'Closed']] as [$interval, $status]) {
        $row = $xpath->query('//s:row[s:c[1]/s:is/s:t="' . $interval->getId() . '"]');
        check($row->length === 1 && $xpath->query('s:c[11]/s:is/s:t', $row->item(0))->item(0)->textContent === $status,
            'Workbook row is identified by its immutable interval ID, not employee order: ' . $status);
    }
    $automaticFile->delete();
    $blockedTarget = $automaticFolder->get('Time logs')->newFolder('Time logs.xlsx');
    $warning = $clock->clockOut($userId, $first->getId());
    check($warning !== null && str_contains($warning, 'your time is saved')
        && $jobMapper->findOpenForUser($userId) === null,
        'Workbook failure reports a warning but commits clock-out');
    rejects(fn () => $clock->clockIn($userId, 'SO260200'), 400,
        'Unwritable workbook target prevents a new clock-in');
    check($jobMapper->findOpenForUser($userId) === null, 'Workbook write failure rolls back the new interval');
    $blockedTarget->delete();
    $retry = $clock->clockIn($userId, 'SO260200');
    $testJobs[] = $retry;
    $repaired = workbookSheet($automaticFolder->get('Time logs/Time logs.xlsx')->getContent());
    check(substr_count($repaired, '>Closed<') === 2 && substr_count($repaired, '>Active<') === 1,
        'Next successful clock-in rebuilds previously unsynchronized closed rows without duplicates');
    $clock->clockOut($userId, $retry->getId());
    $raceIds = concurrentClocks($nextcloudRoot, [[$userId, 'in', 0], [$otherUserId, 'in', 0]]);
    $raceXml = workbookSheet($automaticFolder->get('Time logs/Time logs.xlsx')->getContent());
    check(substr_count($raceXml, '>Active<') === 2 && substr_count($raceXml, '>Closed<') === 3,
        'Concurrent first/second employee starts preserve every workbook interval');
    concurrentClocks($nextcloudRoot, [[$otherUserId, 'out', $raceIds[1]], [$userId, 'out', $raceIds[0]]]);
    $raceClosedXml = workbookSheet($automaticFolder->get('Time logs/Time logs.xlsx')->getContent());
    check(!str_contains($raceClosedXml, '>Active<') && substr_count($raceClosedXml, '>Closed<') === 5,
        'Concurrent clock-outs close only their own rows without lost updates or duplicate rows');
    $writeFailure = new class extends \OCA\Timesheets\Service\WorkbookWriter {
        public function write(array $rows): string {
            throw new \OCP\Files\GenericFileException('Simulated workbook storage outage.');
        }
    };
    $logger = Server::get(\Psr\Log\LoggerInterface::class);
    $shiftClock = Server::get(\OCA\Timesheets\Service\ShiftClockService::class);
    $failingStorage = new StorageService($config, $rootFolder, $users, $shiftMapper, $jobMapper,
        $shiftClock, $writeFailure, $logger, Server::get(\OCA\Timesheets\Service\PayrollLocationService::class));
    $failingClock = new JobClockService($jobMapper, $shiftMapper, $shiftClock,
        Server::get(\OCA\Timesheets\Service\AccessService::class), $config,
        Server::get(\OCP\AppFramework\Utility\ITimeFactory::class),
        Server::get(\OCA\Timesheets\Service\ClockTransaction::class),
        Server::get(\OCA\Timesheets\Service\RoundingService::class), $failingStorage, $logger,
        Server::get(\OCA\Timesheets\Service\LocationService::class));
    rejects(fn () => $failingClock->clockIn($otherUserId, 'SO260200'), 409,
        'Workbook I/O exceptions prevent clock-in with an explicit administrator error');
    check($jobMapper->findOpenForUser($otherUserId) === null, 'I/O failure does not persist an active interval');
    $beforeOutage = $clock->clockIn($userId, 'SO260200');
    $warning = $failingClock->clockOut($userId, $beforeOutage->getId());
    check($warning !== null && $jobMapper->findOpenForUser($userId) === null,
        'Actual workbook I/O exception is logged and warned without blocking clock-out');
    $recover = $clock->clockIn($otherUserId, 'SO260200');
    check(substr_count(workbookSheet($automaticFolder->get('Time logs/Time logs.xlsx')->getContent()), '>Closed<') === 6,
        'Successful synchronization repairs the row left stale by an I/O outage');
    $clock->clockOut($otherUserId, $recover->getId());
} finally {
    foreach ([$userId, $otherUserId] as $accountId) {
        foreach ($jobMapper->findByUser($accountId) as $entry) {
            $jobMapper->delete($entry);
        }
    }
    foreach ([$userId, $otherUserId, 'job:' . hash('sha256', 'SO260003'), 'job:' . hash('sha256', 'SO260200')] as $lockId) {
        $query = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
        $query->delete('timesheets_clock_locks')->where($query->expr()->eq('user_id', $query->createNamedParameter($lockId)));
        $query->executeStatement();
    }
    foreach ($testShifts as $entry) {
        $shiftMapper->delete($entry);
    }
    if ($baseFolder !== null) {
        $baseFolder->delete();
    }
    foreach ([$user, $otherUser] as $entry) {
        if ($entry !== false && $entry !== null) {
            $entry->delete();
        }
    }
    foreach ($previous as $key => $value) {
        if ($value === null) {
            $config->deleteAppValue('timesheets', $key);
        } else {
            $config->setAppValue('timesheets', $key, $value);
        }
    }
    Server::get(IAppConfig::class)->clearCache();
    check(true, 'Storage settings, generated workbooks, and temporary accounts restored or removed');
}
