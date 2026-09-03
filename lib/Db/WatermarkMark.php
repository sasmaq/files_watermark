<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCA\FilesWatermark\Service\WatermarkService;
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
 * @method string|null getOriginOwner()
 * @method void setOriginOwner(?string $originOwner)
 * @method int|null getOriginFileId()
 * @method void setOriginFileId(?int $originFileId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class WatermarkMark extends Entity {

	protected int $fileId = 0;
	/** The user whose action put the mark here - not who the watermark will name. */
	protected string $markedBy = '';
	/** Which trigger placed it: `on_demand`, `on_upload` or `inherited`. Audit, not behaviour. */
	protected string $trigger = '';
	protected ?int $configId = null;
	/**
	 * The uid whose file this protection descends from, or null when the mark was placed
	 * directly on a file rather than inherited from one.
	 *
	 * Set only by {@see \OCA\FilesWatermark\Service\WatermarkService::inheritMark}, and read
	 * only by the remove gate: once it is set, *it* decides who may unmark, in place of
	 * ownership. That is the whole point - copying a marked file makes the copier the owner,
	 * so ownership alone stopped being an answer the moment marks began to travel.
	 */
	protected ?string $originOwner = null;
	/** Which file this mark was inherited from. Audit, not behaviour. */
	protected ?int $originFileId = null;
	protected string $createdAt = '';

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('configId', 'integer');
		$this->addType('originFileId', 'integer');
	}

	/**
	 * Whether this mark descends from a file that is not $uid's to release.
	 *
	 * The single definition of the rule, because two callers need it and they must not be
	 * able to disagree: {@see \OCA\FilesWatermark\Service\WatermarkService::unmarkVerdict},
	 * which is what the API enforces, and
	 * {@see \OCA\FilesWatermark\Dav\PropFindPlugin}, which is what decides whether the
	 * Files app offers the button at all. A button offered where the server refuses is a
	 * user discovering the rule by being told no.
	 *
	 * A mark placed directly on a file is foreign to nobody - ownership governs it, as it
	 * always has. An inherited one that names nobody is foreign to everybody, which is the
	 * fail-closed direction and the same call the ownership check makes for a node whose
	 * owner will not resolve. An empty uid never matches an origin.
	 */
	public function isForeignTo(?string $uid): bool {
		if ($this->trigger !== WatermarkService::TRIGGER_INHERITED) {
			return false;
		}

		return $this->originOwner === null
			|| $uid === null
			|| $uid === ''
			|| $this->originOwner !== $uid;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'fileId' => $this->fileId,
			'markedBy' => $this->markedBy,
			'trigger' => $this->trigger,
			'configId' => $this->configId,
			'originOwner' => $this->originOwner,
			'originFileId' => $this->originFileId,
			'createdAt' => $this->createdAt,
		];
	}
}
