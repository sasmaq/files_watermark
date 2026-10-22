<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Middleware;

use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Theming\Controller\UserThemeController;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
 * Refuses to let Theming copy a protected file into an account's custom background.
 *
 * ---------------------------------------------------------------------------
 * A CUSTOM BACKGROUND IS A COPY OF THE FILE, SERVED BACK AS STORED.
 *
 * Choosing a background from Files is `POST /apps/theming/background/custom` with the
 * file's path as `value`. Theming opens that file with `fopen('r')` and writes the bytes
 * into the account's appdata as `background.jpg` (`BackgroundService::setFileBackground`),
 * and `GET /apps/theming/background` hands those bytes back unchanged. So a marked JPEG or
 * PNG came back clean. Theming checks that the copy is an image only *after* writing it, so
 * a PDF came back too, once the account already had a custom background for the failed
 * check to leave in place.
 * ---------------------------------------------------------------------------
 *
 * **Refused before Theming runs**, unlike Text's routes, which are judged by their answer.
 * The copy is made inside the controller and served by a different request later, so by
 * the time `setBackground` answers there is nothing left to stop. Nothing is copied, and the
 * background stays as it was.
 *
 * **A 400, not a 403**, with the `error` field: the answer Theming itself gives when a
 * background cannot be set. `UserThemeController` is an `OCSController`, and core's
 * `OCSMiddleware` rewraps any 401 or 403 from one into an OCS envelope - on this route, a
 * v1 envelope, which is HTTP 200 - and Theming's page would take that for the new
 * background.
 *
 * **The file is the one Theming would open**: the same path in the same user's folder, so
 * a share mounted for the reader is what the share switches see. A path that does not
 * resolve, or is not a file, is left to Theming, which fails on it before copying anything.
 *
 * Registered globally - Theming's controllers are reachable no other way.
 */
class ThemingBackgroundMiddleware extends Middleware {

	/** `BackgroundService::BACKGROUND_CUSTOM`, the one type whose `value` is a path. */
	private const TYPE_CUSTOM = 'custom';

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
		// `instanceof` never autoloads, so with `theming` disabled this never matches.
		if (!($controller instanceof UserThemeController) || $methodName !== 'setBackground') {
			return;
		}

		if ($this->request->getParam('type') !== self::TYPE_CUSTOM) {
			return;
		}

		$file = $this->background();
		if ($file === null) {
			return;
		}

		try {
			$protected = $this->watermarkService->isDeliveryCandidate($file);
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: could not tell whether a file chosen as a background is protected; refusing it: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			$protected = true;
		}

		if ($protected) {
			throw new ProtectedFileException($this->l->t('This file is watermarked on download, so it cannot be used as a background.'));
		}
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
		if (!($exception instanceof ProtectedFileException)) {
			throw $exception;
		}

		return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * The file Theming is about to copy, resolved as `setFileBackground` resolves it, or
	 * null when Theming will find no file there either.
	 */
	private function background(): ?File {
		$uid = $this->userSession->getUser()?->getUID();
		$path = $this->request->getParam('value');
		if ($uid === null || !is_string($path) || $path === '') {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->get($path);
		} catch (\Throwable) {
			return null;
		}

		return $node instanceof File ? $node : null;
	}
}
