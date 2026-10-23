<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Middleware;

use OC\Core\Controller\TaskProcessingApiController;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Middleware;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use Psr\Log\LoggerInterface;

/**
 * Watermarks the files core's task processing API hands out of a task.
 *
 * ---------------------------------------------------------------------------
 * A TASK THAT NAMES A FILE IS A WAY TO DOWNLOAD IT.
 *
 * `GET /ocs/v2.php/taskprocessing/tasks/{taskId}/file/{fileId}` streams any file named in
 * the reader's own task with `fopen('rb')` (`TaskProcessingApiController::getFileContents`).
 * Scheduling only checks that the user has a mount for each file id, so a user with a
 * provider for a task type that takes files could name a marked file in a task and fetch
 * it back clean. `/tasks_provider/{taskId}/file/{fileId}` hands the same bytes to the ExApp
 * running the task.
 * ---------------------------------------------------------------------------
 *
 * **Replaced after core has answered**, as {@see TextAttachmentMiddleware} replaces Text's
 * answer: core has already checked that the task is the reader's (or that the caller is an
 * ExApp) and that the task names this file, so this only has to ask whether the file is
 * protected. A file that is not keeps core's stream.
 *
 * **The reader is the task's user, on both routes.** On the user's route that is the
 * session user anyway. On the ExApp's, the request is signed in as an ExApp acting for
 * nobody, or for whichever user it names, and read by the session it would be a public link
 * whose copy names the file's owner. So the task's user is noted as the reader
 * ({@see ShareAccess::noteReader}), the file is resolved through their folder so a share
 * mounted for them is what the share switches see, and the copy names them. Both routes are
 * authenticated, so the render is not charged against the anonymous allowance - an ExApp
 * working through a queue of tasks would otherwise run into it.
 *
 * **A protected file the reader cannot be placed against is refused.** A file outside the
 * task user's folder is a provider's output in appdata, which nothing marks, so core's
 * answer stands unless the file is marked. A task that will not load is refused outright:
 * core has just loaded it, so this is no state to guess in.
 *
 * **The refusal is a 403 with `message`.** The controller is an `OCSController`, and core's
 * `OCSMiddleware` rewraps a 403 into an OCS envelope carrying that message. On v2 that is an
 * HTTP 403. On v1 every envelope is an HTTP 200, core's own 404 included, so a v1 client
 * gets the envelope where it expected the file, never the file.
 *
 * Registered globally - core's controllers are reachable no other way.
 */
class TaskProcessingMiddleware extends Middleware {

	/** The controller methods that answer with a task's file. */
	private const FILE_METHODS = ['getFileContents', 'getFileContentsExApp'];

	public function __construct(
		private WatermarkService $watermarkService,
		private ShareAccess $shareAccess,
		private ITaskProcessingManager $taskProcessingManager,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private IRequest $request,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!($controller instanceof TaskProcessingApiController) || !in_array($methodName, self::FILE_METHODS, true)) {
			return $response;
		}

		// Core's 404 and 500 are `DataResponse`s; only a stream carries the file.
		if (!($response instanceof StreamResponse) || $response->getStatus() !== Http::STATUS_OK) {
			return $response;
		}

		$taskId = (int)$this->request->getParam('taskId');
		$fileId = (int)$this->request->getParam('fileId');

		try {
			$uid = $this->taskProcessingManager->getTask($taskId)->getUserId();
		} catch (\Throwable $e) {
			$this->logger->warning('files_watermark: core served a task\'s file and the task would not load here; refusing it rather than serving it unchecked: ' . $e->getMessage(), [
				'exception' => $e,
				'taskId' => $taskId,
			]);

			return $this->refused($this->l->t('This file could not be checked for a watermark.'));
		}

		$reader = $uid === null ? null : $this->userManager->get($uid);
		$file = $reader === null ? null : $this->readersFile($reader, $fileId);

		if ($reader === null || $file === null) {
			return $this->watermarkService->isMarked($fileId)
				? $this->refused($this->l->t('This file is watermarked on download, so it cannot be given to this task.'))
				: $response;
		}

		$this->shareAccess->noteReader($reader);

		try {
			$tmpPath = $this->watermarkService->watermarkForDownload($file, false);
		} catch (WatermarkRequiredException $e) {
			return $this->refused($e->getMessage());
		}

		if ($tmpPath === null) {
			return $response;
		}

		$stream = @fopen($tmpPath, 'rb');
		if ($stream === false) {
			$this->cleanup($tmpPath);

			return $this->refused($this->l->t('This file is watermarked on download, and the watermark could not be generated.'));
		}

		// Delete the temp copy once the response has been flushed to the client.
		register_shutdown_function(fn () => $this->cleanup($tmpPath));

		// Core's name, and the type core sniffed from the bytes, which the render keeps.
		$headers = $response->getHeaders();
		$watermarked = new StreamResponse($stream);
		foreach (['Content-Disposition', 'Content-Type'] as $name) {
			if (isset($headers[$name])) {
				$watermarked->addHeader($name, $headers[$name]);
			}
		}
		// This copy names its reader.
		$watermarked->addHeader('Cache-Control', 'private, no-store');

		return $watermarked;
	}

	/**
	 * The file as the task's user reaches it, or null when it is not in their folder.
	 */
	private function readersFile(IUser $reader, int $fileId): ?File {
		try {
			$node = $this->rootFolder->getUserFolder($reader->getUID())->getFirstNodeById($fileId);
		} catch (\Throwable) {
			return null;
		}

		return $node instanceof File ? $node : null;
	}

	private function refused(string $message): Response {
		return new JSONResponse(['message' => $message], Http::STATUS_FORBIDDEN);
	}

	private function cleanup(string $tmpPath): void {
		if (file_exists($tmpPath)) {
			@unlink($tmpPath);
			@rmdir(dirname($tmpPath));
		}
	}
}
