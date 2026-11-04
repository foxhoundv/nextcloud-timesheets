<?php

declare(strict_types=1);

use OCA\Timesheets\Settings\AdminSection;
use OCA\Timesheets\Settings\AdminSettings;
use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\Settings\IManager;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$nextcloudRoot = $argv[1] ?? '';
$baseUrl = rtrim($argv[2] ?? '', '/');
if (!is_file($nextcloudRoot . '/lib/base.php') || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "Usage: php tests/entrypoints.php /path/to/nextcloud https://testing-instance\n");
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

$settingsManager = Server::get(IManager::class);
$sections = array_merge(...array_values($settingsManager->getAdminSections()));
check(count(array_filter($sections, static fn ($section) => $section instanceof AdminSection)) === 1,
    'Timesheets admin section is registered exactly once');
$settings = array_merge(...array_values($settingsManager->getAdminSettings('timesheets')));
check(count(array_filter($settings, static fn ($setting) => $setting instanceof AdminSettings)) === 1,
    'Timesheets admin form is registered exactly once');

$config = Server::get(IConfig::class);
$keys = ['shift_tracking', 'job_tracking', 'shift_rounding', 'job_rounding', 'net_shift_threshold',
    'job_require_shift', 'shift_end_job_policy', 'shift_rounding_enabled', 'job_rounding_enabled'];
$originalValues = [];
$existingKeys = $config->getAppKeys('timesheets');
foreach ($keys as $key) {
    $originalValues[$key] = in_array($key, $existingKeys, true)
        ? $config->getAppValue('timesheets', $key)
        : null;
}

$userManager = Server::get(IUserManager::class);
$groupManager = Server::get(IGroupManager::class);
$adminGroup = $groupManager->get('admin');
check($adminGroup !== null, 'Administrator group exists');
$userId = 'timesheets-entrypoint-' . bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24));
$cookieFile = tempnam(sys_get_temp_dir(), 'timesheets-entrypoints-');
if ($cookieFile === false) {
    throw new RuntimeException('Unable to create a temporary cookie file');
}
chmod($cookieFile, 0600);
$user = null;
$settingsSaved = false;
$previousAccess = $config->getAppValue('timesheets', 'access_policy', '');
$accessSaved = false;
$accessGroup = null;
$storageFixture = null;
require_once __DIR__ . '/JobStorageFixture.php';

function request(string $path, ?array $payload = null, ?string $token = null): array {
    global $baseUrl, $cookieFile;
    $curl = curl_init($baseUrl . $path);
    $headers = ['Accept: text/html, application/json'];
    if ($payload !== null) {
        $origin = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $headers[] = 'Origin: ' . $origin . ($port !== null ? ':' . $port : '');
    }
    if ($token !== null) {
        $headers[] = 'requesttoken: ' . $token;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($payload));
    }
    try {
        $body = curl_exec($curl);
        if ($body === false) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
        }
        return [
            'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'body' => $body,
            'redirect' => parse_url((string) curl_getinfo($curl, CURLINFO_REDIRECT_URL), PHP_URL_PATH),
        ];
    } finally {
        curl_close($curl);
    }
}

function tokenFrom(string $body): string {
    if (!preg_match('/data-requesttoken="([^"]+)"/', $body, $matches)) {
        throw new RuntimeException('Nextcloud page did not include a request token');
    }
    return html_entity_decode($matches[1], ENT_QUOTES);
}

function login(string $userId, string $password): void {
    $page = request('/index.php/login');
    check($page['status'] === 200, 'Login page is accessible');
    $response = request('/index.php/login', [
        'user' => $userId,
        'password' => $password,
        'requesttoken' => tokenFrom($page['body']),
    ]);
    check(($response['status'] === 303 || $response['status'] === 302)
        && !str_ends_with($response['redirect'] ?? '', '/login'),
        'Test account signs in over HTTP');
}

function pageDocument(string $body): DOMDocument {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR | LIBXML_NOWARNING);
    return $document;
}

function setTrackingOverHttp(bool $enabled, string $token, ?string $threshold = null): void {
    global $adminGroup, $user, $originalValues;
    $adminGroup->addUser($user);
    try {
        $response = request('/index.php/apps/timesheets/settings', [
            'shift_tracking' => $enabled ? '1' : '0',
            'job_tracking' => $originalValues['job_tracking'] ?? '0',
            'shift_rounding' => $originalValues['shift_rounding'] ?? '15',
            'job_rounding' => $originalValues['job_rounding'] ?? '15',
            'net_shift_threshold' => $threshold ?? $originalValues['net_shift_threshold'] ?? '0',
        ], $token);
        check($response['status'] === 200, 'Tracking test preference saved through the web application');
    } finally {
        $adminGroup->removeUser($user);
    }
}

$shiftMapper = new ShiftMapper(Server::get(IDBConnection::class));
$testShifts = [];
try {
    $user = $userManager->createUser($userId, $password);
    check($user !== false, 'Temporary regular account was created');
    $storageFixture = new JobStorageFixture($userId, ['SO0001']);
    check(!$groupManager->isAdmin($userId), 'Test account is not an administrator');
    $anonymous = request('/index.php/apps/timesheets/');
    check(in_array($anonymous['status'], [302, 303], true)
        && str_ends_with($anonymous['redirect'] ?? '', '/login'),
        'Dashboard requires authentication');
    login($userId, $password);
    $accessPage = request('/index.php/apps/files/');
    $accessToken = tokenFrom($accessPage['body']);
    $adminGroup->addUser($user);
    try {
        $accessSaved = true;
        $response = request('/index.php/apps/timesheets/settings/access',
            ['policy' => '{"restricted":false,"grants":[]}'], $accessToken);
        check($response['status'] === 200, 'Access test starts in unrestricted mode');
    } finally {
        $adminGroup->removeUser($user);
    }
    $dashboard = request('/index.php/apps/timesheets/');
    check($dashboard['status'] === 200 && str_contains($dashboard['body'], 'Timesheets dashboard'),
        'Regular account can open the Timesheets dashboard (HTTP ' . $dashboard['status']
        . ', redirect ' . ($dashboard['redirect'] ?? 'none') . ')');
    check(str_contains($dashboard['body'], 'No shifts recorded yet')
        && !str_contains($dashboard['body'], 'Waiting for clock-ins'),
        'Empty dashboard explains the absence of records without a sample shift');
    check(str_contains($dashboard['body'], 'Your current shift')
        && !str_contains($dashboard['body'], 'Quick summary'),
        'Dashboard shows current shift controls instead of placeholder settings');
    check(str_contains($dashboard['body'], 'Clock out when your break starts, then clock in again when you return.')
        && str_contains($dashboard['body'], 'time between shifts is excluded')
        && str_contains($dashboard['body'], 'There is no paused shift or automatic break deduction.'),
        'Dashboard explains separate-shift breaks and excluded gaps without suggesting a pause timer');
    foreach ([
        ['2026-10-01', '2026-10-01 08:00:00', 'closed', 125],
        ['2026-10-02', '2026-10-02 08:00:00', 'open', 60],
    ] as [$date, $clockIn, $status, $duration]) {
        $shift = new Shift();
        $shift->setUserId($userId);
        $shift->setShiftDate($date);
        $shift->setClockIn($clockIn);
        $shift->setStatus($status);
        $shift->setDurationMinutes($duration);
        $testShifts[] = $shiftMapper->insert($shift);
    }
    $history = request('/index.php/apps/timesheets/');
    check($history['status'] === 200, 'Populated dashboard is accessible');
    $historyDocument = pageDocument($history['body']);
    $historyPath = new DOMXPath($historyDocument);
    $values = $historyPath->query('//*[@id="timesheets"]//*[contains(concat(" ", normalize-space(@class), " "), " timesheets-stat-value ")]');
    check($values->length === 8 && trim($values->item(0)->textContent) === '2h 05m'
        && trim($values->item(1)->textContent) === '1' && trim($values->item(2)->textContent) === '1',
        'Overview shows all-date completed time and completed/open counts without including open durations');
    check($historyPath->query('//*[@id="timesheets-history"]//tbody/tr')->length === 2,
        'History shows one row per saved shift');
    check(str_contains($history['body'], 'Not finalized') && !str_contains($history['body'], '2.05h'),
        'Open durations are not finalized and closed durations use hours and minutes');
    check(trim($values->item(3)->textContent) === '0h 00m'
        && trim($values->item(4)->textContent) === '0h 00m'
        && str_contains($history['body'], 'lack recorded start or end times')
        && str_contains($history['body'], 'Unavailable'),
        'Legacy shift without endpoints retains saved time but explicitly lacks a job/shop breakdown');
    $testShifts[0]->setClockOut('2026-10-01 10:05:00');
    $testShifts[0]->setDurationSeconds(7500);
    $shiftMapper->update($testShifts[0]);
    $historicalJob = new \OCA\Timesheets\Db\Job();
    $historicalJob->setUserId($userId);
    $historicalJob->setJobReference('SO-overlap');
    $historicalJob->setJobDate('2026-10-01');
    $historicalJob->setTimeZone('UTC');
    $historicalJob->setClockIn('2026-10-01 09:00:15');
    $historicalJob->setClockOut('2026-10-01 09:30:45');
    $historicalJob->setDurationSeconds(1830);
    Server::get(\OCA\Timesheets\Db\JobMapper::class)->insert($historicalJob);
    $withBreakdown = request('/index.php/apps/timesheets/');
    $breakdownPath = new DOMXPath(pageDocument($withBreakdown['body']));
    $closedRow = $breakdownPath->query('//*[@id="timesheets-history"]//tbody/tr')->item(1);
    check($withBreakdown['status'] === 200
        && trim($breakdownPath->query('./td', $closedRow)->item(4)->textContent) === '0h 30m'
        && trim($breakdownPath->query('./td', $closedRow)->item(5)->textContent) === '1h 34m'
        && !str_contains($withBreakdown['body'], 'lack recorded start or end times'),
        'HTTP dashboard shows exact-second job/shop projections with no stale legacy warning');
    check(preg_match('/id="initial-state-core-apps" value="([^"]+)"/', $dashboard['body'], $matches) === 1,
        'Dashboard includes app-menu state');
    $apps = json_decode(base64_decode(html_entity_decode($matches[1], ENT_QUOTES), true), true, 512, JSON_THROW_ON_ERROR);
    $entries = array_values(array_filter($apps, static fn ($app) => $app['id'] === 'timesheets'));
    check(count($entries) === 1 && str_contains($entries[0]['href'], '/apps/timesheets'),
        'Regular account sees one Timesheets app-menu entry linked to the dashboard');
    $icon = request(parse_url($entries[0]['icon'], PHP_URL_PATH));
    check($icon['status'] === 200 && str_contains($icon['body'], '<svg'), 'Timesheets menu icon is accessible');
    $denied = request('/index.php/apps/timesheets/admin');
    check($denied['status'] === 403,
        'Regular users cannot open the delegated Timesheets administration page (HTTP ' . $denied['status'] . ')');
    $token = tokenFrom($dashboard['body']);
    $denied = request('/index.php/settings/admin/timesheets');
    check($denied['status'] === 403, 'Regular account cannot open administration settings');
    $denied = request('/index.php/apps/timesheets/settings', [], $token);
    check($denied['status'] === 403, 'Regular account cannot save administration settings');
    $storageDenied = request('/index.php/apps/timesheets/settings/storage', [
        'payrollRoot' => 'payroll',
        'jobRoot' => 'sales-orders',
    ], $token);
    check($storageDenied['status'] === 403,
        'Regular accounts cannot configure Timesheets storage (HTTP ' . $storageDenied['status'] . ': '
            . substr($storageDenied['body'], 0, 300) . ')');

    $settingsSaved = true;
    setTrackingOverHttp(true, $token);
    $clockInPath = '/index.php/apps/timesheets/api/v1/shifts/clock-in';
    $clockOutPath = '/index.php/apps/timesheets/api/v1/shifts/clock-out';
    $denied = request($clockInPath, []);
    check($denied['status'] === 412, 'Clock-in requires a CSRF token (HTTP ' . $denied['status'] . ')');
    $started = request($clockInPath, ['userId' => 'someone-else', 'clockIn' => '2000-01-01'], $token);
    check($started['status'] === 200, 'Regular account can clock in with tracking enabled (HTTP ' . $started['status'] . ')');
    $startedData = json_decode($started['body'], true, 512, JSON_THROW_ON_ERROR);
    check(($startedData['longShiftConfirmation'] ?? true) === false,
        'Clock-in response does not request a long-shift reminder');
    $active = $shiftMapper->findOpenForUser($userId);
    check($active !== null && $active->getUserId() === $userId
        && str_starts_with($active->getClockIn(), gmdate('Y-m-d')),
        'Clock-in uses the authenticated account and server time, not submitted values');
    $testShifts[] = $active;
    $duplicate = request($clockInPath, [], $token);
    check($duplicate['status'] === 409, 'Duplicate clock-in is rejected');
    $running = request('/index.php/apps/timesheets/');
    check($running['status'] === 200 && str_contains($running['body'], 'id="timesheets-elapsed"')
        && str_contains($running['body'], 'name="shiftId"')
        && str_contains($running['body'], 'id="timesheets-long-shift-confirmation"')
        && str_contains($running['body'], 'data-dismiss-long-shift'),
        'Dashboard has the hidden dismissible long-shift reminder and clock-out form');
    $denied = request($clockOutPath, ['shiftId' => $active->getId()]);
    check($denied['status'] === 412, 'Clock-out requires a CSRF token');
    $stale = request($clockOutPath, ['shiftId' => $active->getId() + 1], $token);
    check($stale['status'] === 409, 'Clock-out rejects another or stale shift ID');
    $active->setClockIn(gmdate('Y-m-d H:i:s', time() - 120));
    $shiftMapper->update($active);
    setTrackingOverHttp(false, $token, '1');
    $denied = request($clockInPath, [], $token);
    check($denied['status'] === 403, 'Disabled tracking rejects new clock-ins on the server');
    $stopped = request($clockOutPath, ['shiftId' => $active->getId()], $token);
    $stoppedData = json_decode($stopped['body'], true, 512, JSON_THROW_ON_ERROR);
    check($stopped['status'] === 200 && $shiftMapper->findOpenForUser($userId) === null
        && ($stoppedData['longShiftConfirmation'] ?? false) === true,
        'Clock-out still succeeds while tracking is disabled and returns a threshold reminder');
    $duplicate = request($clockOutPath, ['shiftId' => $active->getId()], $token);
    check($duplicate['status'] === 409, 'Duplicate clock-out is rejected');
    setTrackingOverHttp(true, $token, '0');
    $shortStarted = request($clockInPath, [], $token);
    check($shortStarted['status'] === 200, 'Clock-in succeeds when threshold reminders are disabled');
    $shortShift = $shiftMapper->findOpenForUser($userId);
    $testShifts[] = $shortShift;
    $shortStopped = request($clockOutPath, ['shiftId' => $shortShift->getId()], $token);
    $shortData = json_decode($shortStopped['body'], true, 512, JSON_THROW_ON_ERROR);
    check($shortStopped['status'] === 200 && ($shortData['longShiftConfirmation'] ?? true) === false,
        'Successful clock-out returns no reminder when threshold is zero');

    $adminGroup->addUser($user);
    check($groupManager->isAdmin($userId), 'Temporary account is promoted for admin-only checks');
    $admin = request('/index.php/settings/admin/timesheets');
    check($admin['status'] === 200 && str_contains($admin['body'], 'timesheets-admin-form'),
        'Administrator can open Timesheets administration settings (HTTP ' . $admin['status'] . ')');
    check(str_contains($admin['body'], 'timesheets-storage-form')
        && str_contains($admin['body'], 'timesheets-payroll-report-form')
        && !str_contains($admin['body'], 'timesheets-job-report-form')
        && substr_count($admin['body'], 'data-folder-picker=') === 2
        && str_contains($admin['body'], 'Job ID format')
        && str_contains($admin['body'], 'Century for two-digit years')
        && str_contains($admin['body'], 'SO{sequence:4}')
        && str_contains($admin['body'], 'name="payrollWeekStart"'),
        'Administration includes shared storage configuration and on-demand workbook forms');
    check(request('/index.php/apps/timesheets/settings/storage', [
        'payrollRoot' => '../invalid',
        'jobRoot' => '../invalid',
    ], $token)['status'] === 400,
        'Storage root save rejects unsafe relative paths without changing settings');
    $storagePayload = Server::get(\OCA\Timesheets\Service\StorageService::class)->settings();
    unset($storagePayload['owner']);
    $storagePayload['payrollWeekStart'] = '7';
    check(request('/index.php/apps/timesheets/settings/storage', $storagePayload)['status'] === 412,
        'Payroll workweek configuration requires CSRF protection');
    check(request('/index.php/apps/timesheets/settings/storage', $storagePayload, $token)['status'] === 200,
        'Administrator can save a Sunday-through-Saturday payroll week over HTTP');
    $weekPage = request('/index.php/settings/admin/timesheets');
    $weekPath = new DOMXPath(pageDocument($weekPage['body']));
    check($weekPath->query('//select[@id="payroll_week_start"]/option[@selected and @value="7"]')->length === 1,
        'Reloaded settings retain the saved workweek and show its corresponding end weekday');
    check(str_contains($admin['body'], 'timesheets/js/admin.js'), 'Settings page loads the external save script');
    check(str_contains($admin['body'], 'timesheets-location-settings')
        && str_contains($admin['body'], 'Do you need to track multiple countries?')
        && str_contains($admin['body'], 'states / provinces / regions'),
        'Administration offers optional country and region tracking');
    $locationPath = '/index.php/apps/timesheets/settings/locations';
    $locationSettings = [
        'shiftEnabled' => true, 'jobEnabled' => true, 'multipleCountries' => false,
        'multipleRegions' => true, 'workingCountry' => 'US', 'countries' => [],
        'regions' => ['US' => ['Oregon', 'Idaho']], 'defaultRegion' => 'Oregon',
    ];
    $locationPayload = ['settings' => json_encode($locationSettings, JSON_THROW_ON_ERROR)];
    check(request($locationPath, $locationPayload)['status'] === 412, 'Location settings require CSRF protection');
    check(request($locationPath, $locationPayload, $token)['status'] === 200, 'Administrator can save Oregon and Idaho location settings');
    $locationAdmin = request('/index.php/settings/admin/timesheets');
    $locationXPath = new DOMXPath(pageDocument($locationAdmin['body']));
    check($locationXPath->query('//*[@data-location-setting="shiftEnabled" and @checked]')->length === 1
        && str_contains($locationAdmin['body'], 'Oregon'),
        'Location settings survive an administration-page reload');
    $locationDashboard = request('/index.php/apps/timesheets/');
    $locationXPath = new DOMXPath(pageDocument($locationDashboard['body']));
    check($locationXPath->query('//*[@id="timesheets-clock-form"]//*[@data-work-location]')->length === 1
        && $locationXPath->query('//*[@id="timesheets-job-form"]//*[@data-work-location]')->length === 1,
        'Enabled shift and Job forms both offer configured location choices');
    check(request($clockInPath, ['country' => 'US', 'region' => 'Washington'], $token)['status'] === 400,
        'HTTP clock-in rejects a state not selected by the administrator');
    check(request($clockInPath, ['country' => 'US', 'region' => 'Oregon'], $token)['status'] === 200,
        'HTTP shift clock-in captures the selected work state');
    $locationShift = $shiftMapper->findOpenForUser($userId);
    $testShifts[] = $locationShift;
    check($locationShift !== null && \OCA\Timesheets\Service\LocationService::decode($locationShift->getWorkLocation())['region'] === 'Oregon',
        'HTTP location selection is persisted on the shift');
    check(request($clockOutPath, ['shiftId' => $locationShift->getId()], $token)['status'] === 200,
        'A location-tagged shift can be ended normally');
    $adminGroup->removeUser($user);
    check(request($locationPath, $locationPayload, $token)['status'] === 403,
        'Regular users cannot change country and region configuration');
    $adminGroup->addUser($user);
    $locationSettings['shiftEnabled'] = false;
    $locationSettings['jobEnabled'] = false;
    check(request($locationPath, ['settings' => json_encode($locationSettings, JSON_THROW_ON_ERROR)], $token)['status'] === 200,
        'Administrator can disable optional location tracking without altering history');
    check(str_contains($admin['body'], 'role="tablist"') && str_contains($admin['body'], 'timesheets-tab-group')
        && str_contains($admin['body'], 'timesheets-tab-user') && str_contains($admin['body'], 'Groups/Users With Access'),
        'Administration renders group/user tabs and the selected access area');
    check(str_contains($admin['body'], 'value="' . $userId . '"')
        && !str_contains($admin['body'], 'timesheets-principal-query'),
        'Administration auto-populates account choices without a search request');
    check(str_contains($admin['body'], 'Users take unpaid breaks by clocking out and starting a new shift')
        && str_contains($admin['body'], 'There is no separate break switch or automatic deduction.'),
        'Administration explains unpaid breaks without an additional enable switch');
    check(!str_contains($admin['body'], "const form = document.getElementById('timesheets-admin-form')"),
        'Settings page does not rely on a CSP-blocked inline script');
    $adminDocument = pageDocument($admin['body']);
    $adminPath = new DOMXPath($adminDocument);
    check(trim($adminPath->query('//*[@id="timesheets-admin"]/h2')->item(0)->textContent) === 'Timesheet Administration',
        'Administration page has the requested title');
    check($adminPath->query('//*[@id="timesheets-admin-form"]/fieldset')->length === 4,
        'Settings include combined tracking, rounding, and shift review');
    foreach ($keys as $key) {
        $control = $adminDocument->getElementById($key);
        $help = $control === null ? null : $adminDocument->getElementById($control->getAttribute('aria-describedby'));
        check($help !== null && trim($help->textContent) !== ''
            && $adminPath->query('//label[@for="' . $key . '"]')->length === 1,
            'Setting has an associated label and description: ' . $key);
    }
    $token = tokenFrom($admin['body']);
    $payload = [
        'shift_tracking' => '1',
        'job_tracking' => '1',
        'shift_rounding' => '5',
        'job_rounding' => '30',
        'net_shift_threshold' => '480',
        'job_require_shift' => '0',
        'shift_end_job_policy' => 'block',
        'shift_rounding_enabled' => '1',
        'job_rounding_enabled' => '0',
    ];
    $denied = request('/index.php/apps/timesheets/settings', $payload);
    check($denied['status'] === 412, 'Settings save rejects requests without a CSRF token');
    $saved = request('/index.php/apps/timesheets/settings', $payload, $token);
    $data = json_decode($saved['body'], true, 512, JSON_THROW_ON_ERROR);
    check($saved['status'] === 200 && ($data['status'] ?? '') === 'ok',
        'Administrator can save settings using a CSRF token');
    $query = Server::get(IDBConnection::class)->getQueryBuilder();
    $query->select('configkey', 'configvalue')
        ->from('appconfig')
        ->where($query->expr()->eq('appid', $query->createNamedParameter('timesheets')));
    $result = $query->executeQuery();
    $storedValues = [];
    while ($row = $result->fetch()) {
        $storedValues[$row['configkey']] = $row['configvalue'];
    }
    $result->closeCursor();
    foreach ($payload as $key => $value) {
        check(($storedValues[$key] ?? null) === $value, 'Saved setting persists: ' . $key);
    }
    check(request('/index.php/apps/timesheets/settings', ['shift_end_job_policy' => 'auto-close'], $token)['status'] === 400,
        'Unknown shift/job policy is explicitly rejected');
    check(request('/index.php/apps/timesheets/settings', ['job_require_shift' => 'invalid'], $token)['status'] === 400,
        'Invalid shift requirement is explicitly rejected');
    foreach ([
        ['shift_rounding' => '12', 'shift_tracking' => '0'],
        ['job_rounding' => '0'],
        ['shift_rounding_enabled' => 'yes'],
        ['job_rounding_enabled' => '2'],
        ['net_shift_threshold' => '-1', 'shift_tracking' => '0'],
        ['net_shift_threshold' => '1.5'],
    ] as $invalid) {
        check(request('/index.php/apps/timesheets/settings', $invalid, $token)['status'] === 400,
            'Malformed rounding preference is explicitly rejected before any settings are saved');
    }
    $canonicalThreshold = request('/index.php/apps/timesheets/settings', [
        'shift_tracking' => $config->getAppValue('timesheets', 'shift_tracking', '0'),
        'job_tracking' => $config->getAppValue('timesheets', 'job_tracking', '0'),
        'shift_rounding' => $config->getAppValue('timesheets', 'shift_rounding', '15'),
        'job_rounding' => $config->getAppValue('timesheets', 'job_rounding', '15'),
        'net_shift_threshold' => '001',
    ], $token);
    Server::get(IAppConfig::class)->clearCache();
    check($canonicalThreshold['status'] === 200
        && $config->getAppValue('timesheets', 'net_shift_threshold') === '1',
        'Threshold accepts a nonnegative integer with leading zeros and stores its canonical value');
    check(request('/index.php/apps/timesheets/settings', [
        'shift_tracking' => '1', 'job_tracking' => '1', 'shift_rounding' => '5', 'job_rounding' => '30',
    ], $token)['status'] === 200, 'Older settings payload can omit rounding enable switches');
    $roundingAdmin = request('/index.php/settings/admin/timesheets');
    $roundingPath = new DOMXPath(pageDocument($roundingAdmin['body']));
    check($roundingPath->query('//select[@id="shift_rounding_enabled"]/option[@selected and @value="1"]')->length === 1
        && $roundingPath->query('//select[@id="job_rounding_enabled"]/option[@selected and @value="0"]')->length === 1,
        'Omitted rounding switches preserve independently saved enable states');
    $jobInPath = '/index.php/apps/timesheets/api/v1/jobs/clock-in';
    $jobOutPath = '/index.php/apps/timesheets/api/v1/jobs/clock-out';
    $jobMapper = Server::get(\OCA\Timesheets\Db\JobMapper::class);
    $adminGroup->removeUser($user);
    check(request($jobInPath, ['reference' => 'SO-HTTP'])['status'] === 412, 'Job clock-in requires CSRF protection');
    $missingJob = request($jobInPath, ['reference' => 'SO0002'], $token);
    check($missingJob['status'] === 404 && str_contains($missingJob['body'], 'Contact your administrator')
        && $jobMapper->findOpenForUser($userId) === null,
        'Missing Job folder blocks HTTP clock-in with an administrator prompt');
    check(request($jobInPath, ['reference' => 'SO0001', 'userId' => 'someone-else',
        'clockIn' => '2000-01-01'], $token)['status'] === 200, 'Regular user can start an independent job');
    $httpJob = $jobMapper->findOpenForUser($userId);
    check($httpJob !== null && $httpJob->getUserId() === $userId
        && str_starts_with($httpJob->getClockIn(), gmdate('Y-m-d')),
        'Job identity and timestamp come from the server');
    check(request($jobInPath, ['reference' => 'SO-duplicate'], $token)['status'] === 409,
        'HTTP rejects duplicate active jobs');
    $jobPage = request('/index.php/apps/timesheets/');
    check($jobPage['status'] === 200 && str_contains($jobPage['body'], 'SO0001')
        && str_contains($jobPage['body'], '>End job</button>'), 'Dashboard shows the configured Job ID and active job controls');
    check(request($jobOutPath, ['jobId' => $httpJob->getId()])['status'] === 412, 'Job clock-out requires CSRF protection');
    check(request($jobOutPath, ['jobId' => $httpJob->getId() + 1], $token)['status'] === 409,
        'HTTP rejects stale job interval IDs');
    $jobRoot = Server::get(\OCA\Timesheets\Service\StorageService::class)->settings()['jobRoot'];
    $logs = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($userId)->get($jobRoot . '/SO0001 - Test/Time logs');
    check($logs->nodeExists('Time logs.xlsx'), 'HTTP clock-in automatically creates the Job workbook');
    $logs->get('Time logs.xlsx')->delete();
    $logs->newFolder('Time logs.xlsx');
    $clockOutResponse = request($jobOutPath, ['jobId' => $httpJob->getId()], $token);
    $clockOutData = json_decode($clockOutResponse['body'], true, 512, JSON_THROW_ON_ERROR);
    check($clockOutResponse['status'] === 200 && $clockOutData['status'] === 'ok'
        && str_contains($clockOutData['warning'] ?? '', 'your time is saved')
        && $jobMapper->findOpenForUser($userId) === null,
        'HTTP clock-out saves the specific interval and returns a storage warning instead of trapping the timer');
    check(request($jobOutPath, ['jobId' => $httpJob->getId()], $token)['status'] === 409,
        'HTTP rejects repeated job clock-out');
    $adminGroup->addUser($user);
    $accessPath = '/index.php/apps/timesheets/settings/access';
    $searchPath = $accessPath . '/search?type=user&query=' . rawurlencode($userId);
    $adminGroup->removeUser($user);
    check(request($accessPath, ['policy' => '{"restricted":true,"grants":[]}'], $token)['status'] === 403,
        'Regular users cannot change access policy');
    check(request($searchPath, null, $token)['status'] === 403,
        'Regular users cannot search the user directory');
    $adminGroup->addUser($user);
    check(request($accessPath, ['policy' => '{"restricted":true,"grants":[]}'])['status'] === 412,
        'Access policy changes require CSRF protection');
    check(request($accessPath, ['policy' => 'not-json'], $token)['status'] === 400,
        'Malformed access policy is rejected');
    check(request($searchPath, null, $token)['status'] === 200,
        'Administrators can search for assignment targets');
    $delegatedPolicy = ['restricted' => true, 'grants' => [
        ['type' => 'user', 'id' => $userId, 'shift' => false, 'job' => false, 'admin' => true],
    ]];
    check(request($accessPath, ['policy' => json_encode($delegatedPolicy, JSON_THROW_ON_ERROR)], $token)['status'] === 200,
        'Nextcloud administrator can assign delegated Timesheets administration');
    $adminGroup->removeUser($user);
    $delegatedPage = request('/index.php/apps/timesheets/admin');
    check($delegatedPage['status'] === 200 && str_contains($delegatedPage['body'], 'timesheets-admin-form')
        && str_contains($delegatedPage['body'], 'delegated administrator assignments are read-only'),
        'Delegated administrator can open the Timesheets settings page with delegation controls read-only');
    $delegatedDashboard = request('/index.php/apps/timesheets/');
    check($delegatedDashboard['status'] === 200
        && str_contains($delegatedDashboard['body'], 'Timesheet Administration'),
        'Delegated administrator sees the settings link on the dashboard');
    $delegatedSettings = request('/index.php/apps/timesheets/settings', [
        'shift_tracking' => '1',
        'job_tracking' => '1',
        'shift_rounding' => '5',
        'job_rounding' => '30',
        'net_shift_threshold' => '1',
        'job_require_shift' => '0',
        'shift_end_job_policy' => 'block',
        'shift_rounding_enabled' => '0',
        'job_rounding_enabled' => '0',
    ], $token);
    check($delegatedSettings['status'] === 200,
        'Delegated administrator can save Timesheets settings');
    check(request($locationPath, ['settings' => json_encode($locationSettings, JSON_THROW_ON_ERROR)], $token)['status'] === 200,
        'Delegated administrator can configure optional work locations');
    check(request($searchPath, null, $token)['status'] === 200,
        'Delegated administrator can search users for tracking assignments');
    $delegatedPolicy['grants'][0]['shift'] = true;
    check(request($accessPath, ['policy' => json_encode($delegatedPolicy, JSON_THROW_ON_ERROR)], $token)['status'] === 200,
        'Delegated administrator can change tracking access while retaining admin assignments');
    $savedDelegatedPolicy = $config->getAppValue('timesheets', 'access_policy');
    $delegatedPolicy['grants'] = [];
    check(request($accessPath, ['policy' => json_encode($delegatedPolicy, JSON_THROW_ON_ERROR)], $token)['status'] === 403
        && $config->getAppValue('timesheets', 'access_policy') === $savedDelegatedPolicy,
        'Delegated administrator cannot remove delegated administrator assignments');
    $adminGroup->addUser($user);
    $accessGroup = $groupManager->createGroup('timesheets-access-' . bin2hex(random_bytes(6)));
    check($accessGroup !== false, 'Temporary access test group created');
    $accessGroup->addUser($user);
    $policy = ['restricted' => true, 'grants' => [
        ['type' => 'group', 'id' => $accessGroup->getGID(), 'shift' => true, 'job' => false],
        ['type' => 'user', 'id' => $userId, 'shift' => false, 'job' => true],
    ]];
    check(request($accessPath, ['policy' => json_encode($policy, JSON_THROW_ON_ERROR)], $token)['status'] === 200,
        'Administrator can save user and group assignments');
    $adminGroup->removeUser($user);
    $permitted = request('/index.php/apps/timesheets/');
    check($permitted['status'] === 200 && str_contains($permitted['body'], '>Clock in</button>'),
        'Group assignment grants shift tracking to a regular user');
    check(request($clockInPath, [], $token)['status'] === 200,
        'Group-granted regular user can clock in');
    $assignedShift = $shiftMapper->findOpenForUser($userId);
    check($assignedShift !== null, 'Assigned shift is persisted');
    $testShifts[] = $assignedShift;
    $adminGroup->addUser($user);
    check(request($accessPath, ['policy' => '{"restricted":true,"grants":[]}'], $token)['status'] === 200,
        'Administrator can revoke all assignments without deleting time logs');
    $adminGroup->removeUser($user);
    $revoked = request('/index.php/apps/timesheets/');
    check($revoked['status'] === 200 && str_contains($revoked['body'], '>Clock out</button>')
        && str_contains($revoked['body'], '2026-10-01'), 'Revoked user retains history and active clock-out control');
    check(request($clockInPath, [], $token)['status'] === 403,
        'Revocation prevents new clock-ins through the API');
    check(request($clockOutPath, ['shiftId' => $assignedShift->getId()], $token)['status'] === 200,
        'Revoked user can finish the existing shift');
    $revoked = request('/index.php/apps/timesheets/');
    check($revoked['status'] === 200 && !str_contains($revoked['body'], '>Clock in</button>')
        && str_contains($revoked['body'], 'do not have permission'), 'Revoked history remains read-only after clock-out');
    $adminGroup->addUser($user);
    $adminAccess = request('/index.php/settings/admin/timesheets');
    check($adminAccess['status'] === 200 && str_contains($adminAccess['body'], 'Save access settings'),
        'Nextcloud administrators retain settings access without an assignment');
    check(request('/index.php/apps/timesheets/admin')['status'] === 200,
        'Nextcloud administrators retain access to the app settings page');
} finally {
    if ($storageFixture !== null) {
        $storageFixture->cleanup();
    }
    foreach (Server::get(\OCA\Timesheets\Db\JobMapper::class)->findByUser($userId) as $entry) {
        Server::get(\OCA\Timesheets\Db\JobMapper::class)->delete($entry);
    }
    $cleanup = Server::get(IDBConnection::class)->getQueryBuilder();
    $cleanup->delete('timesheets_clock_locks')->where($cleanup->expr()->eq('user_id', $cleanup->createNamedParameter($userId)));
    $cleanup->executeStatement();
    if ($accessSaved && $user !== null && $user !== false) {
        $adminGroup->addUser($user);
        $restored = request('/index.php/apps/timesheets/settings/access', [
            'policy' => $previousAccess === '' ? '{"restricted":false,"grants":[]}' : $previousAccess,
        ], $accessToken);
        if ($restored['status'] !== 200) {
            throw new RuntimeException('Failed to restore original access policy through HTTP');
        }
        Server::get(IAppConfig::class)->clearCache();
        if ($previousAccess === '') {
            $config->deleteAppValue('timesheets', 'access_policy');
        }
    }
    if ($accessGroup !== null && $accessGroup !== false && !$accessGroup->delete()) {
        throw new RuntimeException('Failed to remove temporary access group');
    }
    foreach ($testShifts as $shift) {
        $shiftMapper->delete($shift);
    }
    if ($settingsSaved) {
        Server::get(IAppConfig::class)->clearCache();
        foreach ($originalValues as $key => $value) {
            if ($value === null) {
                $config->deleteAppValue('timesheets', $key);
            } else {
                $config->setAppValue('timesheets', $key, $value);
            }
        }
    }
    if ($user !== null && $user !== false && !$user->delete()) {
        throw new RuntimeException('Failed to remove the temporary test account');
    }
    if (!unlink($cookieFile)) {
        throw new RuntimeException('Failed to remove the temporary cookie file');
    }
}

Server::get(IAppConfig::class)->clearCache();
foreach ($originalValues as $key => $value) {
    check($value === null
        ? !in_array($key, $config->getAppKeys('timesheets'), true)
        : $config->getAppValue('timesheets', $key) === $value,
        'Original setting restored: ' . $key);
}
check(!$userManager->userExists($userId), 'Temporary test account was deleted');
check($config->getAppValue('timesheets', 'access_policy', '') === $previousAccess,
    'Original access policy restored');
echo "Entry point checks passed; temporary account and cookies removed, original settings restored.\n";
