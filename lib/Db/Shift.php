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
    protected $openUserId;
    protected $timeZone;
    protected $durationSeconds;
    protected $roundingInterval;
    protected $roundedSeconds;

    public function __construct() {
        $this->addType('durationMinutes', 'integer');
        $this->addType('durationSeconds', 'integer');
        $this->addType('roundingInterval', 'integer');
        $this->addType('roundedSeconds', 'integer');
    }
}
