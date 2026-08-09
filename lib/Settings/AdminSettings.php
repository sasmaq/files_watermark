<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Settings;

use OCA\FilesWatermark\AppInfo\Application;
use OCA\FilesWatermark\Service\InstanceTimeZone;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {

	public function __construct(
		private IInitialState $initialState,
		private InstanceTimeZone $timeZone,
	) {
	}

	public function getForm(): TemplateResponse {
		// See LoadAdditionalScriptsListener: the settings page is Vue, and its strings only
		// resolve if the app's translation bundle is on the page with it.
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, 'admin-settings');
		Util::addStyle(Application::APP_ID, 'admin-settings');

		// The activity log renders timestamps the API has already converted into this zone,
		// so the page has to be able to name it. Without that the table shows an hour with
		// no way to tell whether it is UTC or local - which is the state this replaced, and
		// the reason an admin could not reconcile a row with anything else.
		//
		// Page state rather than a field on every log row: it is a property of the instance,
		// and putting it on each row invites the reading that rows could differ.
		$this->initialState->provideInitialState('time-zone', $this->timeZone->get()->getName());

		return new TemplateResponse(Application::APP_ID, 'admin', [], 'blank');
	}

	public function getSection(): string {
		return 'watermark';
	}

	public function getPriority(): int {
		return 10;
	}
}
