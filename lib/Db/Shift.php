<?php

declare(strict_types=1);

namespace OCA\Timesheets\Db;

use OCP\AppFramework\Db\Entity;

class Shift extends Entity {
    protected $userId;
    protected $shiftDate;
    protected $clockIn;
    protected $clockOut;
    protected $durationMinutes;
    protected $status;
}
