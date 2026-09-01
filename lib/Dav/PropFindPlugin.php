<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\Node;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\Folder;
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
 */
class PropFindPlugin extends ServerPlugin {

	public const WATERMARKED_PROPERTY = '{http://nextcloud.org/ns}is-watermarked';

	/**
	 * file id => marked. Primed with one batched query per folder listing so a
	 * directory PROPFIND does not fan out into a query per child.
	 *
	 * @var array<int, bool>
	 */
	private array $cache = [];

	/**
	 * @param ?WatermarkService $watermarkService when given, the property answers the wider
	 *                                            question this plugin's *public* instance has to answer - see
	 *                                            {@see willBeWatermarked}. Null on the authenticated server, where a mark is
	 *                                            the whole answer.
	 */
	public function __construct(
		private WatermarkMarkMapper $markMapper,
		private ?WatermarkService $watermarkService = null,
	) {
	}

	public function initialize(Server $server): void {
		$server->on('propFind', [$this, 'propFind']);
	}

	public function propFind(PropFind $propFind, INode $node): void {
		if (!in_array(self::WATERMARKED_PROPERTY, $propFind->getRequestedProperties(), true)) {
			return;
		}

		if (!($node instanceof Node)) {
			return;
		}

		// On a folder listing, resolve every child's status in a single query up front.
		if ($node instanceof Directory && $propFind->getDepth() !== 0) {
			$this->cacheFolder($node->getNode());
		}

		$propFind->handle(self::WATERMARKED_PROPERTY, function () use ($node): string {
			return $this->willBeWatermarked($node) ? '1' : '0';
		});
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
	private function willBeWatermarked(Node $node): bool {
		if ($this->isMarked($node->getId())) {
			return true;
		}

		if ($this->watermarkService === null) {
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
		if ($childIds === []) {
			return;
		}

		foreach ($this->markMapper->markedFileIds($childIds) as $id) {
			$this->cache[$id] = true;
		}
		// Everything else in the folder is known *not* marked - record it so the
		// per-node handler never falls back to a second query.
		foreach ($childIds as $id) {
			$this->cache[$id] ??= false;
		}
	}

	/**
	 * Asked through the batch query with a single id rather than through
	 * `WatermarkMarkMapper::isMarked()`, which is the same query: one call shape means the
	 * cache above and the fallback here cannot disagree about what was already asked.
	 */
	private function isMarked(int $fileId): bool {
		if (!array_key_exists($fileId, $this->cache)) {
			$this->cache[$fileId] = $this->markMapper->markedFileIds([$fileId]) !== [];
		}
		return $this->cache[$fileId];
	}
}
