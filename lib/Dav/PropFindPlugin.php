<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\Node;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
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

	public function __construct(
		private WatermarkMarkMapper $markMapper,
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
			return $this->isMarked($node->getId()) ? '1' : '0';
		});
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
