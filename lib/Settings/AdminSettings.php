<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Settings;

use OCA\FilesWatermark\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {

    public function getForm(): TemplateResponse {
        return new TemplateResponse(Application::APP_ID, 'admin', [], 'blank');
    }

    public function getSection(): string {
        return 'watermark';
    }

    public function getPriority(): int {
        return 10;
    }
}
