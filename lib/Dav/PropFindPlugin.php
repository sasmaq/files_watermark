<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\DAV\Connector\Sabre\Node;
use OCA\Files_Trashbin\Sabre\ITrash;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkMark;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCA\FilesWatermark\Db\WatermarkRendition;
use OCA\FilesWatermark\Service\DeliveryReservation;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sabre\DAV\ICollection;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Exposes a per-file WebDAV property telling the Files client whether a file is marked -
 * that is, whether fetching it produces a watermarked copy. Delivering the status as a node
 * property means the Files app has it the moment a row renders, so the "Apply watermark"
 * `FileAction` can decide `enabled()` synchronously on first evaluation - no async lookup,
 * no relying on Nextcloud re-computing memoized actions after the fact.
 *
 * The property name is `is-watermarked` and stays that way: it is what the Files client
 * already asks for, and renaming a DAV property to sharpen a distinction the user never
 * sees would break every listing fetched by an older bundle. What it means has shifted
 * underneath it, from "these bytes carry a watermark" to "every copy handed out will".
 *
 * A **second** property, `watermark-locked`, answers a different question: may the person
 * looking at this row take the mark off it. That is a second property rather than a wider
 * first one on purpose - `is-watermarked` describes the file and reads the same for
 * everybody, while this one is about the viewer, and folding the two together would make
 * the badge mean something different depending on who is looking at the same file. It is
 * needed because the ownership rule the Files app uses to hide "Remove watermark" stopped
 * being the whole answer once marks began travelling with copies: copying a shared file
 * makes the copier the owner, so ownership says yes and the server says no.
 */
class PropFindPlugin extends ServerPlugin {

	public const WATERMARKED_PROPERTY = '{http://nextcloud.org/ns}is-watermarked';

	/** Whether the mark on this file is one the viewer is not allowed to remove. */
	public const LOCKED_PROPERTY = '{http://nextcloud.org/ns}watermark-locked';

	/**
	 * file id => its mark, or false for a file known to carry none. Primed with one batched
	 * query per folder listing so a directory PROPFIND does not fan out into a query per
	 * child.
	 *
	 * The mark itself rather than a boolean, because both properties are answered from it -
	 * and only marked files produce a row, so this is the same query and the same rows the
	 * id-only version fetched.
	 *
	 * @var array<int, WatermarkMark|false>
	 */
	private array $cache = [];

	/**
	 * file id => the reader's standing reservation, for one listing. Null until primed, which
	 * is the difference that matters: an empty array means "this listing was asked about and
	 * none of it is reserved", and null means "nobody has asked yet, go and look".
	 *
	 * @var ?array<int, WatermarkRendition>
	 */
	private ?array $reservationCache = null;

	/**
	 * @param ?WatermarkService $watermarkService when given, the property answers the wider
	 *                                            question this plugin's *public* instance has to answer - see
	 *                                            {@see willBeWatermarked}. Null on the authenticated server, where a mark is
	 *                                            the whole answer.
	 * @param ?IUserSession $userSession who is asking, for {@see LOCKED_PROPERTY}. Null on
	 *                                   the public-link server, which is built by hand and has no viewer to name -
	 *                                   and no Remove action for the property to govern.
	 */
	public function __construct(
		private WatermarkMarkMapper $markMapper,
		private ?WatermarkService $watermarkService = null,
		private ?IUserSession $userSession = null,
		private ?DeliveryReservation $reservation = null,
		private LoggerInterface $logger = new NullLogger(),
	) {
	}

	public function initialize(Server $server): void {
		$server->on('propFind', [$this, 'propFind']);
	}

	public function propFind(PropFind $propFind, INode $node): void {
		// Before anything about badges: the length and version this download will really
		// have. Unlike the two properties below it is not something the client asked this
		// app for - it is core's own answer, corrected.
		$this->advertiseReservation($propFind, $node);

		$requested = $propFind->getRequestedProperties();
		$wantsWatermarked = in_array(self::WATERMARKED_PROPERTY, $requested, true);
		$wantsLocked = in_array(self::LOCKED_PROPERTY, $requested, true);
		if (!$wantsWatermarked && !$wantsLocked) {
			return;
		}

		// Batching comes **before** the id check, because the one collection that most needs
		// it has no id of its own: the trash root is a bare `ICollection`, so resolving it
		// first and returning early would leave every trashed file to its own query.
		if ($propFind->getDepth() !== 0) {
			$this->cacheListing($node);
		}

		$fileId = $this->fileIdFor($node);
		if ($fileId === null) {
			return;
		}

		if ($wantsWatermarked) {
			$propFind->handle(self::WATERMARKED_PROPERTY, function () use ($fileId, $node): string {
				return $this->willBeWatermarked($fileId, $node instanceof Node ? $node : null) ? '1' : '0';
			});
		}

		if ($wantsLocked) {
			$propFind->handle(self::LOCKED_PROPERTY, function () use ($fileId): string {
				return $this->isLocked($fileId) ? '1' : '0';
			});
		}
	}

	/**
	 * Replace the length and version core reports with the ones the download will have.
	 *
	 * ---------------------------------------------------------------------------
	 * THE LISTING IS WHERE A SYNC CLIENT LEARNS HOW BIG A FILE IS.
	 *
	 * Core answers `{DAV:}getcontentlength` from the stored file. For a marked file that is
	 * the wrong number - the download is rendered on the way out and is longer - and the
	 * Windows client with virtual files acts on it before anything can correct it: it sizes
	 * its placeholder from this answer, and hydration then writes past the end of it.
	 *
	 * So a file that has a reserved length is advertised at that length, and
	 * {@see DownloadInterceptorPlugin} pads the render up to meet it. The two numbers come
	 * from one row, which is what stops them disagreeing.
	 * ---------------------------------------------------------------------------
	 *
	 * The etag has to move with it. A client that has already cached "this file is 11745
	 * bytes, etag X" will not re-read the length while the etag still says X, so the
	 * reservation carries its own - a digest of the stored etag *and* the reserved length -
	 * and the moment a file gains or revises a reservation, the version it is offered under
	 * changes and the client comes back to look.
	 *
	 * `set()` rather than `handle()`: core has already answered both, and `handle()` declines
	 * to touch a property that is no longer unset.
	 *
	 * Silent on the public-link server, which has no reader to have made a promise to - see
	 * {@see DownloadInterceptorPlugin::__construct}.
	 */
	private function advertiseReservation(PropFind $propFind, INode $node): void {
		if ($this->reservation === null || $this->userSession === null || $this->watermarkService === null) {
			return;
		}

		// Nothing to correct unless the length is actually being asked for. This is the one
		// property worth testing: a client that wants the size wants the etag with it, and
		// one that wants neither is not about to download anything.
		if ($propFind->getStatus('{DAV:}getcontentlength') === null) {
			return;
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			$this->bail('no session user', $propFind, $node);
			return;
		}

		$config = $this->watermarkService->resolveConfig();

		// A directory listing arrives here before its children do, which is the moment to
		// fetch all of their reservations - and all of their marks - at once rather than one
		// query per row. The marks are batched here as well as in `propFind` because the two
		// callers are independent: a sync client asks for the length without ever asking for
		// the badge, so nothing else would have primed them.
		if ($node instanceof Directory && $propFind->getDepth() !== 0) {
			$this->cacheListing($node);
			$this->primeReservations($node->getNode(), $uid, $config);
			return;
		}

		if (!($node instanceof DavFile)) {
			return;
		}

		$file = $node->getNode();
		if (!($file instanceof File)) {
			return;
		}

		$fileId = $node->getId();
		if ($fileId === null || !$this->willBeWatermarked($fileId, $node)) {
			// **A reservation outlives the mark that caused it**, and this is what stops that
			// mattering. Taking the watermark off a file makes its download the stored bytes
			// again, at the stored length - but the row measured while it *was* marked is
			// still there and still matches on file and policy, because neither changed.
			// Advertising from it would promise a padded length for a download that is now
			// the plain original. The mark is the thing that decides, so the mark is asked.
			return;
		}

		$reserved = $this->reservationFor($file, $uid, $config);
		if ($reserved === null) {
			// Never measured, or measured for a version of this file or policy that has moved
			// on. Core's answer stands, and the next download re-measures.
			$this->bail('no valid reservation', $propFind, $node, [
				'cachePrimed' => $this->reservationCache !== null,
				'cacheSize' => $this->reservationCache === null ? -1 : count($this->reservationCache),
			]);
			return;
		}

		$propFind->set('{DAV:}getcontentlength', (string)$reserved->getReservedSize(), 200);
		$propFind->set('{DAV:}getetag', '"' . $reserved->getEtag() . '"', 200);

		// The other half of the pair {@see DownloadInterceptorPlugin::trace} records. A
		// client that opens nothing may be failing here rather than on the download, and
		// the difference is not visible from the download side alone.
		$this->logger->debug('files_watermark: advertising a reserved length', [
			'path' => $file->getPath(),
			'storedSize' => $file->getSize(),
			'advertisedSize' => $reserved->getReservedSize(),
			'advertisedEtag' => $reserved->getEtag(),
			'uid' => $uid,
		]);
	}

	/**
	 * Why a *watermarked* file was left with core's answer, at debug level.
	 *
	 * Deliberately narrow. An earlier version of this logged every node it declined, which
	 * buried the one interesting case - a file that will be watermarked and still has no
	 * length to promise - under a line for every ordinary file in every listing. The cases
	 * that fire for unmarked files and for property sets that never asked about length are
	 * the normal path, not a diagnosis.
	 *
	 * It earns its place because the bug it was written for was a sync client and a `curl`
	 * getting different answers to what looked like the same request, and nothing about that
	 * was visible from outside.
	 *
	 * @param array<string, mixed> $context
	 */
	private function bail(string $why, PropFind $propFind, INode $node, array $context = []): void {
		$this->logger->debug('files_watermark: not advertising - ' . $why, array_merge([
			'node' => $propFind->getPath(),
			'class' => $node::class,
			'depth' => $propFind->getDepth(),
			'requested' => implode(',', $propFind->getRequestedProperties()),
		], $context));
	}

	/**
	 * One query for a whole listing's reservations.
	 *
	 * A failure here is not worth propagating: the cache stays empty, every row falls back to
	 * its own lookup, and the listing still renders.
	 */
	private function primeReservations(Folder $folder, string $uid, WatermarkConfig $config): void {
		$filesById = [];
		foreach ($folder->getDirectoryListing() as $child) {
			if ($child instanceof File) {
				$filesById[$child->getId()] = $child;
			}
		}

		try {
			$this->reservationCache = $this->reservation?->currentForListing($filesById, $uid, $config) ?? [];
		} catch (\Throwable) {
			$this->reservationCache = [];
		}
	}

	/**
	 * $file's reservation, from the listing's batch where there was one.
	 *
	 * A primed cache is trusted completely - a miss in it means the batch asked about this id
	 * and found nothing, so asking again would be the same query for the same answer.
	 */
	private function reservationFor(File $file, string $uid, WatermarkConfig $config): ?WatermarkRendition {
		if ($this->reservationCache !== null) {
			return $this->reservationCache[$file->getId()] ?? null;
		}

		try {
			return $this->reservation?->current($file, $uid, $config);
		} catch (\Throwable) {
			// A listing must render whatever this says.
			return null;
		}
	}

	/**
	 * The file id behind a DAV node, or null when it is not a node with one.
	 *
	 * ---------------------------------------------------------------------------
	 * A DELETED FILE IS STILL A MARKED FILE, AND THE BADGE HAS TO SAY SO.
	 *
	 * `/remote.php/dav/trashbin/...` is served by the **same** Sabre server this plugin is
	 * registered on, and the Files client asks the trash listing for every registered DAV
	 * property - ours included. So this hook already ran for every row in the trash view and
	 * simply declined: a trashed node is an `ITrash`, never an
	 * `OCA\DAV\Connector\Sabre\Node`, and the type test was the whole of why the trash
	 * showed no badge on files whose download out of the trash *is* watermarked
	 * ({@see DownloadInterceptorPlugin::fileFor}, which had the identical blind spot on the
	 * identical cause).
	 *
	 * A mark is a row against a file id and the trash preserves file ids - it is a move, not
	 * a copy - so the mark is still there and still applies. Nothing about the policy needed
	 * to change; only this resolution did.
	 * ---------------------------------------------------------------------------
	 *
	 * `Node` is tested first because a `Directory` is both, and the `instanceof ITrash` is
	 * safe on an instance with `files_trashbin` disabled: the interface simply never matches.
	 */
	private function fileIdFor(INode $node): ?int {
		if ($node instanceof Node) {
			return $node->getId();
		}

		if ($node instanceof ITrash) {
			return $node->getFileId();
		}

		return null;
	}

	/**
	 * Prime the cache for one listing, whichever kind of collection it is.
	 *
	 * The two are separate because the ids come from different places - an ordinary
	 * directory can hand over an `OCP\Files\Folder`, and a trash collection cannot - and
	 * `Directory` is checked first because it is both a `Node` and an `ICollection`.
	 */
	private function cacheListing(INode $node): void {
		if ($node instanceof Directory) {
			$this->cacheFolder($node->getNode());
			return;
		}

		if ($node instanceof ICollection) {
			$this->cacheTrashCollection($node);
		}
	}

	/**
	 * Prime the cache from a trash collection's children.
	 *
	 * Driven off `ITrash` per child rather than off the collection's own class, so the trash
	 * root and a trashed folder are handled by the same code and neither
	 * `TrashRoot` nor `TrashFolder` is named here. A collection whose children are not
	 * trashed nodes yields no ids and costs no query, which is what keeps this from firing
	 * on every other `ICollection` the server serves.
	 */
	private function cacheTrashCollection(ICollection $collection): void {
		try {
			$children = $collection->getChildren();
		} catch (\Throwable) {
			// A listing must render whatever this says; the per-node fallback still answers.
			return;
		}

		$childIds = [];
		foreach ($children as $child) {
			if ($child instanceof ITrash) {
				$childIds[] = $child->getFileId();
			}
		}

		$this->primeCache($childIds);
	}

	/**
	 * Whether the viewer is barred from removing this file's mark.
	 *
	 * Only an *inherited* mark can lock anybody out, so an unmarked file and a file marked
	 * in the ordinary way both answer false and the Files app's existing ownership rule goes
	 * on governing them untouched. The rule itself is {@see WatermarkMark::isForeignTo},
	 * which is also what the API enforces - one definition, so the offered button and the
	 * server's answer cannot drift apart.
	 *
	 * **With no session this is false, not true.** The only server where that happens is the
	 * public-link one, which offers no Remove action to lock; answering true there would
	 * report every inherited mark as locked to a page that has nothing to do with it.
	 */
	private function isLocked(int $fileId): bool {
		$uid = $this->userSession?->getUser()?->getUID();
		if ($uid === null) {
			return false;
		}

		$mark = $this->markFor($fileId);

		return $mark !== false && $mark->isForeignTo($uid);
	}

	/**
	 * Whether fetching `$node` produces a watermarked copy.
	 *
	 * ---------------------------------------------------------------------------
	 * THE ANSWER IS NOT THE SAME ON BOTH DAV SERVERS.
	 *
	 * On the authenticated server a mark is the whole story: the two share switches
	 * watermark a *recipient's* fetch, and the owner browsing their own files is not that
	 * recipient. Answering anything wider there would badge every file in the owner's home
	 * the moment they ticked "watermark public links".
	 *
	 * On the **public** server the request being served *is* the share, so the switch is
	 * live for every file behind the link, marked or not. A property that reported only
	 * marks would leave a visitor's whole listing unbadged on exactly the installs that
	 * watermark all of it - the case the setting exists for.
	 *
	 * `isDeliveryCandidate()` is the same question delivery itself asks, with the policy's
	 * own scope (type filter, folder tag) applied, so the badge and the file that arrives
	 * cannot disagree.
	 * ---------------------------------------------------------------------------
	 *
	 * The batched mark lookup is still consulted first, and answers most rows without
	 * touching the service at all.
	 */
	private function willBeWatermarked(int $fileId, ?Node $node): bool {
		if ($this->markFor($fileId) !== false) {
			return true;
		}

		if ($this->watermarkService === null || $node === null) {
			// **A trashed node arrives here with no `$node`, and mark-only is the right
			// answer for it.** The share switches describe a file being handed to somebody
			// through a share, and a trash listing is the owner looking at their own deleted
			// files - there is no such fetch to describe.
			return false;
		}

		try {
			// `getNode()` rather than `getFileInfo()`: an `OCP\Files\Node` *is* a `FileInfo`,
			// it is what every other caller of this method hands it, and it is the one of
			// the two that this plugin's own folder batching already uses.
			return $this->watermarkService->isForcedByShare($node->getNode());
		} catch (\Throwable) {
			// A listing must render whatever this says. The badge is an indicator, and the
			// download path decides the real answer for itself a moment later.
			return false;
		}
	}

	private function cacheFolder(Folder $folder): void {
		$childIds = array_map(
			static fn ($child) => $child->getId(),
			$folder->getDirectoryListing(),
		);
		$this->primeCache($childIds);
	}

	/**
	 * One batched lookup for a whole listing's ids.
	 *
	 * @param int[] $childIds
	 */
	private function primeCache(array $childIds): void {
		if ($childIds === []) {
			return;
		}

		foreach ($this->markMapper->findByFileIds($childIds) as $id => $mark) {
			$this->cache[$id] = $mark;
		}
		// Everything else in the listing is known *not* marked - record it so the
		// per-node handler never falls back to a second query.
		foreach ($childIds as $id) {
			$this->cache[$id] ??= false;
		}
	}

	/**
	 * $fileId's mark, or false when it has none.
	 *
	 * Asked through the batch query with a single id rather than through
	 * `WatermarkMarkMapper::findByFileId()`: one call shape means the cache above and the
	 * fallback here cannot disagree about what was already asked. Both properties read this,
	 * so a row costs one lookup however many of them the client asked for.
	 *
	 * @return WatermarkMark|false
	 */
	private function markFor(int $fileId): WatermarkMark|false {
		if (!array_key_exists($fileId, $this->cache)) {
			$this->cache[$fileId] = $this->markMapper->findByFileIds([$fileId])[$fileId] ?? false;
		}
		return $this->cache[$fileId];
	}
}
