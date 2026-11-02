<?php

declare(strict_types=1);

namespace OCA\Timesheets\Db;

use OCP\AppFramework\Db\Entity;

class Job extends Entity {
    protected $userId;
    protected $jobReference;
    protected $jobDate;
    protected $clockIn;
    protected $clockOut;
    protected $durationSeconds;
    protected $timeZone;
    protected $openUserId;

    public function __construct() {
        $this->addType('durationSeconds', 'integer');
    }
}
