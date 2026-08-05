<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Marks a freshly written file when the policy is `on_upload`.
 *
 * **Inline, in the event.** That is worth naming because it used to be impossible: the
 * watermark was burned into the file, `NodeWrittenEvent` fires while the triggering write
 * still holds a lock on the node, and writing from here threw `LockedException`. The whole
 * apparatus that existed to work around it - a background job, a second DAV plugin to beat
 * cron to it, and a static suppression map so this app's own writes did not re-trigger
 * themselves - is gone with the burn. Marking touches no content, takes no lock, and costs
 * one insert.
 *
 * @template-implements IEventListener<NodeWrittenEvent>
 */
class NodeWrittenListener implements IEventListener {

	public function __construct(
		private WatermarkService $watermarkService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeWrittenEvent)) {
			return;
		}

		$node = $event->getNode();

		if (!($node instanceof File)) {
			return;
		}

		if (!$this->watermarkService->isSupported($node->getMimeType())) {
			return;
		}

		if ($this->watermarkService->effectiveTrigger() !== WatermarkService::TRIGGER_ON_UPLOAD) {
			return;
		}

		// An overwrite of an already-marked file leaves the mark standing - the mark is a
		// policy on the file id, not a claim about a particular set of bytes - so this
		// returning false is the ordinary outcome for every write after the first.
		try {
			$this->watermarkService->mark(
				$node,
				WatermarkService::TRIGGER_ON_UPLOAD,
				$this->userSession->getUser(),
			);
		} catch (\Throwable $e) {
			// **The upload still succeeds.** A file that cannot be marked is a file served
			// exactly as it was uploaded, which is the only failure mode available here that
			// does not lose the user's data. It is logged at warning because it is also the
			// only place an oversized upload is ever mentioned: nothing downstream refuses
			// the file later, so this line is the whole record that a policy did not apply.
			$this->logger->warning('files_watermark: could not mark {path} on upload: {reason}', [
				'path' => $node->getPath(),
				'reason' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}
}
