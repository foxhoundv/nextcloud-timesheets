<?php

declare(strict_types=1);

use OCA\Timesheets\Controller\PageController;
use OCA\Timesheets\Db\ShiftMapper;
use OCA\Timesheets\Service\AccessService;
use OCA\Timesheets\Service\ShiftClockService;
use OCA\Timesheets\Service\ShiftService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

require __DIR__ . '/persistence.php';

$config = Server::get(IConfig::class);
$users = Server::get(IUserManager::class);
$groups = Server::get(IGroupManager::class);
$access = Server::get(AccessService::class);
$previous = $config->getAppValue('timesheets', 'access_policy', '');
$tracking = $config->getAppValue('timesheets', 'shift_tracking', '');
$id = 'timesheets-access-' . bin2hex(random_bytes(8));
$user = $users->createUser($id, bin2hex(random_bytes(24)));
check($user !== false, 'Access test user created');
$group = null;
$otherGroup = null;
$mapper = new ShiftMapper($db);
$clock = new ShiftClockService($mapper, $config, Server::get(ITimeFactory::class), $access);

function savePolicy(bool $restricted, array $grants): void {
    global $access;
    $access->save(json_encode(['restricted' => $restricted, 'grants' => $grants], JSON_THROW_ON_ERROR));
}

function rejectPolicy(mixed $policy): void {
    global $access, $config;
    $before = $config->getAppValue('timesheets', 'access_policy');
    try {
        $access->save(json_encode($policy, JSON_THROW_ON_ERROR));
        throw new LogicException('Invalid policy was accepted');
    } catch (DomainException $error) {
        check($error->getCode() === 400 && $config->getAppValue('timesheets', 'access_policy') === $before,
            'Invalid access policy is rejected without replacing the saved policy');
    }
}

try {
    $config->deleteAppValue('timesheets', 'access_policy');
    check($access->permissions($id) === ['access' => true, 'shift' => true, 'job' => true],
        'No saved policy preserves existing access and tracking permissions');
    savePolicy(true, []);
    check($access->permissions($id) === ['access' => false, 'shift' => false, 'job' => false],
        'Restricted mode with no assignments denies new activity');
    $session = Server::get(IUserSession::class);
    $session->setUser($user);
    $page = new PageController('timesheets', Server::get(IRequest::class), new ShiftService($mapper),
        $session, $clock, Server::get(IURLGenerator::class), $access);
    check($page->index()->getStatus() === 403, 'Unassigned user without history receives explicit access denial');
    $grant = ['type' => 'user', 'id' => $id, 'shift' => false, 'job' => false];
    savePolicy(true, [$grant]);
    check($access->permissions($id) === ['access' => true, 'shift' => false, 'job' => false],
        'Dashboard-only access grants no tracking permission');
    $grant['job'] = true;
    savePolicy(true, [$grant]);
    check($access->permissions($id) === ['access' => true, 'shift' => false, 'job' => true],
        'User can receive job-only permission without shift tracking');
    $group = $groups->createGroup($id . '-group');
    $otherGroup = $groups->createGroup($id . '-other');
    check($group !== false && $otherGroup !== false, 'Access test groups created');
    $groupGrant = ['type' => 'group', 'id' => $group->getGID(), 'shift' => true, 'job' => false];
    $otherGrant = ['type' => 'group', 'id' => $otherGroup->getGID(), 'shift' => false, 'job' => true];
    savePolicy(true, [$groupGrant, $otherGrant]);
    check(!$access->permissions($id)['access'], 'Non-members do not inherit group permissions');
    $group->addUser($user);
    check($access->permissions($id) === ['access' => true, 'shift' => true, 'job' => false],
        'Membership grants shift-only permission');
    $otherGroup->addUser($user);
    check($access->permissions($id) === ['access' => true, 'shift' => true, 'job' => true],
        'Permissions combine across multiple groups');
    $otherGroup->removeUser($user);
    savePolicy(true, [$grant, $groupGrant]);
    check($access->permissions($id) === ['access' => true, 'shift' => true, 'job' => true],
        'Direct and group permissions combine instead of overriding');
    $group->removeUser($user);
    check(!$access->permissions($id)['shift'] && $access->permissions($id)['job'],
        'Removing group membership removes only that group permission');
    $group->addUser($user);
    $config->setAppValue('timesheets', 'shift_tracking', '0');
    try {
        $clock->clockIn($id);
        throw new LogicException('A grant bypassed the global tracking switch');
    } catch (DomainException $error) {
        check($error->getCode() === 403, 'Tracking grants cannot bypass the global master switch');
    }
    $config->setAppValue('timesheets', 'shift_tracking', '1');
    $shift = $clock->clockIn($id);
    savePolicy(true, []);
    check($clock->current($id)['id'] === $shift->getId(), 'Revocation preserves the active shift');
    check(!$page->index()->getParams()['canStartShift'], 'Dashboard hides new clock-ins after revocation');
    try {
        $clock->clockIn($id);
        throw new LogicException('Revoked clock-in was accepted');
    } catch (DomainException $error) {
        check($error->getCode() === 403, 'Clock service enforces revocation server-side');
    }
    $clock->clockOut($id, $shift->getId());
    check($clock->current($id) === null && count($page->index()->getParams()['entries']) === 1,
        'Revoked user can close an active shift and continue viewing history');
    $user->setEnabled(false);
    savePolicy(false, []);
    check(!$access->permissions($id)['access'], 'Disabled accounts do not receive unrestricted permission');
    $user->setEnabled(true);
    check(!$access->permissions($id . '-missing')['access'], 'Unknown accounts receive no permission');
    foreach ([null, ['restricted' => 'true', 'grants' => []],
        ['restricted' => true, 'grants' => [$grant, $grant]],
        ['restricted' => true, 'grants' => [array_merge($grant, ['shift' => '1'])]],
        ['restricted' => true, 'grants' => [array_merge($grant, ['type' => 'admin'])]],
        ['restricted' => true, 'grants' => [array_merge($grant, ['id' => $id . '-missing'])]],
    ] as $invalid) {
        rejectPolicy($invalid);
    }
    check(in_array($id, array_column($access->search('user', $id), 'id'), true),
        'User directory search returns stable IDs');
    check(in_array($group->getGID(), array_column($access->search('group', $id), 'id'), true),
        'Group directory search returns stable IDs');
    $user->setDisplayName('ZZZ display name differs from account name');
    $directory = $access->directory('user');
    $entry = array_values(array_filter($directory, static fn (array $entry): bool => $entry['id'] === $id));
    check(count($entry) === 1 && $entry[0]['name'] === $id,
        'User selector displays account names instead of display names');
    foreach (['user', 'group'] as $type) {
        $directory = $access->directory($type);
        $sorted = $directory;
        usort($sorted, static fn (array $a, array $b): int =>
            strcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
        check($directory === $sorted, 'Complete selector directory is alphabetized: ' . $type);
        check(count($directory) === count(array_unique(array_column($directory, 'id'))),
            'Directory contains no duplicate IDs: ' . $type);
    }
    $group->setDisplayName('A renamed group');
    $directory = $access->directory('group');
    $entry = array_values(array_filter($directory, static fn (array $entry): bool => $entry['id'] === $group->getGID()));
    check(count($entry) === 1 && $entry[0]['name'] === 'A renamed group',
        'Group selector refreshes renamed group names without changing stable IDs');
} finally {
    foreach ($mapper->findByUser($id) as $shift) {
        $mapper->delete($shift);
    }
    foreach ([$group, $otherGroup] as $entry) {
        if ($entry !== null && $entry !== false && !$entry->delete()) {
            throw new RuntimeException('Failed to remove access test group');
        }
    }
    check($user->delete(), 'Access test user removed');
    Server::get(IAppConfig::class)->clearCache();
    foreach (['access_policy' => $previous, 'shift_tracking' => $tracking] as $key => $value) {
        if ($value === '') {
            $config->deleteAppValue('timesheets', $key);
        } else {
            $config->setAppValue('timesheets', $key, $value);
        }
    }
}
echo "Access checks passed; original settings restored and fixtures removed.\n";
