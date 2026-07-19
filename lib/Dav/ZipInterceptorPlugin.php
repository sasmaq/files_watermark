<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OC\Streamer;
use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\Node as DavNode;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDateTimeZone;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Watermarks the members of a folder / multi-file download archive.
 *
 * {@see DownloadInterceptorPlugin} covers single-file GETs, but downloading a folder (or a
 * multi-file selection) is served by core's `ZipFolderPlugin`, which streams each member
 * straight from `$node->fopen('rb')` — the interceptor never sees those reads, so every
 * archive shipped clean originals regardless of trigger. This plugin claims the archive
 * request first (priority 95 against core's 100) and rebuilds the same archive with a
 * watermarked copy substituted for each member the policy applies to.
 *
 * It deliberately mirrors `ZipFolderPlugin`'s request parsing (Accept header / `?accept=`,
 * the `files=` + `X-NC-Files` member filter, archive naming and root-path handling) so an
 * archive is byte-for-byte the same shape as core's, only with watermarked members. When
 * no delivery trigger applies it defers to core rather than duplicating the work.
 *
 * `on_share` must never leak a clean original, so members are rendered *before* any bytes
 * go out: a failed render can then abort with a real 403 instead of a truncated archive.
 * That costs a bounded amount of temp disk, which is what {@see MAX_MEMBERS} and
 * {@see MAX_BYTES} cap. `on_download` keeps its best-effort contract and degrades to core's
 * plain archive when the caps are exceeded.
 *
 * Registered on both DAV servers, with $publicContext set on the public one — see
 * {@see DownloadInterceptorPlugin} for why that flag is needed.
 */
class ZipInterceptorPlugin extends ServerPlugin {

    /**
     * Ceilings on the pre-render pass. A folder download can hold arbitrarily many files,
     * and each watermarked member costs one temp copy plus a full render; without a cap a
     * single request could exhaust CPU and the temp filesystem.
     */
    private const MAX_MEMBERS = 200;
    private const MAX_BYTES   = 268435456; // 256 MiB of source content

    private ?Server $server = null;
    private bool $handled = false;

    /** @var string[] temp copies to delete once the archive has been streamed */
    private array $tmpPaths = [];

    public function __construct(
        private WatermarkService $watermarkService,
        private IDateTimeZone $dateTimeZone,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
        private bool $publicContext = false,
    ) {
    }

    public function initialize(Server $server): void {
        $this->server = $server;
        // Ahead of core's ZipFolderPlugin (100); returning false there keeps it from
        // streaming its own unwatermarked archive.
        $server->on('method:GET', [$this, 'httpGet'], 95);
        // The archive is written straight to the output buffer, so — exactly as
        // ZipFolderPlugin does — Sabre must be told not to send a response on top of it.
        $server->on('afterMethod:GET', [$this, 'afterGet'], 900);
    }

    /**
     * @return bool false when this plugin streamed the archive, true to let core handle it
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

        if (!($node instanceof DavDirectory)) {
            return true;
        }

        $archiveType = $this->archiveType($request);
        if ($archiveType === null) {
            return true;
        }

        $files = $this->memberFilter($request);
        if ($files === null) {
            // Malformed filter — let core parse it and produce the same complaint.
            return true;
        }

        $folder = $node->getNode();

        // Coarse gate: with no delivery-triggered policy anywhere, no member of any archive
        // can need a watermark (on_demand / on_upload burn it into the stored bytes, so a
        // plain archive already carries it) and core's path is left completely untouched.
        //
        // This deliberately does *not* test the container. `deliveryApplies($folder)` was
        // the gate here and it leaked: a shared *single file* is mounted in the recipient's
        // own home, so the folder reports "owner access" under on_share while the member
        // itself is a received share — every "download selected" on a single-file share
        // shipped the clean original. Only members can answer this, and preRender asks them
        // one by one; when it finds nothing to substitute we hand the request back to core
        // below, so being permissive here costs nothing.
        if (!$this->watermarkService->hasDeliveryTriggerConfigured()) {
            return true;
        }

        // Core dispatches this so apps can veto a folder download; honour it identically,
        // otherwise taking over would silently bypass those vetoes.
        $event = new BeforeZipCreatedEvent($folder, $files);
        $this->eventDispatcher->dispatchTyped($event);
        if (!$event->isSuccessful() || $event->getErrorMessage() !== null) {
            $errorMessage = $event->getErrorMessage();
            if ($errorMessage === null) {
                return true;
            }
            throw new Forbidden($errorMessage);
        }

        try {
            $content = $this->members($node, $folder, $files);
        } catch (NotFound) {
            return true;
        }

        // Full-folder downloads nest everything under the folder's own name, selections
        // are flat — same rule as core, so archives keep their familiar shape.
        $wholeFolder  = $files === [];
        $archiveName  = $wholeFolder ? $folder->getName() : 'download';
        $rootPath     = $wholeFolder ? dirname($folder->getPath()) : $folder->getPath();

        try {
            $rendered = $this->preRender($content);
        } catch (WatermarkRequiredException $e) {
            // on_share: a member that had to be watermarked could not be. Nothing has been
            // written yet, so this is a clean denial rather than a truncated download.
            $this->cleanup();
            $this->logger->warning('files_watermark: denying archive download, a member could not be watermarked', [
                'path' => $e->getPath(),
            ]);
            throw new Forbidden('This shared folder is only available watermarked, which could not be generated.');
        } catch (ArchiveTooLargeException $e) {
            $this->cleanup();
            // Best-effort trigger: fall back to core's plain archive rather than failing
            // the download outright. on_share never reaches here (preRender denies first).
            $this->logger->warning('files_watermark: archive too large to watermark, serving it unwatermarked', [
                'reason' => $e->getMessage(),
                'path'   => $folder->getPath(),
            ]);
            return true;
        }

        if ($rendered === []) {
            // No member needed substituting — every one would be streamed from its own
            // bytes, so core's archive is identical to the one we would build. Hand it
            // back rather than duplicating the work. (on_share never reaches here with a
            // member it failed to render: preRender denies first.)
            $this->cleanup();
            return true;
        }

        $this->handled = true;

        try {
            $streamer = new Streamer($archiveType === 'tar', -1, count($content), $this->dateTimeZone);
            $streamer->sendHeaders($archiveName);
            if ($wholeFolder) {
                $streamer->addEmptyDir($archiveName);
            }
            foreach ($content as $member) {
                $this->streamNode($streamer, $member, $rootPath, $rendered);
            }
            $streamer->finalize();
        } finally {
            $this->cleanup();
        }

        return false;
    }

    /**
     * Suppress Sabre's own response for a request whose archive we already wrote.
     *
     * @return bool false when this plugin handled the request
     */
    public function afterGet(RequestInterface $request, ResponseInterface $response): bool {
        return !$this->handled;
    }

    /**
     * 'zip' / 'tar' when the request asks for an archive, null otherwise.
     *
     * The `accept` query parameter overrides the header because a plain browser link
     * cannot set headers — this is how core's folder-download URLs are built.
     */
    private function archiveType(RequestInterface $request): ?string {
        $accept      = $request->getHeaderAsArray('Accept');
        $acceptParam = $request->getQueryParameters()['accept'] ?? '';
        if ($acceptParam !== '') {
            $accept = array_map(static fn (string $name): string => strtolower(trim($name)), explode(',', $acceptParam));
        }

        if (array_intersect(['application/zip', 'zip'], $accept) !== []) {
            return 'zip';
        }
        if (array_intersect(['application/x-tar', 'tar'], $accept) !== []) {
            return 'tar';
        }
        return null;
    }

    /**
     * The requested member filter: [] for a whole-folder download, a list of child names
     * for a selection, or null when the parameter is malformed (defer to core).
     *
     * @return string[]|null
     */
    private function memberFilter(RequestInterface $request): ?array {
        $files      = $request->getHeaderAsArray('X-NC-Files');
        $filesParam = $request->getQueryParameters()['files'] ?? '';

        if ($filesParam !== '') {
            $decoded = json_decode($filesParam);
            $files   = is_array($decoded) ? $decoded : [$decoded];
        }

        foreach ($files as $file) {
            if (!is_string($file)) {
                return null;
            }
        }

        return array_values($files);
    }

    /**
     * Resolve the top-level nodes to put in the archive.
     *
     * @param string[] $files
     * @return Node[]
     */
    private function members(DavDirectory $node, Folder $folder, array $files): array {
        if ($files === []) {
            return $folder->getDirectoryListing();
        }

        $content = [];
        foreach ($files as $path) {
            $child = $node->getChild($path);
            if (!($child instanceof DavNode)) {
                throw new NotFound('Unexpected child node');
            }
            $content[] = $child->getNode();
        }
        return $content;
    }

    /**
     * Render every member the policy applies to, before a single byte is sent.
     *
     * Doing this up front is what makes a clean 403 possible for `on_share`; streaming
     * lazily would only ever produce a truncated archive once headers were out.
     *
     * @param Node[] $content
     * @return array<int, string> file id → path of the watermarked temp copy
     * @throws WatermarkRequiredException an on_share member could not be watermarked
     * @throws ArchiveTooLargeException   the caps were exceeded (on_download only)
     */
    private function preRender(array $content): array {
        $rendered = [];
        $count    = 0;
        $bytes    = 0;

        foreach ($this->flatten($content) as $file) {
            $trigger = $this->watermarkService->deliveryTriggerFor($file, $this->publicContext);
            if ($trigger === null || !$this->watermarkService->isSupported($file->getMimeType())) {
                // Never a candidate — streamed untouched, and not counted against the caps.
                continue;
            }

            $count++;
            $bytes += max(0, $file->getSize());
            if ($count > self::MAX_MEMBERS || $bytes > self::MAX_BYTES) {
                if ($trigger === 'on_share') {
                    throw new WatermarkRequiredException($file->getPath());
                }
                throw new ArchiveTooLargeException(
                    "archive exceeds the watermarking cap ($count members, $bytes bytes)"
                );
            }

            $tmpPath = $this->watermarkService->watermarkForDownload($file, $this->publicContext);
            if ($tmpPath === null) {
                // Either the config excludes this file (fine — stream it as-is) or the
                // render failed. Under on_share the two are indistinguishable here, and
                // shipping the original on a failed render is exactly the leak we guard
                // against, so deny; watermarkForDownload has already logged the cause.
                if ($trigger === 'on_share' && $this->watermarkService->deliveryTrigger($file, $this->publicContext) === 'on_share') {
                    throw new WatermarkRequiredException($file->getPath());
                }
                continue;
            }

            $this->tmpPaths[]              = $tmpPath;
            $rendered[$file->getId()] = $tmpPath;
        }

        return $rendered;
    }

    /**
     * Depth-first walk of every File under the given nodes, matching the order and reach
     * of the archive itself so the pre-render pass and the stream agree on the member set.
     *
     * @param Node[] $nodes
     * @return \Generator<File>
     */
    private function flatten(array $nodes): \Generator {
        foreach ($nodes as $node) {
            if ($node instanceof File) {
                yield $node;
            } elseif ($node instanceof Folder) {
                yield from $this->flatten($node->getDirectoryListing());
            }
        }
    }

    /**
     * @param array<int, string> $rendered
     */
    private function streamNode(Streamer $streamer, Node $node, string $rootPath, array $rendered): void {
        $filename = str_replace($rootPath, '', $node->getPath());
        $mtime    = $node->getMTime();

        if ($node instanceof Folder) {
            $streamer->addEmptyDir($filename, $mtime);
            foreach ($node->getDirectoryListing() as $child) {
                $this->streamNode($streamer, $child, $rootPath, $rendered);
            }
            return;
        }

        if (!($node instanceof File)) {
            return;
        }

        $tmpPath = $rendered[$node->getId()] ?? null;

        if ($tmpPath !== null) {
            $stream = @fopen($tmpPath, 'rb');
            // Tar records the size up front (zip derives it while streaming), so it must
            // be the *watermarked* length or the archive is corrupt.
            $size = filesize($tmpPath);
        } else {
            $stream = $node->fopen('rb');
            $size   = $node->getSize();
        }

        if ($stream === false) {
            $this->logger->info('files_watermark: cannot read file for archive stream', [
                'path' => $node->getPath(),
            ]);
            throw new ServiceUnavailable('Requested file can currently not be accessed.');
        }

        $streamer->addFileFromStream($stream, $filename, $size, $mtime);
    }

    private function cleanup(): void {
        foreach ($this->tmpPaths as $tmpPath) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
                @rmdir(dirname($tmpPath));
            }
        }
        $this->tmpPaths = [];
    }
}
