<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\FolderMarker;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\SystemTag\TagAssignedEvent;
use Psr\Log\LoggerInterface;

/**
 * Marks the files already in a folder when the policy's folder tag is put on it, under
 * `on_upload`.
 *
 * ---------------------------------------------------------------------------
 * TAGGING A FOLDER BRINGS ITS FILES INTO SCOPE WITHOUT TOUCHING ANY OF THEM.
 *
 * The upload trigger marks a file when it is written or moved into scope
 * ({@see NodeWrittenListener}). Putting the tag on a folder changes the scope of everything
 * under it and fires neither event, so a folder tagged after it was filled stayed clean under
 * a policy that says every file in a tagged folder is marked.
 *
 * **The whole tree**, since the tag scopes every file below the folder at any depth
 * ({@see \OCA\FilesWatermark\Service\FolderTagScope}) - in this request up to a budget, and
 * the rest in a job ({@see FolderMarker}). Tagging a *file* changes nothing: the setting
 * scopes folders.
 *
 * Taking the tag off marks nothing and unmarks nothing: the mark is the decision, and the
 * scope is not consulted again once it is placed ({@see WatermarkService::assertMarkable}).
 * ---------------------------------------------------------------------------
 *
 * Never throws: the tag is already saved when this runs, and failing the request would
 * report an assignment that happened as one that did not.
 *
 * @template-implements IEventListener<TagAssignedEvent>
 */
class TagAssignedListener implements IEventListener {

	public function __construct(
		private WatermarkService $watermarkService,
		private FolderMarker $folderMarker,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof TagAssignedEvent) || $event->getObjectType() !== 'files') {
			return;
		}

		// The tag first: nearly every assignment on an instance is some other tag, and the
		// trigger check logs a warning per call for a stale policy.
		$policyTag = $this->watermarkService->resolveConfig()->getFolderTag();
		if ($policyTag === null || $policyTag === ''
			|| !in_array($policyTag, array_map('strval', $event->getTags()), true)) {
			return;
		}

		if ($this->watermarkService->effectiveTrigger() !== WatermarkService::TRIGGER_ON_UPLOAD) {
			return;
		}

		foreach ($event->getObjectIds() as $objectId) {
			try {
				$folder = $this->folderMarker->folderById((int)$objectId);
				if ($folder !== null) {
					$this->folderMarker->markTreeOrQueue($folder, $this->userSession->getUser(), 'tag');
				}
			} catch (\Throwable $e) {
				$this->logger->warning('files_watermark: could not mark the files of tagged folder {fileId}: {reason}', [
					'fileId' => $objectId,
					'reason' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
	}
}
