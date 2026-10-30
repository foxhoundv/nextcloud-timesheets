<?php

declare(strict_types=1);

use OCA\Timesheets\AppInfo\Application;

$app = new Application();
if (method_exists($app, 'boot')) {
    $app->boot(new class implements \OCP\AppFramework\Bootstrap\IBootContext {
        public function getAppName(): string {
            return 'timesheets';
        }

        public function getContainer(): \Psr\Container\ContainerInterface {
            return \OC::$server;
        }
    });
}
