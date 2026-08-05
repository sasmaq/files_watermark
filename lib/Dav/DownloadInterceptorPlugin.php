<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Watermarks marked files on download.
 *
 * A marked file is never served as it is stored: a freshly rendered copy, carrying the
 * name of whoever is fetching it, goes out instead. The web Files app's Download action,
 * desktop and mobile sync clients and direct DAV links all issue a plain `GET` on the file
 * node, so intercepting `method:GET` here is the single point that covers them all. The
 * file on storage is never modified - the watermarked bytes exist only in the temp copy
 * this streams and then deletes.
 *
 * This complements {@see PropFindPlugin} (which serves the marked *status*). The decision
 * and the rendering live in {@see WatermarkService::watermarkForDownload}; this plugin is
 * the thin Sabre adapter that resolves the node, streams the copy and cleans up.
 *
 * Registered on both DAV servers - the authenticated Files server (via
 * {@see \OCA\FilesWatermark\EventListener\SabrePluginAddListener}) and the public-link
 * server behind `public.php/dav` (via
 * {@see \OCA\FilesWatermark\EventListener\SabrePublicPluginAddListener}). Neither needs to
 * be told which it is any more: the mark decides whether to watermark, and who is asking
 * only decides what the watermark says.
 */
class DownloadInterceptorPlugin extends ServerPlugin {

	private ?Server $server = null;

	public function __construct(
		private WatermarkService $watermarkService,
	) {
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		// Hook the same event Sabre's CorePlugin streams file bodies on (`method:GET`),
		// at a lower priority number so we run *first*. Returning false stops CorePlugin
		// from serving the original, but - unlike returning false from `beforeMethod` -
		// Sabre still runs `afterMethod` and flushes our response via `sendResponse`.
		// (A false from `beforeMethod:GET` returns before `sendResponse`, sending 0 bytes.)
		$server->on('method:GET', [$this, 'httpGet'], 90);
	}

	/**
	 * @return bool false when the download was handled (watermarked copy streamed),
	 *              true to let Sabre serve the file normally
	 */
	public function httpGet(RequestInterface $request, ResponseInterface $response): bool {
		if ($this->server === null) {
			return true;
		}

		if ($this->isHeadRequest($request)) {
			return true;
		}

		try {
			$node = $this->server->tree->getNodeForPath($request->getPath());
		} catch (NotFound) {
			return true;
		}

		if (!($node instanceof DavFile)) {
			return true;
		}

		$file = $node->getNode();
		if (!($file instanceof File)) {
			return true;
		}

		try {
			$tmpPath = $this->watermarkService->watermarkForDownload($file);
		} catch (WatermarkRequiredException $e) {
			// The file is marked and the render failed. Serving the stored bytes here would
			// hand the clean original to exactly the reader the mark exists to name, so the
			// download is refused instead. The cause is already in the log; the client gets
			// a 403 rather than a file that looks fine and identifies nobody.
			throw new Forbidden($e->getMessage(), 0, $e);
		}

		if ($tmpPath === null) {
			// Not marked: nothing to do, and core serves the file as it is stored.
			return true;
		}

		$stream = @fopen($tmpPath, 'rb');
		if ($stream === false) {
			$this->cleanup($tmpPath);
			return true;
		}

		// Delete the temp copy once the response has been flushed to the client.
		register_shutdown_function(fn () => $this->cleanup($tmpPath));

		// Status 200 with the full body deliberately ignores any Range header: the
		// watermarked bytes differ from the original, so byte offsets into the
		// source are meaningless and a partial response would be incoherent.
		$response->setStatus(200);
		$response->setHeader('Content-Type', $file->getMimeType());
		$response->setHeader('Content-Length', (string)filesize($tmpPath));
		$response->setHeader(
			'Content-Disposition',
			'attachment; filename="' . addslashes($file->getName()) . '"',
		);
		$response->setBody($stream);

		return false;
	}

	/**
	 * Whether this `method:GET` is really Sabre serving a **HEAD**.
	 *
	 * Sabre implements HEAD by cloning the request, setting its method to GET and
	 * re-dispatching it (`CorePlugin::httpHead`), so a HEAD arrives here indistinguishable
	 * from a download except for the marker it leaves behind. The Files app's download
	 * sends HEAD before GET, so without this every download rendered the whole watermarked
	 * file **twice** and wrote **two** audit rows for one download.
	 *
	 * Deferring to core also makes the headers consistent with the rest of the app:
	 * PROPFIND already reports the stored file's size, never the watermarked copy's, so a
	 * HEAD that answered with the render's length was the odd one out - and paid a full
	 * render for a response with no body.
	 */
	private function isHeadRequest(RequestInterface $request): bool {
		return $request->getHeader('X-Sabre-Original-Method') === 'HEAD';
	}

	private function cleanup(string $tmpPath): void {
		if (file_exists($tmpPath)) {
			@unlink($tmpPath);
			@rmdir(dirname($tmpPath));
		}
	}
}
