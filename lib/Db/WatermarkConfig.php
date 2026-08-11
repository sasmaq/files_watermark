<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The server-wide watermark policy. There is one, set by an administrator.
 *
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getTextTemplate()
 * @method void setTextTemplate(?string $textTemplate)
 * @method string|null getImagePath()
 * @method void setImagePath(?string $imagePath)
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
 * @method bool getLogDelivery()
 * @method void setLogDelivery(bool $logDelivery)
 * @method bool getWatermarkInternalShares()
 * @method void setWatermarkInternalShares(bool $watermarkInternalShares)
 * @method bool getWatermarkExternalShares()
 * @method void setWatermarkExternalShares(bool $watermarkExternalShares)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class WatermarkConfig extends Entity {

	protected string $type = 'text';
	protected ?string $textTemplate = null;
	protected ?string $imagePath = null;
	protected int $opacity = 40;
	protected int $fontSize = 24;
	/**
	 * Light grey. It tints rather than obscures, which is the point and also the cost: it is
	 * at its faintest over a white page, where opacity and font size are the dials that
	 * answer. Every install can override it.
	 */
	protected string $color = '#d3d3d3';
	protected int $rotation = 45;
	protected string $trigger = 'on_demand';
	/** Comma-separated MIME types to watermark; null means all supported types */
	protected ?string $mimeTypes = null;
	/** Nextcloud system-tag ID for per-folder targeting; null means global */
	protected ?string $folderTag = null;
	/**
	 * Whether to write an audit row for every *delivery* - one watermarked copy handed to
	 * one reader.
	 *
	 * **On by default**, which it was not under the four-trigger model. There, two of the
	 * four triggers never produced a delivery row at all, so an install that had one was a
	 * minority case and the rows were an opt-in extra. Now every marked file renders on
	 * every fetch, and *"who received a copy of this document"* is the question the app
	 * exists to answer - a default install that cannot answer it is recording the policy
	 * and not the thing the policy is about.
	 *
	 * What that costs is one row per file per download, archive members included; the
	 * bound is `archive_max_members`, and `occ files_watermark:prune-log` is the retention.
	 * Previews render per viewer but write no rows - the volume would be per thumbnail.
	 *
	 * The mark/unmark events are recorded regardless of this, and always have been.
	 */
	protected bool $logDelivery = true;
	/**
	 * Watermark **every fetch made through a share**, whether or not the file is marked.
	 *
	 * The two switches below are not triggers and they mark nothing: they are read at
	 * delivery, against the fetch that is happening, so ticking one watermarks shared files
	 * from that moment and unticking it stops - there is no residue either way. A file that
	 * carries a mark is watermarked regardless of both, for every reader including its owner;
	 * these only widen the set of *fetches* that get a watermark, never the set of marks.
	 *
	 * `internal` is a fetch through a received share - the file is mounted from somebody
	 * else's storage. `external` is a public link, where the visitor has no account to name
	 * and the watermark falls back to the owner ({@see WatermarkService::readerIdentity()}).
	 */
	protected bool $watermarkInternalShares = false;
	protected bool $watermarkExternalShares = false;
	protected string $createdAt = '';
	protected string $updatedAt = '';

	public function __construct() {
		$this->addType('opacity', 'integer');
		$this->addType('fontSize', 'integer');
		$this->addType('rotation', 'integer');
		$this->addType('logDelivery', 'boolean');
		$this->addType('watermarkInternalShares', 'boolean');
		$this->addType('watermarkExternalShares', 'boolean');
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
			'id' => $this->id,
			'type' => $this->type,
			'textTemplate' => $this->textTemplate,
			'imagePath' => $this->imagePath,
			'opacity' => $this->opacity,
			'fontSize' => $this->fontSize,
			'color' => $this->color,
			'rotation' => $this->rotation,
			'trigger' => $this->trigger,
			'mimeTypes' => $this->mimeTypes,
			'folderTag' => $this->folderTag,
			'logDelivery' => $this->logDelivery,
			'watermarkInternalShares' => $this->watermarkInternalShares,
			'watermarkExternalShares' => $this->watermarkExternalShares,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
