<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The promised download lengths, one row per (file, reader).
 *
 * Every method here is on a path that serves a download or answers a listing, so none of
 * them may throw for a reason a caller cannot do anything about: a reservation that cannot
 * be read is treated as one that is not there yet, which costs a re-measure and never costs
 * a wrong answer.
 *
 * @template-extends QBMapper<WatermarkRendition>
 */
class WatermarkRenditionMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'watermark_rendition', WatermarkRendition::class);
	}

	/**
	 * $uid's reservation for $fileId, or null when none has been measured.
	 *
	 * Returns the row whatever its signature says - deciding whether it is still current is
	 * {@see WatermarkRendition::matches}' job, and the caller needs the row itself to
	 * overwrite it.
	 */
	public function find(int $fileId, string $uid): ?WatermarkRendition {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		} catch (DbException) {
			// Reads on this table are advisory - see the class docblock.
			return null;
		}
	}

	/**
	 * Write $uid's reservation for $fileId, replacing any earlier one.
	 *
	 * The insert races: two workers measuring the same file for the same user at the same
	 * moment is the ordinary case, not the exotic one. The unique index settles it, and the
	 * loser updates instead - both measured the same file under the same signature, so they
	 * agree about everything except which row id carries the answer.
	 *
	 * @return bool whether the reservation is now recorded
	 */
	public function reserve(
		int $fileId,
		string $uid,
		string $signature,
		int $reservedSize,
		string $etag,
	): bool {
		$now = date('Y-m-d H:i:s');

		$existing = $this->find($fileId, $uid);
		if ($existing !== null) {
			$existing->setSignature($signature);
			$existing->setReservedSize($reservedSize);
			$existing->setEtag($etag);
			$existing->setUpdatedAt($now);

			try {
				$this->update($existing);
				return true;
			} catch (DbException) {
				return false;
			}
		}

		$rendition = new WatermarkRendition();
		$rendition->setFileId($fileId);
		$rendition->setUid($uid);
		$rendition->setSignature($signature);
		$rendition->setReservedSize($reservedSize);
		$rendition->setEtag($etag);
		$rendition->setCreatedAt($now);
		$rendition->setUpdatedAt($now);

		try {
			$this->insert($rendition);
			return true;
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// Someone measured it between the read above and this insert. They wrote the
				// same signature and a size measured the same way, so the postcondition holds.
				return true;
			}
			return false;
		}
	}

	/**
	 * Drop every reader's reservation for $fileId.
	 *
	 * For the changes a signature cannot notice on its own - the mark coming off, the file
	 * being deleted - and as housekeeping for the ones it can. Leaving a stale row behind is
	 * never a correctness problem ({@see WatermarkRendition::matches} catches it), so this
	 * failing is not worth propagating.
	 */
	public function deleteByFileId(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		try {
			$qb->executeStatement();
		} catch (DbException) {
			// Housekeeping - see above.
		}
	}

	/**
	 * Drop every reservation measured for $uid, for when the user is gone.
	 */
	public function deleteByUid(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		try {
			$qb->executeStatement();
		} catch (DbException) {
			// Housekeeping - see above.
		}
	}

	/**
	 * $uid's reservations for a whole listing's worth of file ids, keyed by file id.
	 *
	 * One query for a directory PROPFIND, for the same reason `WatermarkMarkMapper` batches:
	 * a listing must not fan out into a query per row.
	 *
	 * @param int[] $fileIds
	 * @return array<int, WatermarkRendition>
	 */
	public function findByFileIds(array $fileIds, string $uid): array {
		if ($fileIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		try {
			$found = $this->findEntities($qb);
		} catch (DbException) {
			return [];
		}

		$byFileId = [];
		foreach ($found as $rendition) {
			$byFileId[$rendition->getFileId()] = $rendition;
		}

		return $byFileId;
	}
}
