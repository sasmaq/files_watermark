<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\OriginalStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\BeforeShareCreatedEvent;

/**
 * Refuses shares of the app's preserved originals.
 *
 * {@see \OCA\FilesWatermark\Dav\HideOriginalsPlugin} seals those paths on WebDAV, and a
 * share walks straight past it: share creation resolves a *path* through the Files API,
 * so no DAV hook ever sees it, and the share is then served from the **public** endpoint,
 * where the shared node is re-rooted so that its path no longer names the folder at all.
 * Registering the DAV plugin on the public server does not help for that reason - it was
 * measured. Refusing the share is what closes it.
 *
 * Core acts on this only when **both** the error and the propagation stop are set:
 *
 *     if ($event->isPropagationStopped() && $event->getError()) { … }   // Share20\Manager
 *
 * `setError()` on its own is silently ignored and the share is created regardless, which
 * is the kind of half-working guard that looks right in review and fails in production.
 *
 * This refuses *new* shares. Any that already exist on an instance are untouched, and are
 * worth listing for once after this ships.
 *
 * @template-implements IEventListener<BeforeShareCreatedEvent>
 */
class ShareGuardListener implements IEventListener {

	public function __construct(
		private OriginalStore $originalStore,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeShareCreatedEvent)) {
			return;
		}

		try {
			$node = $event->getShare()->getNode();
		} catch (\Throwable) {
			// The share names a node that cannot be resolved. Core is about to fail this
			// share on its own terms with a better message than anything invented here.
			return;
		}

		if (!$this->originalStore->isBackup($node)) {
			return;
		}

		$event->setError('This file cannot be shared.');
		$event->stopPropagation();
	}
}
