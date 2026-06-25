<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFilePath()
 * @method void setFilePath(string $filePath)
 * @method string getTrigger()
 * @method void setTrigger(string $trigger)
 * @method int|null getConfigId()
 * @method void setConfigId(?int $configId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class WatermarkLog extends Entity {

    protected string $userId = '';
    protected int $fileId = 0;
    protected string $filePath = '';
    protected string $trigger = '';
    protected ?int $configId = null;
    protected string $createdAt = '';

    public function __construct() {
        $this->addType('fileId', 'integer');
        $this->addType('configId', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id'        => $this->id,
            'userId'    => $this->userId,
            'fileId'    => $this->fileId,
            'filePath'  => $this->filePath,
            'trigger'   => $this->trigger,
            'configId'  => $this->configId,
            'createdAt' => $this->createdAt,
        ];
    }
}
