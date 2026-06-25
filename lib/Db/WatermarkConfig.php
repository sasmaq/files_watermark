<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getTextTemplate()
 * @method void setTextTemplate(?string $textTemplate)
 * @method string|null getImagePath()
 * @method void setImagePath(?string $imagePath)
 * @method string getPosition()
 * @method void setPosition(string $position)
 * @method int getOpacity()
 * @method void setOpacity(int $opacity)
 * @method int getFontSize()
 * @method void setFontSize(int $fontSize)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method int getRotation()
 * @method void setRotation(int $rotation)
 * @method string getTrigger()
 * @method void setTrigger(string $trigger)
 * @method string|null getMimeTypes()
 * @method void setMimeTypes(?string $mimeTypes)
 * @method string|null getFolderTag()
 * @method void setFolderTag(?string $folderTag)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class WatermarkConfig extends Entity {

    protected ?string $userId = null;
    protected ?string $groupId = null;
    protected string $type = 'text';
    protected ?string $textTemplate = null;
    protected ?string $imagePath = null;
    protected string $position = 'diagonal';
    protected int $opacity = 80;
    protected int $fontSize = 24;
    protected string $color = '#cccccc';
    protected int $rotation = 45;
    protected string $trigger = 'on_demand';
    /** Comma-separated MIME types to watermark; null means all supported types */
    protected ?string $mimeTypes = null;
    /** Nextcloud system-tag ID for per-folder targeting; null means global */
    protected ?string $folderTag = null;
    protected string $createdAt = '';
    protected string $updatedAt = '';

    public function __construct() {
        $this->addType('opacity', 'integer');
        $this->addType('fontSize', 'integer');
        $this->addType('rotation', 'integer');
    }

    /** Returns the allowed MIME types as an array, or all supported types if not set. */
    public function getAllowedMimeTypes(): array {
        if ($this->mimeTypes === null || trim($this->mimeTypes) === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $this->mimeTypes)));
    }

    public function jsonSerialize(): array {
        return [
            'id'           => $this->id,
            'userId'       => $this->userId,
            'groupId'      => $this->groupId,
            'type'         => $this->type,
            'textTemplate' => $this->textTemplate,
            'imagePath'    => $this->imagePath,
            'position'     => $this->position,
            'opacity'      => $this->opacity,
            'fontSize'     => $this->fontSize,
            'color'        => $this->color,
            'rotation'     => $this->rotation,
            'trigger'      => $this->trigger,
            'mimeTypes'    => $this->mimeTypes,
            'folderTag'    => $this->folderTag,
            'createdAt'    => $this->createdAt,
            'updatedAt'    => $this->updatedAt,
        ];
    }
}
