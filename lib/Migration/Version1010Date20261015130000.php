<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Records, on every mark already placed, who owned the file when it was marked.
 *
 * ---------------------------------------------------------------------------
 * WHY A MARK NOW REMEMBERS ITS OWNER.
 *
 * Only the owner may remove a mark, and the owner used to be asked at the moment of removal.
 * A move keeps the file id, so a recipient with delete permission on a shared folder could
 * move a marked file into their own home, become its owner, remove the mark, and download
 * the clean original. `WatermarkService::mark()` now writes the owner into `origin_owner`
 * as it places the mark, and the remove gate asks that instead.
 *
 * Marks placed before that have no owner recorded, and fall back to the old rule. This
 * fills in what it can, from each file's owner **today** - which is the right answer for any
 * file that has not already been moved out of a share, and the only answer available for one
 * that has.
 * ---------------------------------------------------------------------------
 *
 * Data only - no schema change, so `SchemaConvergenceTest` has nothing to converge. A file
 * that cannot be resolved (deleted for good, on a mount that is offline) is left as it is,
 * and keeps the old rule; the cleanup job removes the marks of files that are gone.
 */
class Version1010Date20261015130000 extends SimpleMigrationStep {

	/** Rows updated per transaction-free batch; the read is chunked the same way. */
	private const BATCH = 500;

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private IUserMountCache $mountCache,
		private LoggerInterface $logger,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$recorded = 0;
		$unresolved = 0;
		$afterId = 0;

		while (true) {
			$rows = $this->unrecorded($afterId);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$afterId = $row['id'];
				$owner = $this->ownerOf($row['file_id']);
				if ($owner === null) {
					$unresolved++;
					continue;
				}

				$update = $this->db->getQueryBuilder();
				$update->update('watermark_mark')
					->set('origin_owner', $update->createNamedParameter($owner))
					->where($update->expr()->eq('id', $update->createNamedParameter($row['id'], IQueryBuilder::PARAM_INT)))
					->andWhere($update->expr()->isNull('origin_owner'));
				$recorded += $update->executeStatement();
			}
		}

		if ($recorded > 0 || $unresolved > 0) {
			$output->info(sprintf(
				'files_watermark: recorded the owner on %d existing watermark mark(s); %d could not be resolved and keep the previous rule.',
				$recorded,
				$unresolved,
			));
		}
	}

	/**
	 * The next batch of direct marks with no owner recorded, in id order after $afterId.
	 *
	 * @return list<array{id: int, file_id: int}>
	 */
	private function unrecorded(int $afterId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id')
			->from('watermark_mark')
			->where($qb->expr()->isNull('origin_owner'))
			->andWhere($qb->expr()->neq('trigger', $qb->createNamedParameter(WatermarkService::TRIGGER_INHERITED)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults(self::BATCH);

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['id' => (int)$row['id'], 'file_id' => (int)$row['file_id']];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * The owner of $fileId, found through a user who has it mounted.
	 *
	 * **Not `IRootFolder::getFirstNodeById()`**, which answers null under `occ upgrade` -
	 * nobody's filesystem is set up there, so the root cannot see into any home. The mount
	 * cache knows which users have the file, and their own folder resolves it; for a shared
	 * file that may be a recipient's mount, and `getOwner()` still answers the owner.
	 */
	private function ownerOf(int $fileId): ?string {
		try {
			foreach ($this->mountCache->getMountsForFileId($fileId) as $mount) {
				$node = $this->rootFolder->getUserFolder($mount->getUser()->getUID())->getFirstNodeById($fileId);
				if ($node !== null) {
					return $node->getOwner()?->getUID();
				}
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->info('files_watermark: could not resolve the owner of marked file {id}', [
				'id' => $fileId,
				'exception' => $e,
			]);

			return null;
		}
	}
}
