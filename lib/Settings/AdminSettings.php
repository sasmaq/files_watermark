<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Settings;

use OCA\FilesWatermark\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {

	public function getForm(): TemplateResponse {
		// See LoadAdditionalScriptsListener: the settings page is Vue, and its strings only
		// resolve if the app's translation bundle is on the page with it.
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, 'admin-settings');
		Util::addStyle(Application::APP_ID, 'admin-settings');
		return new TemplateResponse(Application::APP_ID, 'admin', [], 'blank');
	}

	public function getSection(): string {
		return 'watermark';
	}

	public function getPriority(): int {
		return 10;
	}
}
