<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

class AccessService {
    public function __construct(
        private IConfig $config,
        private IUserManager $users,
        private IGroupManager $groups,
        private ClockTransaction $transaction,
    ) {
    }

    public function policy(): array {
        $policy = json_decode($this->config->getAppValue('timesheets', 'access_policy',
            '{"restricted":false,"grants":[]}'), true, 512, JSON_THROW_ON_ERROR);
        try {
            $this->validate($policy);
        } catch (\DomainException $error) {
            throw new \UnexpectedValueException('The saved Timesheets access policy is invalid.', 0, $error);
        }
        foreach ($policy['grants'] as &$grant) {
            $grant['admin'] = $grant['admin'] ?? false;
        }
        unset($grant);
        return $policy;
    }

    public function save(string $json, ?string $actorId = null): void {
        try {
            $policy = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \DomainException('The access policy is not valid JSON.', 400, $error);
        }
        $this->validate($policy);
        foreach ($policy['grants'] as $grant) {
            if ($grant['type'] === 'user' ? !$this->users->userExists($grant['id']) : !$this->groups->groupExists($grant['id'])) {
                throw new \DomainException('An assigned user or group no longer exists. Remove or replace that assignment.', 400);
            }
        }
        $this->transaction->exclusive(function () use ($policy, $actorId): void {
            if ($actorId !== null && !$this->isTimesheetsAdmin($actorId)) {
                throw new \DomainException('You do not have permission to manage Timesheets settings.', 403);
            }
            if ($actorId !== null && !$this->isNextcloudAdmin($actorId)
                && $this->adminAssignments($policy) !== $this->adminAssignments($this->policy())) {
                throw new \DomainException('Only Nextcloud administrators can change delegated administrator assignments.', 403);
            }
            $adminChanges = $this->adminAssignments($policy) !== $this->adminAssignments($this->policy());
            $this->config->setAppValue('timesheets', 'access_policy', json_encode($policy, JSON_THROW_ON_ERROR));
            if ($adminChanges) {
                $this->transaction->invalidateReset();
            }
        });
    }

    public function isNextcloudAdmin(string $userId): bool {
        return $this->groups->isAdmin($userId);
    }

    public function administrators(): array {
        $ids = [];
        foreach ($this->directory('user') as $entry) {
            if ($this->isTimesheetsAdmin($entry['id'])) {
                $ids[] = $entry['id'];
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function isTimesheetsAdmin(string $userId): bool {
        $user = $this->users->get($userId);
        if ($user === null || !$user->isEnabled()) {
            return false;
        }
        if ($this->isNextcloudAdmin($userId)) {
            return true;
        }
        $groupIds = $this->groups->getUserGroupIds($user);
        foreach ($this->policy()['grants'] as $grant) {
            if ($grant['admin']
                && (($grant['type'] === 'user' && $grant['id'] === $userId)
                    || ($grant['type'] === 'group' && in_array($grant['id'], $groupIds, true)))) {
                return true;
            }
        }
        return false;
    }

    public function permissions(string $userId): array {
        $user = $this->users->get($userId);
        if ($user === null || !$user->isEnabled()) {
            return ['access' => false, 'shift' => false, 'job' => false];
        }
        $policy = $this->policy();
        if (!$policy['restricted']) {
            return ['access' => true, 'shift' => true, 'job' => true];
        }
        $permissions = ['access' => false, 'shift' => false, 'job' => false];
        $groupIds = $this->groups->getUserGroupIds($user);
        foreach ($policy['grants'] as $grant) {
            if (($grant['type'] === 'user' && $grant['id'] === $userId)
                || ($grant['type'] === 'group' && in_array($grant['id'], $groupIds, true))) {
                $permissions['access'] = true;
                $permissions['shift'] = $permissions['shift'] || $grant['shift'];
                $permissions['job'] = $permissions['job'] || $grant['job'];
            }
        }
        return $permissions;
    }

    public function directory(string $type): array {
        if (!in_array($type, ['user', 'group'], true)) {
            throw new \DomainException('Choose users or groups.', 400);
        }
        $entries = [];
        $offset = 0;
        do {
            $matches = $type === 'user'
                ? $this->users->search('', 200, $offset)
                : $this->groups->search('', 200, $offset);
            foreach ($matches as $match) {
                $id = $type === 'user' ? $match->getUID() : $match->getGID();
                $entries[$id] = [
                    'id' => $id,
                    'name' => $type === 'user' ? $id : $match->getDisplayName(),
                ];
            }
            $offset += count($matches);
        } while (count($matches) === 200);
        $entries = array_values($entries);
        usort($entries, static fn (array $a, array $b): int =>
            strcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
        return $entries;
    }

    public function search(string $type, string $query): array {
        if (!in_array($type, ['user', 'group'], true)) {
            throw new \DomainException('Choose users or groups.', 400);
        }
        $query = trim($query);
        if (strlen($query) < 2 || strlen($query) > 255) {
            throw new \DomainException('Enter between 2 and 255 characters to search.', 400);
        }
        $results = [];
        $matches = $type === 'user' ? $this->users->search($query, 20, 0) : $this->groups->search($query, 20, 0);
        foreach ($matches as $match) {
            $results[] = [
                'id' => $type === 'user' ? $match->getUID() : $match->getGID(),
                'label' => $match->getDisplayName(),
            ];
        }
        return $results;
    }

    private function validate(mixed $policy): void {
        if (!is_array($policy) || !is_bool($policy['restricted'] ?? null)
            || !is_array($policy['grants'] ?? null) || !array_is_list($policy['grants'])
            || count($policy['grants']) > 1000) {
            throw new \DomainException('The access policy must have a mode and at most 1000 assignments.', 400);
        }
        $seen = [];
        foreach ($policy['grants'] as $grant) {
            if (!is_array($grant) || !in_array($grant['type'] ?? null, ['user', 'group'], true)
                || !is_string($grant['id'] ?? null) || $grant['id'] === '' || strlen($grant['id']) > 255
                || !is_bool($grant['shift'] ?? null) || !is_bool($grant['job'] ?? null)
                || (array_key_exists('admin', $grant) && !is_bool($grant['admin']))) {
                throw new \DomainException('Every assignment needs a valid user/group ID and tracking permissions.', 400);
            }
            $key = $grant['type'] . ':' . $grant['id'];
            if (isset($seen[$key])) {
                throw new \DomainException('Each user or group may only be assigned once.', 400);
            }
            $seen[$key] = true;
        }
    }

    private function adminAssignments(array $policy): array {
        $assignments = [];
        foreach ($policy['grants'] as $grant) {
            if (($grant['admin'] ?? false) === true) {
                $assignments[] = $grant['type'] . ':' . $grant['id'];
            }
        }
        sort($assignments, SORT_STRING);
        return $assignments;
    }
}
