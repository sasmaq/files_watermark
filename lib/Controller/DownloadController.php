<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Controller;

use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class DownloadController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private WatermarkService $watermarkService,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Streams a watermarked copy of the requested file.
	 *
	 * The file on storage is never modified, and a file that is not marked is streamed as
	 * it is stored - this endpoint reports what the policy says, it does not overrule it.
	 * A marked file whose render fails is refused rather than served clean, the same
	 * denial the DAV path makes.
	 */
	#[NoAdminRequired]
	public function download(string $path): Http\Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		try {
			$node = $userFolder->get($path);
		} catch (NotFoundException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}

		if (!($node instanceof File)) {
			return new DataResponse(['error' => 'Path is not a file'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$tmpPath = $this->watermarkService->watermarkForDownload($node);
		} catch (WatermarkRequiredException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		if ($tmpPath === null) {
			// Not marked. Handing back the stored file is the correct answer, and it is the
			// same bytes any other download of it would produce.
			$handle = $node->fopen('rb');
			if ($handle === false) {
				return new DataResponse(['error' => $this->l->t('File not found')], Http::STATUS_NOT_FOUND);
			}

			$response = new StreamResponse($handle);
			$response->addHeader('Content-Disposition', 'attachment; filename="' . addslashes($node->getName()) . '"');
			$response->addHeader('Content-Type', $node->getMimeType());
			$response->addHeader('Content-Length', (string)$node->getSize());

			return $response;
		}

		$response = new StreamResponse($tmpPath);
		$response->addHeader('Content-Disposition', 'attachment; filename="' . addslashes($node->getName()) . '"');
		$response->addHeader('Content-Type', $node->getMimeType());
		$response->addHeader('Content-Length', (string)filesize($tmpPath));

		// Clean up the temp file after the response is sent via a shutdown function
		$tmpPathCopy = $tmpPath;
		register_shutdown_function(static function () use ($tmpPathCopy): void {
			if (file_exists($tmpPathCopy)) {
				unlink($tmpPathCopy);
				@rmdir(dirname($tmpPathCopy));
			}
		});

		return $response;
	}
}
