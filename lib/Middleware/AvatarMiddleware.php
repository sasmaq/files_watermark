<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Middleware;

use OC\Core\Controller\AvatarController;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Refuses to let core make a profile picture out of a protected file.
 *
 * ---------------------------------------------------------------------------
 * A PROFILE PICTURE FROM FILES IS A COPY OF THE FILE, SERVED BACK CLEAN.
 *
 * Choosing a profile picture from Files is `POST /avatar/` with the file's path as `path`.
 * Core reads the whole file with `getContent()` (`AvatarController::postAvatar`), and then
 * either answers a non-square image with the whole of it, re-encoded, as a base64 `data:`
 * URI in the JSON - a full-resolution copy - or stores a square one as the account's avatar,
 * which `GET /avatar/{userId}/512` serves to anyone, signed in or not. Either way a marked
 * JPEG or PNG came back clean.
 * ---------------------------------------------------------------------------
 *
 * **Refused before core runs**, as {@see ThemingBackgroundMiddleware} refuses a background:
 * the copy is made inside the controller, and a square image is already the avatar by the
 * time `postAvatar` answers. Nothing is read, and the profile picture stays as it was.
 *
 * **A 200 with `{data: {message}}`**, the answer core itself gives on this route when the
 * path is no file ("Please select a file."). The settings page shows `data.message` only from
 * a 2xx answer; any other status gets its own generic "Error setting profile picture". Either
 * way it sets nothing. `AvatarController` is a plain `Controller`, so unlike Theming's there is
 * no OCS envelope to rewrap the answer.
 *
 * **The file is the one core would read**: the same path, through the same `stripslashes()`,
 * in the same user's folder. A path that does not resolve, or is not a file, is left to core,
 * which fails on it before reading anything. An upload (`files[]`, which the page's cropper
 * sends) comes from the reader's own device and is not looked at.
 *
 * Registered globally - core's controllers are reachable no other way.
 */
class AvatarMiddleware extends Middleware {

	public function __construct(
		private WatermarkService $watermarkService,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IRequest $request,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		if (!($controller instanceof AvatarController) || $methodName !== 'postAvatar') {
			return;
		}

		$file = $this->picture();
		if ($file === null) {
			return;
		}

		try {
			$protected = $this->watermarkService->isDeliveryCandidate($file);
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: could not tell whether a file chosen as a profile picture is protected; refusing it: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			$protected = true;
		}

		if ($protected) {
			throw new ProtectedFileException($this->l->t('This file is watermarked on download, so it cannot be used as a profile picture.'));
		}
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if (!($exception instanceof ProtectedFileException)) {
			throw $exception;
		}

		return new JSONResponse(['data' => ['message' => $exception->getMessage()]]);
	}

	/**
	 * The file core is about to read, resolved as `postAvatar` resolves it, or null when core
	 * will find no file there either.
	 */
	private function picture(): ?File {
		$uid = $this->userSession->getUser()?->getUID();
		$path = $this->request->getParam('path');
		if ($uid === null || !is_string($path)) {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->get(stripslashes($path));
		} catch (\Throwable) {
			return null;
		}

		return $node instanceof File ? $node : null;
	}
}
