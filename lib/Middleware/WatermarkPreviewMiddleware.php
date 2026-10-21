<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Middleware;

use OCA\Files_Versions\Controller\PreviewController as VersionsPreviewController;
use OCA\FilesWatermark\Preview\PreviewRequestContext;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Replaces the preview of a marked file with one carrying the viewer's own name.
 *
 * ---------------------------------------------------------------------------
 * WHY A GLOBAL MIDDLEWARE, AND WHY IT BUILDS ITS OWN RESPONSE.
 *
 * A watermark that names the reader cannot go through core's preview cache. That cache is
 * keyed by file id and dimensions and **never by viewer**, so a stamped thumbnail written
 * into it is handed to the next person to open the folder, with the first person's name on
 * it - the exact inversion of what a watermark is for.
 *
 * So the cache keeps doing what it is good at - holding the *clean* preview, which is not
 * reachable by any client except through the endpoints this middleware sits on - and the
 * watermark is applied per response, after it. That also makes the expensive half cached
 * and the cheap half repeated, which is the right way round: rendering a thumbnail from a
 * 30-page PDF is the cost, stamping a 256px image is not.
 *
 * **On 32 there is now a second way to do this, and it is deliberately not taken.**
 * `IPreview::getPreview()` gained a `$cacheResult` argument in 32.0.0, so this app could ask
 * core to render an uncached, already-watermarked preview per viewer. That would move the
 * *whole* render into every request - a fresh thumbnail out of a 30-page PDF for every
 * viewer of every row - where the split above renders once and stamps thereafter. The new
 * argument removes the constraint that forced this design; it does not make it the wrong
 * one, and the cheap half is still the half worth repeating.
 *
 * `registerMiddleware($class, global: true)` (Nextcloud 26+) is what lets an app's
 * middleware run around *core's* controllers. Nothing else in the framework can reach them.
 *
 * The response core produced is discarded rather than modified: `FileDisplayResponse` keeps
 * its file private and streams through a callback, so there are no bytes to intercept. This
 * asks `IPreview` for the same preview itself - which is a cache hit, because the controller
 * has just generated it - and answers with its own body.
 * ---------------------------------------------------------------------------
 *
 * **It fails closed.** If the stamped preview cannot be produced, the response is a 404 and
 * the client shows a generic file-type icon. Passing core's clean preview through instead
 * would publish a readable copy of the file's first page to anybody who can list the folder.
 */
class WatermarkPreviewMiddleware extends Middleware {

	/**
	 * The query parameters clients put a file's etag in to make a preview URL change with the
	 * file: `etag` (the Viewer, the Photos grid), `c` (core's own and Photos' older views) and
	 * `v` (the Files list). Their values are never read - only whether one is there.
	 */
	private const VERSION_PARAMS = ['etag', 'c', 'v'];

	public function __construct(
		private PreviewRequestContext $context,
		private WatermarkService $watermarkService,
		private IPreview $preview,
		private LoggerInterface $logger,
		private IRequest $request,
		private ?IRootFolder $rootFolder = null,
		private ?IUserSession $userSession = null,
	) {
	}

	/**
	 * Note which file a version preview is a version of, before the controller asks for it.
	 *
	 * A source that will not resolve is simply not noted: the controller resolves the same
	 * path itself a moment later and answers 404 for it, so there is no preview to protect.
	 */
	public function beforeController(Controller $controller, string $methodName): void {
		// **The one preview controller named here**, which this class otherwise avoids on
		// principle - see {@see PreviewRequestContext}. The preview event carries the
		// *version's* node, which lives under `files_versions` with a file id of its own;
		// nothing on it says which file it is a version of, so no mark matched and every
		// version thumbnail in the Versions tab went out clean. The controller's `file`
		// argument is the only place the answer exists.
		//
		// `instanceof` never autoloads, so with `files_versions` disabled this simply never
		// matches - the same reasoning the trash checks in lib/Dav rely on.
		if (!($controller instanceof VersionsPreviewController)) {
			return;
		}

		$uid = $this->userSession?->getUser()?->getUID();
		$path = $this->request->getParam('file');
		if ($uid === null || $this->rootFolder === null || !is_string($path) || $path === '') {
			return;
		}

		try {
			$source = $this->rootFolder->getUserFolder($uid)->get($path);
		} catch (\Throwable) {
			return;
		}

		if ($source instanceof File) {
			$this->context->noteVersionSource($source);
		}
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if ($this->context->previewsCleanCopy()) {
			return $this->revalidating($response);
		}

		$file = $this->context->file();
		if ($file === null) {
			// Not a preview request, or a preview of a file that carries no mark. By far the
			// common case, and it costs one null check on every request in the server.
			return $response;
		}

		// A 304, a 404, a redirect: core did not serve an image, so there is nothing to
		// stamp and nothing to leak. 304 in particular must be passed through - the client
		// is being told to reuse what it has, which it got from here.
		if ($response->getStatus() !== Http::STATUS_OK) {
			return $response;
		}

		try {
			return $this->stamped($file, $this->context->subject() ?? $file);
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: could not watermark a preview, refusing to serve it: ' . $e->getMessage(), [
				'exception' => $e,
				'path' => $file->getPath(),
			]);

			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * The watermarked preview, as a response that must not be cached anywhere.
	 *
	 * `no-store` rather than a short max-age: this image names the person looking at it, so
	 * a shared proxy or a second user on the same browser profile must never be able to
	 * produce it again. It is the one place where re-rendering on every scroll is the
	 * cheaper mistake.
	 */
	private function stamped(File $file, File $subject): Response {
		// Exactly what the controller asked core for - size, crop, mode and type - so this is
		// the preview it just rendered and a cache hit, not a different one.
		$preview = $this->context->whileGenerating(
			fn () => $this->preview->getPreview(
				$file,
				$this->requestedWidth(),
				$this->requestedHeight(),
				$this->context->crop() ?? false,
				$this->context->mode() ?? IPreview::MODE_FILL,
				$this->context->mimeType(),
			),
		);

		$bytes = $preview->getContent();
		$mime = $preview->getMimeType();

		$dir = sys_get_temp_dir() . '/nc_watermark_preview_' . bin2hex(random_bytes(8));
		mkdir($dir, 0700, true);
		$src = $dir . '/src';
		$dst = $dir . '/out';

		try {
			file_put_contents($src, $bytes);

			// Measured from the image core actually produced, not from what the client
			// asked for: the request is a hint that core clamps against its own maxima and
			// against the source's real size, so an unscaled request for 4096px can arrive
			// here as a 1024px image and would scale the watermark four times too large.
			$size = @getimagesize($src);
			$shorterSide = $size === false ? $this->context->shorterSide() : min($size[0], $size[1]);

			// The subject, not the node: for an old version the watermark names the file it is a
			// version of, and is decided by that file's mark and share.
			$this->watermarkService->watermarkPreviewImage($subject, $src, $dst, $shorterSide);

			$stamped = file_get_contents($dst);
			if ($stamped === false) {
				throw new \RuntimeException('the watermarked preview could not be read back');
			}
		} finally {
			@unlink($src);
			@unlink($dst);
			@rmdir($dir);
		}

		$response = new DataDisplayResponse($stamped, Http::STATUS_OK, ['Content-Type' => $mime]);
		// The headers are set directly rather than through `cacheFor(0)`, which also emits
		// an `Expires` date and a `Pragma` of its own but reaches into the server container
		// to do it. What matters here is `no-store`, and it is stated once, in full.
		$response->addHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate');
		$response->addHeader('Pragma', 'no-cache');

		return $response;
	}

	/**
	 * Core's clean preview of a file that is not marked *yet*, made unable to outlive a mark.
	 *
	 * ---------------------------------------------------------------------------
	 * `immutable` IS ONLY TRUE WHEN THE ADDRESS CHANGES WITH THE FILE.
	 *
	 * Core and Photos both serve previews with `private, max-age=86400, immutable`: keep it a
	 * day and never ask again. Most callers make that safe by putting the file's etag in the
	 * URL, and marking moves the etag, so a marked file is previewed at a new address that no
	 * browser has cached. **Some callers put no version in at all.** Photos opens the Viewer
	 * on `/apps/photos/api/v1/preview/{id}?x=2048&y=2048`, and its album, face and place
	 * covers are the same. A clean preview fetched there before the file was marked was
	 * reused for a day afterwards, and the photo looked as if marking had done nothing.
	 * ---------------------------------------------------------------------------
	 *
	 * So an unversioned one goes out `private, no-cache` instead: the browser keeps it but
	 * asks every time. Asking is cheap - core's `NotModifiedMiddleware` turns a matching
	 * `If-None-Match` into a 304, and it runs *after* this one (global app middleware is
	 * registered last and `afterController` runs in reverse), so on a marked file the stamped
	 * response has already replaced core's before any 304 could be decided.
	 *
	 * Versioned URLs keep core's header - **unless the preview came through a share.** A
	 * share switch claims the next fetch without moving the etag, so the versioned address
	 * the recipient's browser holds a clean preview under is the same one after the switch
	 * goes on, and the Viewer showed that clean image for a day. The owner's own previews
	 * keep it: no switch ever claims them, and a whole Photos timeline revalidating on every
	 * visit is the cost this avoids.
	 */
	private function revalidating(Response $response): Response {
		if ($response->getStatus() !== Http::STATUS_OK
			|| ($this->carriesVersion() && !$this->context->throughShare())) {
			return $response;
		}

		$response->addHeader('Cache-Control', 'private, no-cache');
		// Core's `Expires` is a day out; `Cache-Control` already outranks it, and leaving it
		// would only describe a lifetime the response no longer has. Null is how core's
		// `addHeader()` deletes a header ("null will delete it"), though its `@param` says
		// `string`; there is no other way to drop one short of rebuilding them all.
		/** @psalm-suppress NullArgument */
		$response->addHeader('Expires', null);

		return $response;
	}

	private function carriesVersion(): bool {
		foreach (self::VERSION_PARAMS as $param) {
			if ((string)$this->request->getParam($param, '') !== '') {
				return true;
			}
		}

		return false;
	}

	/**
	 * The width core was asked for, or -1 - `IPreview`'s "no constraint" - when the event
	 * carried none.
	 *
	 * Width and height are passed through separately. Both used to be the *shorter* side,
	 * so the Viewer's 1920x1080 request was regenerated inside a 1080x1080 box and arrived at
	 * a little over half the width it asked for.
	 */
	private function requestedWidth(): int {
		$width = $this->context->width();
		return $width > 0 ? $width : -1;
	}

	private function requestedHeight(): int {
		$height = $this->context->height();
		return $height > 0 ? $height : -1;
	}
}
