<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesWatermark\AppInfo\Application;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the Files app integration bundle (the "Apply Watermark" file action)
 * whenever the Files app renders, and hands the client the effective watermark
 * trigger so the file action can hide itself unless the policy is `on_demand`.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadAdditionalScriptsListener implements IEventListener {

	public function __construct(
		private IInitialState $initialState,
		private WatermarkService $watermarkService,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}

		// The Apply/Remove file actions only make sense when watermarking happens
		// on demand — in on_upload / on_download / on_share modes the app applies
		// watermarks itself, so the manual action is hidden. Expose the resolved
		// trigger (global → default) so `main-files.js` can gate on it.
		$trigger = $this->watermarkService->resolveConfig()->getTrigger();
		$this->initialState->provideInitialState('effective-trigger', $trigger);

		// Registered explicitly rather than left to whatever the server does on its own:
		// without the app's `l10n/<lang>.js`, every `t('files_watermark', …)` in the file
		// actions silently falls through to its English source, which looks exactly like a
		// working translation to anything except a reader of the menu.
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, 'files');
		Util::addStyle(Application::APP_ID, 'files');
	}
}
