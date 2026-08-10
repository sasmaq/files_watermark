<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OC\Streamer;
use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\Node as DavNode;
use OCA\FilesWatermark\Service\ArchiveLimits;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\File;
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
 * straight from `$node->fopen('rb')` - the interceptor never sees those reads, so every
 * archive shipped clean originals regardless of trigger. This plugin claims the archive
 * request first (priority 95 against core's 100) and rebuilds the same archive with a
 * watermarked copy substituted for each member the policy applies to.
 *
 * It deliberately mirrors `ZipFolderPlugin`'s request parsing (Accept header / `?accept=`,
 * the `files=` + `X-NC-Files` member filter, archive naming and root-path handling) so an
 * archive is byte-for-byte the same shape as core's, only with watermarked members. When
 * no delivery trigger applies it defers to core rather than duplicating the work.
 *
 * A marked member must never leave as a clean original, so members are rendered *before*
 * any bytes go out: a failed render can then abort with a real 403 instead of a truncated
 * archive. That costs a bounded amount of temp disk, which is what {@see ArchiveLimits}
 * caps - defaults an admin can raise or lower per host. Exceeding a cap denies the archive
 * rather than falling back to core's plain one, because that fallback is a bulk leak of
 * exactly the files the marks protect.
 *
 * Registered on both DAV servers, and neither instance needs to know which it is. A mark
 * applies to every reader, so a public-link archive and an owner's own are the same case;
 * and where the *share* is what calls for the watermark, the question is asked of the
 * request rather than of the plugin - see {@see \OCA\FilesWatermark\Service\ShareAccess}.
 */
class ZipInterceptorPlugin extends ServerPlugin {

	private ?Server $server = null;
	private bool $handled = false;

	/** @var string[] temp copies to delete once the archive has been streamed */
	private array $tmpPaths = [];

	public function __construct(
		private WatermarkService $watermarkService,
		private IDateTimeZone $dateTimeZone,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private ArchiveLimits $limits,
	) {
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		// Ahead of core's ZipFolderPlugin (100); returning false there keeps it from
		// streaming its own unwatermarked archive.
		$server->on('method:GET', [$this, 'httpGet'], 95);
		// The archive is written straight to the output buffer, so - exactly as
		// ZipFolderPlugin does - Sabre must be told not to send a response on top of it.
		$server->on('afterMethod:GET', [$this, 'afterGet'], 900);
	}

	/**
	 * @return bool false when this plugin streamed the archive, true to let core handle it
	 */
	public function httpGet(RequestInterface $request, ResponseInterface $response): bool {
		if ($this->server === null) {
			return true;
		}

		// Sabre serves HEAD by re-dispatching the request as a GET, so a HEAD on a folder
		// would build the entire archive - rendering every member - for a response with no
		// body, and record an audit row per member while doing it. See
		// DownloadInterceptorPlugin::isHeadRequest().
		if ($request->getHeader('X-Sabre-Original-Method') === 'HEAD') {
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
			// Malformed filter - let core parse it and produce the same complaint.
			return true;
		}

		$folder = $node->getNode();

		// There is deliberately no coarse gate ahead of this. The one that used to sit here
		// tested the *container*, and it leaked: a shared single file is mounted in the
		// recipient's own home, so the folder reported owner access while the member itself
		// was a received share, and every "download selected" on a single-file share shipped
		// the clean original. Only the members can answer the question, and preRender asks
		// them in one batched query; when nothing is marked the request goes back to core
		// below, so being permissive here costs a query rather than a leak.

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
		// are flat - same rule as core, so archives keep their familiar shape.
		$wholeFolder = $files === [];
		$archiveName = $wholeFolder ? $folder->getName() : 'download';
		$rootPath = $wholeFolder ? dirname($folder->getPath()) : $folder->getPath();

		try {
			$rendered = $this->preRender($content);
		} catch (WatermarkRequiredException $e) {
			// A marked member could not be watermarked - a failed render, or one the caps
			// refused to attempt. Nothing has been written yet, so this is a clean denial
			// rather than a truncated download.
			$this->cleanup();
			$this->logger->warning('files_watermark: denying archive download, a member could not be watermarked', [
				'path' => $e->getPath(),
				'reason' => $e->getMessage(),
			]);
			throw new Forbidden('This folder contains a watermarked file whose watermark could not be generated, so it cannot be downloaded as an archive.');
		}

		if ($rendered === []) {
			// No member is marked - every one would be streamed from its own bytes, so
			// core's archive is identical to the one we would build. Hand it back rather
			// than duplicating the work.
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
	 * cannot set headers - this is how core's folder-download URLs are built.
	 */
	private function archiveType(RequestInterface $request): ?string {
		$accept = $request->getHeaderAsArray('Accept');
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
	 * @return list<string>|null
	 */
	private function memberFilter(RequestInterface $request): ?array {
		$files = $request->getHeaderAsArray('X-NC-Files');
		$filesParam = $request->getQueryParameters()['files'] ?? '';

		if ($filesParam !== '') {
			$decoded = json_decode($filesParam);
			$files = is_array($decoded) ? $decoded : [$decoded];
		}

		// Rebuilt entry by entry rather than handed back with array_values(): the source is
		// either a header array or whatever json_decode() made of a query parameter, so
		// "every entry is a string" is only true once each one has been looked at - which is
		// exactly what BeforeZipCreatedEvent's list<string> asks for.
		$members = [];
		foreach ($files as $file) {
			if (!is_string($file)) {
				return null;
			}
			$members[] = $file;
		}

		return $members;
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
	 * Render every marked member, before a single byte is sent.
	 *
	 * Doing this up front is what makes a clean 403 possible; streaming lazily would only
	 * ever produce a truncated archive once the headers were out.
	 *
	 * @param Node[] $content
	 * @return array<int, string> file id → path of the watermarked temp copy
	 * @throws WatermarkRequiredException a marked member could not be watermarked, or the
	 *                                    caps refused to render it
	 */
	private function preRender(array $content): array {
		$rendered = [];
		$count = 0;
		$bytes = 0;

		// Read once per request, not once per member: the ceilings must not move
		// underneath a walk that is already half-rendered, and a misconfigured value
		// should warn once rather than once per file.
		$maxMembers = $this->limits->maxMembers();
		$maxBytes = $this->limits->maxBytes();

		foreach ($this->deliveryMembers($content) as $file) {
			$count++;
			$bytes += max(0, $file->getSize());
			if ($count > $maxMembers || $bytes > $maxBytes) {
				// **The caps deny now; they used to degrade.** Falling back to core's plain
				// archive was defensible when the cap could only be reached by a policy that
				// watermarked on the way out. It is not defensible for a marked file: the
				// fallback ships precisely the clean originals the marks were placed to
				// prevent, and it does it silently, in bulk, at the moment the download is
				// big enough for nobody to check.
				throw new WatermarkRequiredException(
					$file->getPath(),
					"archive exceeds the watermarking cap ($count members, $bytes bytes)",
				);
			}

			// Throws WatermarkRequiredException of its own if the render fails, which is the
			// same denial by a different route.
			$tmpPath = $this->watermarkService->watermarkForDownload($file);
			if ($tmpPath === null) {
				// The mark went away between the batch query and here. Vanishingly rare and
				// entirely benign: the file is not marked, so streaming it as stored is
				// exactly right.
				continue;
			}

			$this->tmpPaths[] = $tmpPath;
			$rendered[$file->getId()] = $tmpPath;
		}

		return $rendered;
	}

	/**
	 * The members of this archive that have to be watermarked, in walk order.
	 *
	 * Two reasons a member qualifies, exactly as for a single download: it carries a mark, or
	 * the fetch itself is a share the policy watermarks. The whole point of asking per member
	 * rather than per archive is that the second reason is not a property of the folder - a
	 * shared *file* is mounted in the recipient's own home, so a selection download can mix
	 * received shares with the recipient's own files, and only the members can say which is
	 * which.
	 *
	 * The marks are still one query for the whole archive rather than one per member: a
	 * folder download of a few hundred files is the ordinary case, and asking the mark table
	 * per file is what would make this plugin the slowest thing in a download. The share test
	 * costs nothing per member - the storage is already resolved and the policy is memoised.
	 *
	 * @param Node[] $content
	 * @return File[]
	 */
	private function deliveryMembers(array $content): array {
		$candidates = [];
		foreach ($this->flatten($content) as $file) {
			$candidates[$file->getId()] = $file;
		}

		if ($candidates === []) {
			return [];
		}

		$marked = array_flip($this->watermarkService->markedFileIds(array_keys($candidates)));

		$members = [];
		foreach ($candidates as $id => $file) {
			if (isset($marked[$id])) {
				// A marked member is asked what it *holds*, not what it is called: a rename to
				// an unwatermarkable extension used to drop it from this list silently, and an
				// archive is exactly where that goes unnoticed. The read only happens for a
				// marked file whose name already says "nothing to do here", so the ordinary
				// folder download still costs one mark query and no reads.
				// {@see \OCA\FilesWatermark\Service\WatermarkService::deliveryMime}
				if ($this->watermarkService->deliveryMime($file) !== null) {
					$members[] = $file;
				}

				continue;
			}

			// Unmarked: only the share switches can pull it in, and those are a rule about
			// *this fetch* rather than about the file, so the cached type is answer enough.
			if ($this->watermarkService->isSupported($file->getMimeType())
				&& $this->watermarkService->isForcedByShare($file)) {
				$members[] = $file;
			}
		}

		return $members;
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
		$mtime = $node->getMTime();

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
			$size = $node->getSize();
		}

		// `filesize()` returns false when the rendered temp file has gone missing between
		// preRender and here. Tar writes the length into the header before the bytes, so a
		// wrong one corrupts the archive silently - refused on the same path as a stream
		// that will not open, rather than shipped as a broken download.
		if ($stream === false || $size === false) {
			if ($stream !== false) {
				fclose($stream);
			}
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
