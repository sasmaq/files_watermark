<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A file that is to be watermarked whenever it is fetched.
 *
 * The mark is a *policy attached to a file id*, not a description of the file's bytes -
 * nothing about the stored content changes when one is written. That is the whole
 * distinction against `watermark_log`, which the mark used to live inside: the log is a
 * history of things that happened, and this is a statement about what happens next.
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getMarkedBy()
 * @method void setMarkedBy(string $markedBy)
 * @method string getTrigger()
 * @method void setTrigger(string $trigger)
 * @method int|null getConfigId()
 * @method void setConfigId(?int $configId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class WatermarkMark extends Entity {

	protected int $fileId = 0;
	/** The user whose action put the mark here - not who the watermark will name. */
	protected string $markedBy = '';
	/** Which trigger placed it: `on_demand` or `on_upload`. Audit, not behaviour. */
	protected string $trigger = '';
	protected ?int $configId = null;
	protected string $createdAt = '';

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('configId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'fileId' => $this->fileId,
			'markedBy' => $this->markedBy,
			'trigger' => $this->trigger,
			'configId' => $this->configId,
			'createdAt' => $this->createdAt,
		];
	}
}
