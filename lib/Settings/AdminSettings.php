<?php

declare(strict_types=1);

namespace OCA\Timesheets\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('timesheets', 'settings/admin');
    }

    public function getSection(): string {
        return 'general';
    }

    public function getPriority(): int {
        return 10;
    }
}
