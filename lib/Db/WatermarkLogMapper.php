<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The audit trail: what happened, to which file, at whose hand.
 *
 * **It is only history now.** It used to be history *and* the app's record of which files
 * carried a watermark, which is why answering "is this file watermarked?" meant replaying a
 * file's rows in order and why `deleteBefore()` had to be forbidden from touching most of
 * them - deleting a line of history would have un-badged a file and let it be stamped
 * twice. That record lives in `watermark_mark` now, so nothing here is load-bearing and a
 * row can be pruned on age alone.
 *
 * @template-extends QBMapper<WatermarkLog>
 */
class WatermarkLogMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'watermark_log', WatermarkLog::class);
	}

	/**
	 * @return WatermarkLog[]
	 */
	public function findAll(int $limit = 100, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/**
	 * Rows older than `$cutoff`, counted rather than deleted. Backs `--dry-run`.
	 *
	 * @param string|null $cutoff `Y-m-d H:i:s`, or null for "every row"
	 */
	public function countBefore(?string $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'rows'))->from($this->getTableName());
		$this->restrict($qb, $cutoff);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Delete rows older than `$cutoff`, and report how many went.
	 *
	 * Every row is reachable, which it deliberately was not before: the trigger carve-out
	 * that used to sit here existed because some rows were the app's memory rather than its
	 * history, and none are any more. Retention is now what it says it is.
	 *
	 * @param string|null $cutoff `Y-m-d H:i:s`, or null for "every row"
	 * @return int rows deleted
	 */
	public function deleteBefore(?string $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());
		$this->restrict($qb, $cutoff);

		return $qb->executeStatement();
	}

	/** The shared `WHERE` of the two above, so they cannot select different rows. */
	private function restrict(IQueryBuilder $qb, ?string $cutoff): void {
		if ($cutoff === null) {
			return;
		}

		$qb->andWhere($qb->expr()->lt(
			'created_at',
			$qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_STR),
		));
	}

	public function insertLog(string $userId, int $fileId, string $filePath, string $trigger, ?int $configId): WatermarkLog {
		$log = new WatermarkLog();
		$log->setUserId($userId);
		$log->setFileId($fileId);
		$log->setFilePath($filePath);
		$log->setTrigger($trigger);
		$log->setConfigId($configId);
		$log->setCreatedAt(date('Y-m-d H:i:s'));
		return $this->insert($log);
	}
}
