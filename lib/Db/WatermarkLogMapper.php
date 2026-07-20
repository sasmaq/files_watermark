<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<WatermarkLog> */
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
	 * Triggers that stream a watermarked copy on delivery and leave the stored file
	 * untouched. Their log rows must not flag the file in the Files-list indicator
	 * (nor count towards the in-place double-burn guard) — only the in-place triggers
	 * (`on_demand`, `on_upload`) that burn the mark into the file itself qualify.
	 */
	private const NON_DESTRUCTIVE_TRIGGERS = ['on_download', 'on_share'];

	/**
	 * Trigger recorded when a watermark is undone and the original restored. It is an
	 * in-place event like the ones above — it changes the stored content — so it takes
	 * part in the query below, where it *cancels* an earlier apply.
	 */
	private const REMOVAL_TRIGGER = 'removed';

	/**
	 * Return the subset of the given file ids whose *stored* content is watermarked
	 * right now. Runs as a single batched `IN (...)` query.
	 *
	 * A file's status is decided by its **most recent** in-place event, not by the mere
	 * existence of one: apply → removed → apply must end up watermarked, and
	 * apply → removed must not. Rows are read in insertion order (`id`, which is
	 * monotonic — `created_at` has only second resolution and ties on fast round-trips)
	 * and the last one per file wins.
	 *
	 * @param int[] $fileIds
	 * @return int[]
	 */
	public function findWatermarkedFileIds(array $fileIds): array {
		if (empty($fileIds)) {
			return [];
		}

		$fileIds = array_values(array_unique(array_map('intval', $fileIds)));

		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'trigger')
			->from($this->getTableName())
			->where($qb->expr()->in(
				'file_id',
				$qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->andWhere($qb->expr()->notIn(
				'trigger',
				$qb->createNamedParameter(self::NON_DESTRUCTIVE_TRIGGERS, IQueryBuilder::PARAM_STR_ARRAY),
			))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		/** @var array<int, string> $latest file id → most recent in-place trigger */
		$latest = [];
		while ($row = $result->fetch()) {
			$latest[(int)$row['file_id']] = (string)$row['trigger'];
		}
		$result->closeCursor();

		$ids = [];
		foreach ($latest as $fileId => $trigger) {
			if ($trigger !== self::REMOVAL_TRIGGER) {
				$ids[] = $fileId;
			}
		}

		return $ids;
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
