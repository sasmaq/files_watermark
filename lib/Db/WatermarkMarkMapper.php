<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Which files carry a watermark mark.
 *
 * One row per file id, enforced by a unique index rather than by a read-then-write: two
 * PHP workers finishing an upload of the same path is the ordinary case, not the exotic
 * one, and a `SELECT` followed by an `INSERT` has a window between them that no amount of
 * care in this class closes. {@see mark()} leans on the index instead and treats the
 * collision as the success it is - the file ends up marked either way.
 *
 * @template-extends QBMapper<WatermarkMark>
 */
class WatermarkMarkMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'watermark_mark', WatermarkMark::class);
	}

	/**
	 * Mark $fileId, or leave the existing mark standing.
	 *
	 * @param ?string $originOwner the uid this protection descends from, for an inherited
	 *                             mark; null for one placed directly on a file
	 * @param ?int $originFileId the file the mark was inherited from; audit only
	 * @return bool true when this call created the mark, false when one was already there
	 */
	public function mark(
		int $fileId,
		string $markedBy,
		string $trigger,
		?int $configId,
		?string $originOwner = null,
		?int $originFileId = null,
	): bool {
		$mark = new WatermarkMark();
		$mark->setFileId($fileId);
		$mark->setMarkedBy($markedBy);
		$mark->setTrigger($trigger);
		$mark->setConfigId($configId);
		$mark->setOriginOwner($originOwner);
		$mark->setOriginFileId($originFileId);
		$mark->setCreatedAt(date('Y-m-d H:i:s'));

		try {
			$this->insert($mark);
		} catch (DbException $e) {
			// The unique index on file_id did its job: someone marked this file between our
			// caller's check and this insert. Not an error - the postcondition holds.
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}

		return true;
	}

	/**
	 * Remove $fileId's mark.
	 *
	 * @return bool true when a mark was removed, false when there was none
	 */
	public function unmark(int $fileId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	public function isMarked(int $fileId): bool {
		return $this->markedFileIds([$fileId]) !== [];
	}

	/**
	 * $fileId's mark, or null when there is none.
	 *
	 * The row rather than a boolean, because the one caller that needs it - the remove gate -
	 * has to read two of its columns to answer one question, and asking twice would be two
	 * queries for one decision.
	 */
	public function findByFileId(int $fileId): ?WatermarkMark {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * The marks on $fileIds, keyed by file id, as one batched `IN (...)` query.
	 *
	 * The row-returning counterpart of {@see markedFileIds}, and it exists for the same
	 * caller: `PropFindPlugin` needs two answers per listing - is this file marked, and may
	 * the person looking at it remove the mark - and the second one is in the row. Only
	 * marked files come back, so this costs the same round trip and the same rows as asking
	 * for the ids alone.
	 *
	 * @param int[] $fileIds
	 * @return array<int, WatermarkMark>
	 */
	public function findByFileIds(array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}

		$fileIds = array_values(array_unique(array_map('intval', $fileIds)));

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'file_id',
				$qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY),
			));

		$marks = [];
		foreach ($this->findEntities($qb) as $mark) {
			$marks[$mark->getFileId()] = $mark;
		}

		return $marks;
	}

	/**
	 * The subset of $fileIds that are marked, as one batched `IN (...)` query.
	 *
	 * Batched because the caller that matters is a folder listing: `PropFindPlugin` asks
	 * this once for every child of a directory, and a query per row is what that plugin
	 * exists to avoid.
	 *
	 * @param int[] $fileIds
	 * @return int[]
	 */
	public function markedFileIds(array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}

		$fileIds = array_values(array_unique(array_map('intval', $fileIds)));

		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'file_id',
				$qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY),
			));

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['file_id'];
		}
		$result->closeCursor();

		return $ids;
	}
}
