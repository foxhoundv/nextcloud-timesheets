<?php

declare(strict_types=1);

namespace OCA\Timesheets\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return 'timesheets';
    }

    public function getName(): string {
        return $this->l->t('Timesheets');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('timesheets', 'app.svg');
    }
}
