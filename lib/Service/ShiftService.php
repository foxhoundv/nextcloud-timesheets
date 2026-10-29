<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCA\Timesheets\Db\Shift;
use OCA\Timesheets\Db\ShiftMapper;

class ShiftService {
    public function __construct(
        private ShiftMapper $shiftMapper,
    ) {
    }

    public function listForUser(string $userId): array {
        return $this->shiftMapper->findByUser($userId);
    }

    public function createShift(Shift $shift): Shift {
        return $this->shiftMapper->insert($shift);
    }
}
