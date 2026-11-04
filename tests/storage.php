<?php

declare(strict_types=1);

use OCA\Timesheets\Db\Job;
use OCA\Timesheets\Db\JobMapper;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Service\StorageService;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\Server;

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
    $baseName = 'timesheets-storage-' . bin2hex(random_bytes(6));
    $baseFolder = $rootFolder->getUserFolder($userId)->newFolder($baseName);
    check($baseFolder instanceof Folder, 'Test storage root created in the account files');
    $baseFolder->newFolder('Payroll');
    $jobRoot = $baseFolder->newFolder('Sales Order');
    $yearFolder = $jobRoot->newFolder('2026');
    $firstGroup = $yearFolder->newFolder('1-99');
    $orderFolder = $firstGroup->newFolder('SO260001 - Customer');
    $storage->saveRoots($userId, $baseName . '/Payroll', $baseName . '/Sales Order');
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

    $jobReport = $storage->generateJobReport('SO260001');
    check($jobReport['count'] === 1
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
        'Job workbook includes completed exact-ID intervals and reported durations only');
    $yearFolder->newFolder('100-199')->newFolder('SO260100 - Another Customer');
    check($storage->generateJobReport('SO260100')['folder']
        === '2026/100-199/SO260100 - Another Customer/Time logs/Time logs.xlsx',
        'Sequence 100 starts the next 100-number grouping folder');
    $yearFolder->get('1-99')->newFolder('SO260099 - Last first-group Job');
    check($storage->generateJobReport('SO260099')['folder']
        === '2026/1-99/SO260099 - Last first-group Job/Time logs/Time logs.xlsx',
        'Sequence 99 remains in the first grouping folder');
    $firstGroup->newFolder('SO260001 Secondary');
    rejects(fn () => $storage->generateJobReport('SO260001'), 409,
        'Ambiguous Job ID folder prefix matches are rejected');
    rejects(fn () => $storage->generateJobReport('SO260003'), 404,
        'Missing Job folders are explicitly rejected');
    rejects(fn () => $storage->generateJobReport('NOT-A-JOB'), 400,
        'Job IDs that do not match the configured format are rejected');
    rejects(fn () => $storage->generateJobReport('SO260000'), 400,
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
    check($storage->generateJobReport('AB260012')['folder']
        === 'Jobs/1926/batch-10-to-19/AB260012 - Customer/Time logs/Time logs.xlsx',
        'Custom Job ID, folder path, century, and grouping formats support other business structures');
} finally {
    foreach ($testJobs as $entry) {
        $jobMapper->delete($entry);
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
