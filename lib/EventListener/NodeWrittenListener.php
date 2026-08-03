<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\BackgroundJob\WatermarkOnUploadJob;
use OCA\FilesWatermark\Service\OriginalStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Queue the on-upload watermark for a freshly written file.
 *
 * This listener deliberately does not watermark inline. `NodeWrittenEvent` fires while the
 * triggering write still holds a lock on the node, so writing the watermarked bytes back
 * from here throws `LockedException` - on WebDAV uploads and plain Files-API writes alike.
 * The actual burn happens in {@see WatermarkOnUploadJob}, once the lock is gone.
 *
 * @template-implements IEventListener<NodeWrittenEvent>
 */
class NodeWrittenListener implements IEventListener {

	/** File IDs whose writes must not queue a job; see {@see suppressFor}. */
	private static array $suppressed = [];

	public function __construct(
		private WatermarkService $watermarkService,
		private OriginalStore $originalStore,
		private IUserSession $userSession,
		private IJobList $jobList,
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

		// Storing a preserved original is itself a write of a supported file into the
		// owner's storage, so without this every backup queues a job to watermark the
		// backup. watermarkInPlace() refuses those anyway; this keeps the pointless job
		// out of the queue rather than relying on it being rejected at the far end.
		if ($this->originalStore->isBackup($node)) {
			return;
		}

		$fileId = $node->getId();
		if (isset(self::$suppressed[$fileId])) {
			return;
		}

		// Past this point the write is the *user's*, not one of ours - which is the only
		// moment an overwrite of watermarked content can be recognised for what it is.
		// Ahead of every policy check below on purpose: the watermarked bytes are gone
		// whatever the current trigger is, and a badge that outlives them is a lie the
		// admin never configured.
		$this->watermarkService->noteContentReplaced($node);

		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			// No session to attribute the watermark to, and the job needs a uid to
			// re-resolve the node. Nothing sensible to queue.
			return;
		}

		try {
			$config = $this->watermarkService->resolveConfig();
		} catch (\Throwable) {
			return;
		}

		if ($config->getTrigger() !== 'on_upload') {
			return;
		}

		// Already burned in - the job would only skip it again. Cheap filter for the
		// common case of a file being written repeatedly after its first watermark.
		if ($this->watermarkService->isAlreadyWatermarked($fileId)) {
			return;
		}

		$this->jobList->add(WatermarkOnUploadJob::class, ['fileId' => $fileId, 'uid' => $uid]);
	}

	/**
	 * Run $callback with the on-upload trigger disabled for $fileId.
	 *
	 * The job's own `putContent()` fires another `NodeWrittenEvent`, which would queue a
	 * second job for the same file. That second job would skip harmlessly (the audit row
	 * exists by then), but it is a wasted cron cycle per upload - and the audit row is
	 * written *after* the content, so there is a window where it would not skip.
	 */
	public static function suppressFor(int $fileId, callable $callback): mixed {
		self::$suppressed[$fileId] = true;
		try {
			return $callback();
		} finally {
			unset(self::$suppressed[$fileId]);
		}
	}
}
