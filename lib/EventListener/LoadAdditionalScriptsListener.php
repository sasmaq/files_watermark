<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesWatermark\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the Files app integration bundle (the "Apply Watermark" file action)
 * whenever the Files app renders.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadAdditionalScriptsListener implements IEventListener {

    public function handle(Event $event): void {
        if (!($event instanceof LoadAdditionalScriptsEvent)) {
            return;
        }

        Util::addScript(Application::APP_ID, 'files');
        Util::addStyle(Application::APP_ID, 'files');
    }
}
