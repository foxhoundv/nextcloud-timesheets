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
$keys = ['shift_tracking', 'job_tracking', 'shift_rounding', 'job_rounding', 'net_shift_threshold'];
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

function setTrackingOverHttp(bool $enabled, string $token): void {
    global $adminGroup, $user, $originalValues;
    $adminGroup->addUser($user);
    try {
        $response = request('/index.php/apps/timesheets/settings', [
            'shift_tracking' => $enabled ? '1' : '0',
            'job_tracking' => $originalValues['job_tracking'] ?? '0',
            'shift_rounding' => $originalValues['shift_rounding'] ?? '15',
            'job_rounding' => $originalValues['job_rounding'] ?? '15',
            'net_shift_threshold' => $originalValues['net_shift_threshold'] ?? '0',
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
    check(!$groupManager->isAdmin($userId), 'Test account is not an administrator');
    $anonymous = request('/index.php/apps/timesheets/');
    check(in_array($anonymous['status'], [302, 303], true)
        && str_ends_with($anonymous['redirect'] ?? '', '/login'),
        'Dashboard requires authentication');
    login($userId, $password);
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
    check($values->length === 3 && trim($values->item(0)->textContent) === '2h 05m'
        && trim($values->item(1)->textContent) === '1' && trim($values->item(2)->textContent) === '1',
        'Overview shows all-date completed time and completed/open counts without including open durations');
    check($historyPath->query('//*[@id="timesheets-history"]//tbody/tr')->length === 2,
        'History shows one row per saved shift');
    check(str_contains($history['body'], 'Not finalized') && !str_contains($history['body'], '2.05h'),
        'Open durations are not finalized and closed durations use hours and minutes');
    check(preg_match('/id="initial-state-core-apps" value="([^"]+)"/', $dashboard['body'], $matches) === 1,
        'Dashboard includes app-menu state');
    $apps = json_decode(base64_decode(html_entity_decode($matches[1], ENT_QUOTES), true), true, 512, JSON_THROW_ON_ERROR);
    $entries = array_values(array_filter($apps, static fn ($app) => $app['id'] === 'timesheets'));
    check(count($entries) === 1 && str_contains($entries[0]['href'], '/apps/timesheets'),
        'Regular account sees one Timesheets app-menu entry linked to the dashboard');
    $icon = request(parse_url($entries[0]['icon'], PHP_URL_PATH));
    check($icon['status'] === 200 && str_contains($icon['body'], '<svg'), 'Timesheets menu icon is accessible');
    $token = tokenFrom($dashboard['body']);
    $denied = request('/index.php/settings/admin/timesheets');
    check($denied['status'] === 403, 'Regular account cannot open administration settings');
    $denied = request('/index.php/apps/timesheets/settings', [], $token);
    check($denied['status'] === 403, 'Regular account cannot save administration settings');

    $settingsSaved = true;
    setTrackingOverHttp(true, $token);
    $clockInPath = '/index.php/apps/timesheets/api/v1/shifts/clock-in';
    $clockOutPath = '/index.php/apps/timesheets/api/v1/shifts/clock-out';
    $denied = request($clockInPath, []);
    check($denied['status'] === 412, 'Clock-in requires a CSRF token (HTTP ' . $denied['status'] . ')');
    $started = request($clockInPath, ['userId' => 'someone-else', 'clockIn' => '2000-01-01'], $token);
    check($started['status'] === 200, 'Regular account can clock in with tracking enabled (HTTP ' . $started['status'] . ')');
    $active = $shiftMapper->findOpenForUser($userId);
    check($active !== null && $active->getUserId() === $userId
        && str_starts_with($active->getClockIn(), gmdate('Y-m-d')),
        'Clock-in uses the authenticated account and server time, not submitted values');
    $testShifts[] = $active;
    $duplicate = request($clockInPath, [], $token);
    check($duplicate['status'] === 409, 'Duplicate clock-in is rejected');
    $running = request('/index.php/apps/timesheets/');
    check($running['status'] === 200 && str_contains($running['body'], 'id="timesheets-elapsed"')
        && str_contains($running['body'], 'name="shiftId"'), 'Dashboard shows the active shift and clock-out form');
    $denied = request($clockOutPath, ['shiftId' => $active->getId()]);
    check($denied['status'] === 412, 'Clock-out requires a CSRF token');
    $stale = request($clockOutPath, ['shiftId' => $active->getId() + 1], $token);
    check($stale['status'] === 409, 'Clock-out rejects another or stale shift ID');
    setTrackingOverHttp(false, $token);
    $denied = request($clockInPath, [], $token);
    check($denied['status'] === 403, 'Disabled tracking rejects new clock-ins on the server');
    $stopped = request($clockOutPath, ['shiftId' => $active->getId()], $token);
    check($stopped['status'] === 200 && $shiftMapper->findOpenForUser($userId) === null,
        'Existing shifts can be clocked out even after tracking is disabled');
    $duplicate = request($clockOutPath, ['shiftId' => $active->getId()], $token);
    check($duplicate['status'] === 409, 'Duplicate clock-out is rejected');

    $adminGroup->addUser($user);
    check($groupManager->isAdmin($userId), 'Temporary account is promoted for admin-only checks');
    $admin = request('/index.php/settings/admin/timesheets');
    check($admin['status'] === 200 && str_contains($admin['body'], 'timesheets-admin-form'),
        'Administrator can open Timesheets administration settings (HTTP ' . $admin['status'] . ')');
    check(str_contains($admin['body'], 'timesheets/js/admin.js'), 'Settings page loads the external save script');
    check(!str_contains($admin['body'], "const form = document.getElementById('timesheets-admin-form')"),
        'Settings page does not rely on a CSP-blocked inline script');
    $adminDocument = pageDocument($admin['body']);
    $adminPath = new DOMXPath($adminDocument);
    check(trim($adminPath->query('//*[@id="timesheets-admin"]/h2')->item(0)->textContent) === 'Timesheet Administration',
        'Administration page has the requested title');
    check($adminPath->query('//*[@id="timesheets-admin-form"]/fieldset')->length === 3,
        'Settings are grouped into tracking, rounding, and shift review');
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
} finally {
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
echo "Entry point checks passed; temporary account and cookies removed, original settings restored.\n";
