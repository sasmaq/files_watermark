<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\Node;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCP\Files\Folder;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Exposes a per-file WebDAV property telling the Files client whether a file has
 * ever been watermarked. Delivering the status as a node property means the Files
 * app has it the moment a row renders, so the "Apply watermark" `FileAction` can
 * decide `enabled()` synchronously on first evaluation — no async lookup, no relying
 * on Nextcloud re-computing memoized actions after the fact.
 */
class PropFindPlugin extends ServerPlugin {

    public const WATERMARKED_PROPERTY = '{http://nextcloud.org/ns}is-watermarked';

    /**
     * file id => watermarked. Primed with one batched query per folder listing so a
     * directory PROPFIND does not fan out into a query per child.
     *
     * @var array<int, bool>
     */
    private array $cache = [];

    public function __construct(
        private WatermarkLogMapper $logMapper,
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
            return $this->isWatermarked($node->getId()) ? '1' : '0';
        });
    }

    private function cacheFolder(Folder $folder): void {
        $childIds = array_map(
            static fn($child) => $child->getId(),
            $folder->getDirectoryListing(),
        );
        if ($childIds === []) {
            return;
        }

        foreach ($this->logMapper->findWatermarkedFileIds($childIds) as $id) {
            $this->cache[$id] = true;
        }
        // Everything else in the folder is known *not* watermarked — record it so the
        // per-node handler never falls back to a second query.
        foreach ($childIds as $id) {
            $this->cache[$id] ??= false;
        }
    }

    private function isWatermarked(int $fileId): bool {
        if (!array_key_exists($fileId, $this->cache)) {
            $this->cache[$fileId] = $this->logMapper->findWatermarkedFileIds([$fileId]) !== [];
        }
        return $this->cache[$fileId];
    }
}
