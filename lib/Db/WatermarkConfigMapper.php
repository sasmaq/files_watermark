<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\DoesNotExistException;
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

    /** @return WatermarkConfig[] */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntities($qb);
    }

    public function findGlobal(): WatermarkConfig {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->isNull('user_id'))
            ->andWhere($qb->expr()->isNull('group_id'))
            ->setMaxResults(1);
        return $this->findEntity($qb);
    }

    /**
     * Returns configs for a user that match the given MIME type, including
     * configs with no MIME whitelist (meaning they apply to all types).
     *
     * @return WatermarkConfig[]
     */
    public function findByUserAndMimeType(string $userId, string $mimeType): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->isNull('mime_types'),
                    $qb->expr()->eq('mime_types', $qb->createNamedParameter('')),
                    $qb->expr()->like('mime_types', $qb->createNamedParameter('%' . $mimeType . '%')),
                )
            );
        return $this->findEntities($qb);
    }

    public function findById(int $id): WatermarkConfig {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }
}
