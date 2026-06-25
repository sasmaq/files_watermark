<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class SettingsController extends Controller {

    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    public function adminIndex(): TemplateResponse {
        return new TemplateResponse('files_watermark', 'admin', [], 'blank');
    }
}
