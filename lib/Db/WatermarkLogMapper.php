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
	 *
	 * Public because the same list decides two other things, and they are the same
	 * question asked twice: these rows are **pure audit**, so they are the ones the
	 * `log_delivery` policy can switch off ({@see \OCA\FilesWatermark\Service\WatermarkService})
	 * and the only ones {@see deleteBefore} can reach. The in-place rows are not audit at
	 * all — they are how the app knows a file's stored content is watermarked, so dropping
	 * one un-badges a file and lets it be stamped a second time.
	 */
	public const NON_DESTRUCTIVE_TRIGGERS = ['on_download', 'on_share'];

	/**
	 * Trigger recorded when a watermark is undone and the original restored. It is an
	 * in-place event like the ones above — it changes the stored content — so it takes
	 * part in the query below, where it *cancels* an earlier apply.
	 */
	private const REMOVAL_TRIGGER = 'removed';

	/**
	 * Trigger recorded when a *user's own write* replaces content this app had
	 * watermarked — an upload over an existing file, most often from a sync client.
	 *
	 * It cancels an earlier apply for the same reason `removed` does: the watermarked
	 * bytes are gone. The distinction is who did it and whether anything was restored,
	 * which is exactly what an audit trail should keep separate.
	 */
	private const REPLACEMENT_TRIGGER = 'replaced';

	/** The in-place events that leave a file's stored content *not* watermarked. */
	private const CANCELLING_TRIGGERS = [self::REMOVAL_TRIGGER, self::REPLACEMENT_TRIGGER];

	/**
	 * Return the subset of the given file ids whose *stored* content is watermarked
	 * right now. Runs as a single batched `IN (...)` query.
	 *
	 * A file's status is decided by its **most recent** in-place event, not by the mere
	 * existence of one: apply → removed → apply must end up watermarked, and
	 * apply → removed must not. `replaced` cancels an apply the same way — a user's own
	 * write over a watermarked file leaves bytes that carry no watermark, whatever the
	 * log says happened before. Rows are read in insertion order (`id`, which is
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
			if (!in_array($trigger, self::CANCELLING_TRIGGERS, true)) {
				$ids[] = $fileId;
			}
		}

		return $ids;
	}

	/**
	 * Delivery rows older than `$cutoff`, counted rather than deleted. Backs `--dry-run`.
	 *
	 * @param string|null $cutoff `Y-m-d H:i:s`, or null for "every delivery row"
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
	 * Delete delivery rows older than `$cutoff`, and report how many went.
	 *
	 * **The in-place rows are unreachable from here, by construction rather than by a
	 * default.** They are the app's record that a file's stored content is watermarked —
	 * the Files-list badge and the guard against a second burn both read them — so
	 * deleting one does not shorten a history, it makes the app forget a file it has
	 * already stamped. There is deliberately no parameter to override this: an option
	 * that clears badges is not retention, and a caller cannot ask for it by mistake.
	 *
	 * @param string|null $cutoff `Y-m-d H:i:s`, or null for "every delivery row"
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
		if ($cutoff !== null) {
			$qb->andWhere($qb->expr()->lt(
				'created_at',
				$qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_STR),
			));
		}

		$qb->andWhere($qb->expr()->in(
			'trigger',
			$qb->createNamedParameter(self::NON_DESTRUCTIVE_TRIGGERS, IQueryBuilder::PARAM_STR_ARRAY),
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
