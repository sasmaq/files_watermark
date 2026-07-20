<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\BackgroundJob\WatermarkOnUploadJob;
use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Applies the `on_upload` watermark before the upload response is sent.
 *
 * {@see \OCA\FilesWatermark\EventListener\NodeWrittenListener} cannot do this inline —
 * `NodeWrittenEvent` fires while the write still holds a lock on the node — so it queues
 * {@see WatermarkOnUploadJob} instead. That is correct but only as prompt as cron: on a
 * default (AJAX-cron) instance an uploaded file stays clean for minutes, which reads as
 * "on-upload watermarking is broken" in the Files UI.
 *
 * `afterMethod:PUT` runs once Sabre's own handler has returned, by which point the lock
 * taken for the write is released — so the burn can happen here, in-request, and the user
 * sees a watermarked file immediately. `MOVE` is hooked as well because chunked uploads
 * (anything large enough for the web UI or desktop client to split) land their final bytes
 * by moving the assembled file into place rather than by a plain PUT.
 *
 * The queued job is not replaced by this, it is the fallback: writes that never touch DAV
 * (the Files API, `occ`, other apps) still need it, and if the burn here fails for any
 * reason the job is deliberately left in the queue to retry it out of band. When the inline
 * burn succeeds the now-redundant job is removed.
 *
 * Registered on the authenticated Files server only. Public file-drop uploads have no
 * session to attribute a watermark to, so they fall through to the job — which also
 * declines them for want of a uid. See the note in doc/tasks.md.
 */
class UploadWatermarkPlugin extends ServerPlugin {

	private ?Server $server = null;

	public function __construct(
		private WatermarkService $watermarkService,
		private IUserSession $userSession,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		$server->on('afterMethod:PUT', [$this, 'afterWrite'], 200);
		// Chunked uploads assemble into place with a MOVE, so a large file never sees PUT
		// on its final path.
		$server->on('afterMethod:MOVE', [$this, 'afterWrite'], 200);
	}

	public function afterWrite(RequestInterface $request, ResponseInterface $response): void {
		if ($this->server === null) {
			return;
		}

		$path = $this->targetPath($request);
		if ($path === null) {
			return;
		}

		try {
			$davNode = $this->server->tree->getNodeForPath($path);
		} catch (NotFound) {
			return;
		}

		if (!($davNode instanceof DavFile)) {
			return;
		}

		$node = $davNode->getNode();
		if (!($node instanceof File) || !$this->watermarkService->isSupported($node->getMimeType())) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		try {
			$config = $this->watermarkService->resolveConfig($user->getUID());
		} catch (\Throwable) {
			return;
		}

		if ($config->getTrigger() !== 'on_upload') {
			return;
		}

		$fileId = $node->getId();

		try {
			// The burn writes the file, re-firing NodeWrittenEvent — suppressed so the
			// listener does not queue a job for what we are doing right here.
			$applied = NodeWrittenListener::suppressFor($fileId, fn (): bool
				=> $this->watermarkService->watermarkInPlace($node, 'on_upload', $config, $user));
		} catch (\Throwable $e) {
			// Leave the queued job alone — cron retries it out of band. An upload must not
			// fail because its watermark could not be applied.
			$this->logger->warning('files_watermark: inline on-upload watermark failed, leaving it to the job: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $node->getPath(),
			]);
			return;
		}

		if ($applied) {
			// Done in-request, so the job the PUT queued has nothing left to do.
			$this->jobList->remove(WatermarkOnUploadJob::class, ['fileId' => $fileId, 'uid' => $user->getUID()]);
		}
	}

	/**
	 * The path the write landed on: the request path for PUT, the Destination for MOVE.
	 */
	private function targetPath(RequestInterface $request): ?string {
		if ($request->getMethod() !== 'MOVE') {
			return $request->getPath();
		}

		$destination = $request->getHeader('Destination');
		if ($destination === null || $destination === '') {
			return null;
		}

		try {
			return $this->server->calculateUri($destination);
		} catch (\Throwable) {
			return null;
		}
	}
}
