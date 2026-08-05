<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Middleware;

use OCA\FilesWatermark\Preview\PreviewRequestContext;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Files\File;
use OCP\IPreview;
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
 * it - the exact inversion of what a watermark is for. `IPreview::getPreview()` grew a
 * `$cacheResult` argument for precisely this, in **32.0.0**; this app targets 31, so there
 * is no supported way to ask core for an uncached preview here.
 *
 * So the cache keeps doing what it is good at - holding the *clean* preview, which is not
 * reachable by any client except through the endpoints this middleware sits on - and the
 * watermark is applied per response, after it. That also makes the expensive half cached
 * and the cheap half repeated, which is the right way round: rendering a thumbnail from a
 * 30-page PDF is the cost, stamping a 256px image is not.
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

	public function __construct(
		private PreviewRequestContext $context,
		private WatermarkService $watermarkService,
		private IPreview $preview,
		private LoggerInterface $logger,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
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
			return $this->stamped($file);
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
	private function stamped(File $file): Response {
		$preview = $this->context->whileGenerating(
			fn () => $this->preview->getPreview($file, $this->requestedWidth(), $this->requestedHeight()),
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

			$this->watermarkService->watermarkPreviewImage($file, $src, $dst, $shorterSide);

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
	 * Zero means "core's default", which is what it also means to `IPreview::getPreview()`
	 * when a caller does not care - so an event that carried no dimensions passes the lack
	 * of them straight through rather than inventing a size.
	 */
	private function requestedWidth(): int {
		return max(-1, $this->context->shorterSide());
	}

	private function requestedHeight(): int {
		return max(-1, $this->context->shorterSide());
	}
}
