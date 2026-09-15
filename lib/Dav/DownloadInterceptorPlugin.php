<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\Files_Trashbin\Sabre\ITrash;
use OCA\FilesWatermark\Service\DeliveryPadder;
use OCA\FilesWatermark\Service\DeliveryReservation;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\INode;
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

	/**
	 * The copy this request streamed, and the version identifier of the file it came from -
	 * set only when {@see httpGet} handled the download, and consumed by {@see afterGet}.
	 *
	 * Carried on the instance rather than recomputed because `afterGet` would otherwise have
	 * to resolve the node and the mark a second time to answer a question this request has
	 * already answered. One plugin instance serves one request, and a GET is dispatched once
	 * within it, so there is no second download to confuse this with.
	 *
	 * @var ?array{path: string, etag: string}
	 */
	private ?array $served = null;

	/**
	 * The last three are absent on the public-link server, which builds this by hand.
	 *
	 * A reservation is a promise made to *a reader* about a file in their own listing, and a
	 * public link has neither - no uid to measure for, and a `PropFindPlugin` with no session
	 * to advertise a reserved length to. Measuring there would pad downloads against a
	 * promise nothing ever made, which is the one arrangement worse than not padding at all.
	 * Virtual files never reach that server either, so there is nothing to fix there.
	 */
	public function __construct(
		private WatermarkService $watermarkService,
		private IRootFolder $rootFolder,
		private ?DeliveryReservation $reservation = null,
		private ?DeliveryPadder $padder = null,
		private ?IUserSession $userSession = null,
		private LoggerInterface $logger = new NullLogger(),
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
		// And again *after* core has had its say. `FilesPlugin::httpGet` listens on this
		// same event at the default priority of 100, so a larger number here is what puts
		// {@see afterGet} last and lets it overrule what core wrote. See that method for
		// what needs overruling and why it cannot be set up front in `httpGet`.
		$server->on('afterMethod:GET', [$this, 'afterGet'], 200);
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
			$this->trace('deferring a HEAD to core', $request);
			return true;
		}

		try {
			$node = $this->server->tree->getNodeForPath($request->getPath());
		} catch (NotFound) {
			return true;
		}

		$file = $this->fileFor($node);
		if ($file === null) {
			return true;
		}

		try {
			$tmpPath = $this->watermarkService->watermarkForDownload($file);
		} catch (WatermarkRequiredException $e) {
			// The file is marked and the render failed. Serving the stored bytes here would
			// hand the clean original to exactly the reader the mark exists to name, so the
			// download is refused instead. The cause is already in the log; the client gets
			// a 403 rather than a file that looks fine and identifies nobody.
			$this->trace('refusing: the render failed', $request, ['reason' => $e->getMessage()]);
			throw new Forbidden($e->getMessage(), 0, $e);
		}

		if ($tmpPath === null) {
			// Not marked: nothing to do, and core serves the file as it is stored.
			$this->trace('not watermarked, leaving to core', $request, ['path' => $file->getPath()]);
			return true;
		}

		$stream = @fopen($tmpPath, 'rb');
		if ($stream === false) {
			$this->cleanup($tmpPath);
			return true;
		}

		// Delete the temp copy once the response has been flushed to the client.
		register_shutdown_function(fn () => $this->cleanup($tmpPath));

		[$length, $etag] = $this->fitToReservation($file, $tmpPath);

		$this->trace('serving a watermarked copy', $request, [
			'path' => $file->getPath(),
			'storedSize' => $file->getSize(),
			'sentSize' => $length,
			'sentEtag' => $etag,
		]);

		// Tell `afterGet` there is a substituted body to describe.
		$this->served = ['path' => $tmpPath, 'etag' => $etag];

		// Status 200 with the full body deliberately ignores any Range header: the
		// watermarked bytes differ from the original, so byte offsets into the
		// source are meaningless and a partial response would be incoherent.
		$response->setStatus(200);
		$response->setHeader('Content-Type', $file->getMimeType());
		$response->setHeader('Content-Length', (string)$length);
		if (!($node instanceof ITrash)) {
			// **The trashbin names its own downloads.** `TrashbinPlugin` adds a
			// Content-Disposition on `afterMethod:GET` - which still runs after we return
			// false - carrying the file's *original* name rather than the
			// `Frog.jpg.d1786407996` that storage gives it. It adds unconditionally, and
			// `addHeader` appends rather than replaces, so setting ours here would not win
			// the argument, it would send the header twice. Core's `FilesPlugin` does check
			// first, which is why the ordinary path below is still ours to set.
			$response->setHeader(
				'Content-Disposition',
				'attachment; filename="' . addslashes($file->getName()) . '"',
			);
		}
		$response->setBody($stream);

		return false;
	}

	/**
	 * Re-describes a substituted body, after core has finished describing the original one.
	 *
	 * ---------------------------------------------------------------------------
	 * THE BYTES WERE SWAPPED; THE METADATA DESCRIBING THEM WAS NOT.
	 *
	 * A browser saves whatever arrives and never looks at these headers, which is why this
	 * went unnoticed for as long as the Files app was the only thing downloading. A **sync
	 * client checks**, and every check was against the wrong file:
	 *
	 *  - `OC-Checksum` is added by core's `FilesPlugin::httpGet` on `afterMethod:GET` - which
	 *    still runs after our `method:GET` returned false - from `$node->getChecksum()`, the
	 *    *stored* file's. The client hashes what it received, compares, and rejects the
	 *    download as corrupt. This cannot be pre-empted in `httpGet`: core uses `addHeader`,
	 *    which appends, so setting it early sends the header twice instead of winning. Hence
	 *    a second hook at a priority that runs last, and `setHeader`, which replaces.
	 *  - **No `ETag` at all.** Sabre's `CorePlugin` sets it while serving a body, and
	 *    returning false is what skips it. A DAV GET without one leaves the client no
	 *    version to record against what it just stored.
	 * ---------------------------------------------------------------------------
	 *
	 * The etag deliberately reports the **stored** file's version, not a hash of the bytes
	 * actually sent. A watermark carries `{datetime}`, so no two renders of one file are
	 * identical and a content-derived etag would differ on every fetch - the client would
	 * read that as "changed again on the server" and re-download forever. The etag answers
	 * which *version of the file* this is, and that is the version PROPFIND named; the
	 * per-reader rendering is not a new version of it.
	 *
	 * The checksum goes the other way and describes the bytes on the wire, because that is
	 * the only claim a client can verify for itself - and a correct one is worth more than
	 * no header at all, which would simply switch the integrity check off.
	 *
	 * **This does not make the response wholly coherent, and is not meant to.** PROPFIND
	 * still advertises the stored file's *size* while a longer body arrives, which is what
	 * breaks Windows VFS hydration: the client sizes its placeholder from PROPFIND and the
	 * render overflows it. Fixing that means knowing the rendered length before the GET -
	 * a materialised per-reader copy - and is a larger change than this one.
	 */
	public function afterGet(RequestInterface $request, ResponseInterface $response): void {
		if ($this->served === null) {
			return;
		}

		$served = $this->served;
		// Consumed, so a second pass over this instance cannot re-stamp a body it did not
		// substitute.
		$this->served = null;

		$checksum = @hash_file('sha1', $served['path']);
		if ($checksum !== false) {
			$response->setHeader('OC-Checksum', 'SHA1:' . $checksum);
		} elseif ($response->getHeader('OC-Checksum') !== null) {
			// The hash failed, so the only honest options are a header describing bytes that
			// did not go out, or none. Core's describes the original and would fail every
			// client-side check; removing it costs the check and passes.
			$response->removeHeader('OC-Checksum');
		}

		if ($served['etag'] !== '') {
			// `OCP\Files\Node::getEtag()` hands back the bare cache value; the header and the
			// `{DAV:}getetag` property PROPFIND answers with are both the quoted form, and
			// they have to be the same string for the client to match one against the other.
			$response->setHeader('ETag', '"' . trim($served['etag'], '"') . '"');
		}
	}

	/**
	 * Record what a client asked for and what it was given, at debug level.
	 *
	 * ---------------------------------------------------------------------------
	 * THE SYNC CLIENT IS THE ONE CALLER WHOSE SIDE OF THE CONVERSATION IS INVISIBLE.
	 *
	 * A browser that cannot open a download says so on screen. A sync client decides in
	 * private: it compares what a listing promised against what arrived, and when the two
	 * disagree it retries, or gives up, or leaves a placeholder unfilled - and the *server*
	 * sees nothing wrong, because from here every one of those requests succeeded.
	 *
	 * Three separate defects on this path were each found by reasoning backwards from a
	 * client that "could not download", with no way to confirm any of them from the server.
	 * This exists so the next one is read rather than deduced: it records the half of the
	 * exchange the server can see, including the request headers that decide the client's
	 * behaviour and are otherwise nowhere in the log.
	 * ---------------------------------------------------------------------------
	 *
	 * Debug level, so it costs nothing until an administrator turns it on
	 * (`occ log:manage --level debug`) and is not something a busy instance pays for.
	 *
	 * @param array<string, mixed> $context
	 */
	private function trace(string $message, RequestInterface $request, array $context = []): void {
		$this->logger->debug('files_watermark: ' . $message, array_merge([
			// What the client asked for. `Range` is here because this plugin answers a
			// ranged request with the whole file, which is a difference from core worth
			// being able to see; the agent, because behaviour differs by client and the
			// log otherwise cannot tell a browser from a sync run.
			'method' => $request->getMethod(),
			'uri' => $request->getPath(),
			'range' => $request->getHeader('Range') ?? '-',
			'agent' => $request->getHeader('User-Agent') ?? '-',
		], $context));
	}

	/**
	 * Make the rendered copy match the length that was promised for it, if one was.
	 *
	 * ---------------------------------------------------------------------------
	 * WHY A DOWNLOAD HAS A LENGTH TO LIVE UP TO.
	 *
	 * PROPFIND answered this client some time ago with a length, and with virtual files on
	 * Windows that answer has already been spent: the client sized its placeholder from it,
	 * and hydration writes into a buffer of exactly that size. A body of any other length
	 * fails - not as a mismatch the client reports, but as a write past the end of an
	 * allocation.
	 *
	 * So the render is padded up to the promised length rather than sent at its own
	 * ({@see DeliveryPadder}), and the promise is a number measured from an earlier render
	 * and kept in the reservation table ({@see DeliveryReservation}).
	 * ---------------------------------------------------------------------------
	 *
	 * **The first download of a newly marked file is the one that cannot be padded**, because
	 * nothing has measured it yet. It is served at its own length and the measurement is
	 * taken from it, which leaves a sync client one failed attempt that fixes itself: the
	 * next PROPFIND carries both the reserved length and a changed etag, so the client comes
	 * back, and that download fits. A browser never notices either way, which is why this
	 * serves the file rather than refusing it while the reservation is cold.
	 *
	 * A render that overshoots its reservation - or lands under it by less than the format's
	 * smallest legal filler - takes the same path as a cold one: re-measured, served as it
	 * is, correct on the next fetch.
	 *
	 * @return array{0: int, 1: string} the Content-Length to send and the etag to send with it
	 */
	private function fitToReservation(File $file, string $tmpPath): array {
		$rendered = (int)filesize($tmpPath);
		$storedEtag = (string)$file->getEtag();

		if ($this->reservation === null || $this->padder === null || $this->userSession === null) {
			// The public-link server - see the constructor.
			return [$rendered, $storedEtag];
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			// No reader to measure for, so no promise to keep and the old behaviour stands.
			return [$rendered, $storedEtag];
		}

		// The format actually rendered, which is not always the one the *name* claims - a
		// marked file renamed to a different extension still renders as what its bytes are.
		// See {@see WatermarkService::deliveryMime}.
		$mime = $this->watermarkService->deliveryMime($file) ?? $file->getMimeType();
		$config = $this->watermarkService->resolveConfig();

		$reservation = $this->reservation->current($file, $uid, $config);
		if ($reservation !== null) {
			$promised = $reservation->getReservedSize();
			if ($this->padder->padTo($tmpPath, $promised, $mime)) {
				return [$promised, $reservation->getEtag()];
			}
			// The reservation no longer fits this render. Falling through re-measures it.
		}

		$reserved = $this->reservation->record($file, $uid, $config, $rendered, $mime);
		if ($reserved === null) {
			// Nothing could be promised - an unpaddable format, or the row would not write.
			// The download is still correct, just as unpredictable in length as before.
			return [$rendered, $storedEtag];
		}

		// Deliberately the *stored* etag rather than the one just reserved: this body is not
		// the reserved length, so it must not go out labelled as though it were. The next
		// PROPFIND advertises the new etag, and the client fetches again to get a body that
		// matches it.
		return [$rendered, $storedEtag];
	}

	/**
	 * The stored file behind a DAV node, or null when this GET is not one to watermark.
	 *
	 * ---------------------------------------------------------------------------
	 * TWO NODE KINDS, BECAUSE A DELETED FILE IS STILL A MARKED FILE.
	 *
	 * `/remote.php/dav/trashbin/...` is served by the **same** Sabre server this plugin is
	 * registered on - `files_trashbin` contributes its collection to the DAV root through
	 * `info.xml` - so this `method:GET` hook already ran for every download out of the
	 * trash. It simply returned early: a trashed node is an `ITrash`, never an
	 * `OCA\DAV\Connector\Sabre\File`, and the type test was the only thing standing between
	 * a marked file and its clean original. Deleting a file was a way to download it
	 * unwatermarked - and the *preview* in the trash view was watermarked the whole time,
	 * because that goes through `files_trashbin`'s own preview controller and the middleware
	 * wrapping it, which is what made the hole visible.
	 *
	 * A mark is a row against a file id and the trash preserves file ids (it is a move, not
	 * a copy), so the mark is still there and still applies. Nothing about the policy needed
	 * to change; only this resolution did.
	 * ---------------------------------------------------------------------------
	 *
	 * The trashed node is resolved through `IRootFolder::getById()` rather than through
	 * `files_trashbin`'s own manager: a trashed file is an ordinary node at
	 * `/{uid}/files_trashbin/files/...`, the root folder finds it by id like any other, and
	 * this app then owes `files_trashbin` no coupling beyond the `instanceof` above -
	 * which is safe even where that app is disabled, since the class simply never matches.
	 */
	private function fileFor(INode $node): ?File {
		if ($node instanceof DavFile) {
			$file = $node->getNode();

			return $file instanceof File ? $file : null;
		}

		if (!($node instanceof ITrash)) {
			return null;
		}

		foreach ($this->rootFolder->getById($node->getFileId()) as $candidate) {
			// A trashed *folder* is an ITrash too and resolves to a Folder; it is not a
			// download this plugin has anything to say about.
			if ($candidate instanceof File) {
				return $candidate;
			}
		}

		return null;
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
