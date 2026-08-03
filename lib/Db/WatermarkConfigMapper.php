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
	 * Whether *any* config uses a delivery trigger (`on_download` / `on_share`).
	 *
	 * One indexed lookup that answers "could this request need watermarking at all",
	 * without knowing whose policy applies. The archive interceptor uses it to stay off
	 * core's path entirely in the common on_demand / on_upload case, where no member of
	 * any archive can ever need a watermark.
	 */
	public function hasDeliveryTrigger(): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->in('trigger', $qb->createNamedParameter(
				['on_download', 'on_share'],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false;
	}

	/**
	 * The server-wide policy - the only one there is.
	 *
	 * Throws `DoesNotExistException` on an install where no admin has saved one yet;
	 * {@see WatermarkService::resolveConfig} answers that with its built-in default
	 * rather than treating it as an error.
	 */
	public function findGlobal(): WatermarkConfig {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
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
