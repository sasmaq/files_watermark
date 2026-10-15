<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Db\WatermarkRenditionMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Drops the delivery lengths measured for an account that no longer exists.
 *
 * A reservation is a promise to one reader, keyed by their uid, and nothing else ever
 * deletes one: a reader who is gone leaves a row per file they ever downloaded. Their own
 * files go with the account and are swept by {@see \OCA\FilesWatermark\BackgroundJob\CleanupJob};
 * the rows they hold against *other people's* files are only reachable by uid, which is this.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {

	public function __construct(
		private WatermarkRenditionMapper $renditionMapper,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		$this->renditionMapper->deleteByUid($event->getUser()->getUID());
	}
}
