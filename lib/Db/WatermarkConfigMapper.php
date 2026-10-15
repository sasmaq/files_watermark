<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<WatermarkConfig> */
class WatermarkConfigMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'watermark_config', WatermarkConfig::class);
	}

	/** @return WatermarkConfig[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		return $this->findEntities($qb);
	}

	/**
	 * The server-wide policy - the only one there is.
	 *
	 * Throws `DoesNotExistException` on an install where no admin has saved one yet;
	 * {@see WatermarkService::resolveConfig} answers that with its built-in default
	 * rather than treating it as an error.
	 *
	 * **Ordered, so "the first row" means the same row every time.** Saving no longer adds a
	 * second one, but an install that already has two - from before that - must not have
	 * the database's whim choose between them: PostgreSQL moves an updated row to the end of
	 * the heap, so an unordered `LIMIT 1` could start answering with the *other* row the
	 * moment the admin edited the policy. The oldest row is the one the settings page has
	 * always shown and edited.
	 */
	public function findGlobal(): WatermarkConfig {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		return $this->findEntity($qb);
	}

	public function findById(int $id): WatermarkConfig {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}
}
