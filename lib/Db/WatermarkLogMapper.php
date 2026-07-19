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
     * Return the subset of the given file ids whose *stored* content has been
     * watermarked. Runs as a single batched `IN (...)` query and returns distinct ids.
     *
     * `on_download` log rows are excluded: that trigger streams a watermarked temp
     * copy and leaves the original on storage untouched, so it must not flag the file
     * in the Files-list indicator (nor count towards the in-place double-burn guard).
     * Only the triggers that burn the mark into the file itself qualify.
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
        $qb->selectDistinct('file_id')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'file_id',
                $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY),
            ))
            ->andWhere($qb->expr()->neq(
                'trigger',
                $qb->createNamedParameter('on_download'),
            ));

        $result = $qb->executeQuery();
        $ids    = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['file_id'];
        }
        $result->closeCursor();

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
