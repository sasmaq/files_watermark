<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Watermarks files on download.
 *
 * The `on_download` trigger streams a freshly watermarked copy in place of the
 * original whenever a file is fetched over WebDAV — the web Files app's Download
 * action, desktop/mobile sync clients and direct DAV links all issue a plain
 * `GET` on the file node, so intercepting `beforeMethod:GET` here is the single
 * point that covers them all. The original on storage is never modified; the
 * watermarked bytes live only in the streamed temp copy.
 *
 * This complements {@see PropFindPlugin} (which serves the watermarked *status*).
 * The decision and rendering — supported-type check, trigger gating and watermark
 * generation — live in {@see WatermarkService::watermarkForDownload}; this plugin is
 * the thin Sabre adapter that resolves the node, streams the copy and cleans up.
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
        // from serving the original, but — unlike returning false from `beforeMethod` —
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

        $tmpPath = $this->watermarkService->watermarkForDownload($file);
        if ($tmpPath === null) {
            // No watermarked copy was produced. For `on_share` a recipient must never
            // receive the clean original — so if the file *should* have been
            // watermarked for this shared access but couldn't be (e.g. a PDF the
            // renderer can't parse), deny the fetch instead of leaking the original.
            // This closes the viewer/inline-view bypass. (`on_download` keeps its
            // best-effort fallback of serving the original on failure.)
            if ($this->watermarkService->deliveryTrigger($file) === 'on_share') {
                throw new Forbidden('This shared file is only available watermarked, which could not be generated.');
            }
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
        $response->setHeader('Content-Length', (string) filesize($tmpPath));
        $response->setHeader(
            'Content-Disposition',
            'attachment; filename="' . addslashes($file->getName()) . '"',
        );
        $response->setBody($stream);

        return false;
    }

    private function cleanup(string $tmpPath): void {
        if (file_exists($tmpPath)) {
            @unlink($tmpPath);
            @rmdir(dirname($tmpPath));
        }
    }
}
