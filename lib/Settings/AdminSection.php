<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Settings;

use OCA\FilesWatermark\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {

    public function __construct(
        private IL10N        $l,
        private IURLGenerator $url,
    ) {}

    public function getID(): string {
        return 'watermark';
    }

    public function getName(): string {
        return $this->l->t('Watermark');
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        return $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
    }
}
