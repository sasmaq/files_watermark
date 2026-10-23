<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\FolderMarker;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Marks a file when the policy is `on_upload` and the file comes into its scope: when it is
 * written, and when a move or rename puts it there.
 *
 * **Inline, in the event.** That is worth naming because it used to be impossible: the
 * watermark was burned into the file, `NodeWrittenEvent` fires while the triggering write
 * still holds a lock on the node, and writing from here threw `LockedException`. The whole
 * apparatus that existed to work around it - a background job, a second DAV plugin to beat
 * cron to it, and a static suppression map so this app's own writes did not re-trigger
 * themselves - is gone with the burn. Marking touches no content, takes no lock, and costs
 * one insert.
 *
 * ---------------------------------------------------------------------------
 * A MOVE INTO SCOPE IS NOT A WRITE.
 *
 * The scope is decided by the file's folder and its name, and a move changes both without
 * writing anything: core's `View::rename` emits write hooks only when a `.part` file lands,
 * so `NodeWrittenEvent` never hears a move. A PDF moved into the tagged folder, or
 * `report.bin` renamed to `report.pdf`, stayed unmarked - a user's off switch for the
 * policy, one drag away. So the rename's target is put through the same test as a write,
 * and marked under the same trigger, since it is that policy which placed it.
 *
 * **A moved folder is walked** when it lands inside a tagged folder, because the tag scopes
 * every file below the folder at any depth ({@see \OCA\FilesWatermark\Service\FolderTagScope}).
 * Up to a budget in this request, the rest in a job ({@see FolderMarker}). Two moves are not
 * walked, because they cannot have changed the scope of anything below them: a rename in
 * place keeps every ancestor, and with no folder tag configured the scope is the names alone.
 *
 * **Registered behind {@see NodeMovedListener}** on the rename. A file moved out of a share
 * may be owed an inherited mark naming the sharer; marked here first, it would get an
 * ordinary mark naming the new owner, who could then remove it whenever the policy is
 * `on_demand` again.
 * ---------------------------------------------------------------------------
 *
 * @template-implements IEventListener<NodeWrittenEvent|NodeRenamedEvent>
 */
class NodeWrittenListener implements IEventListener {

	public function __construct(
		private WatermarkService $watermarkService,
		private FolderMarker $folderMarker,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof NodeWrittenEvent) {
			$node = $event->getNode();
			$cause = 'upload';
		} elseif ($event instanceof NodeRenamedEvent) {
			$node = $event->getTarget();
			$cause = 'move';
			if ($node instanceof Folder) {
				$this->movedFolder($event->getSource(), $node);
				return;
			}
		} else {
			return;
		}

		if (!($node instanceof File)) {
			return;
		}

		if (!$this->watermarkService->isSupported($node->getMimeType())) {
			return;
		}

		if ($this->watermarkService->effectiveTrigger() !== WatermarkService::TRIGGER_ON_UPLOAD) {
			return;
		}

		try {
			// An overwrite of an already-marked file leaves the mark standing - the mark is a
			// policy on the file id, not a claim about a particular set of bytes - and so does
			// a move. This is the ordinary outcome for every write after the first, and for
			// almost every move, since the policy marked the file when it arrived.
			if ($this->watermarkService->isMarked($node->getId())) {
				return;
			}

			// Outside the scope is the policy not being about this file, which is not a
			// failure: under a folder tag it is most writes and most moves on the instance.
			if (!$this->watermarkService->isInPolicyScope($node)) {
				return;
			}

			$this->watermarkService->mark(
				$node,
				WatermarkService::TRIGGER_ON_UPLOAD,
				$this->userSession->getUser(),
			);
		} catch (\Throwable $e) {
			// **The upload or move still succeeds.** A file that cannot be marked is a file
			// served exactly as it was uploaded, which is the only failure mode available here
			// that does not lose the user's data. It is logged at warning because it is also
			// the only place an oversized upload is ever mentioned: nothing downstream refuses
			// the file later, so this line is the whole record that a policy did not apply.
			$this->logger->warning('files_watermark: could not mark {path} on {cause}: {reason}', [
				'path' => $node->getPath(),
				'cause' => $cause,
				'reason' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	private function movedFolder(Node $source, Folder $target): void {
		if ($this->watermarkService->effectiveTrigger() !== WatermarkService::TRIGGER_ON_UPLOAD) {
			return;
		}

		try {
			// The source is the path the folder left, which still answers getPath().
			if (dirname($source->getPath()) === dirname($target->getPath())
				|| !$this->watermarkService->isInsidePolicyFolderTag($target)) {
				return;
			}

			$this->folderMarker->markTreeOrQueue($target, $this->userSession->getUser(), 'move');
		} catch (\Throwable $e) {
			$this->logger->warning('files_watermark: could not mark the files of moved folder {path}: {reason}', [
				'path' => $target->getPath(),
				'reason' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}
}
