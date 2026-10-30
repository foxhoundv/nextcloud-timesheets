<?php

declare(strict_types=1);

namespace OCA\Timesheets\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\Settings\IManager;

class Application extends App implements IBootstrap {
    public const APP_ID = 'timesheets';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function boot(IBootContext $context): void {
        $manager = \OC::$server->get(IManager::class);

        if (method_exists($manager, 'registerSection')) {
            $manager->registerSection('timesheets', 'Timesheets', 'icon-calendar', 80);
        }
    }
}
