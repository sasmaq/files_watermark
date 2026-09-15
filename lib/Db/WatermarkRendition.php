<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The length one reader's download of one marked file is promised to have.
 *
 * Not a cached file and not a description of one: no bytes are stored anywhere, and the
 * download is rendered afresh every time. This is only the *number* that render is then
 * padded up to, measured once so PROPFIND has something truthful to advertise before any
 * download has happened. See {@see \OCA\FilesWatermark\Migration\Version1008Date20260915120000}
 * for why that number has to exist at all.
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getSignature()
 * @method void setSignature(string $signature)
 * @method int getReservedSize()
 * @method void setReservedSize(int $reservedSize)
 * @method string getEtag()
 * @method void setEtag(string $etag)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class WatermarkRendition extends Entity {

	protected int $fileId = 0;
	/** The reader this was measured for - their name is drawn into the render. */
	protected string $uid = '';
	/**
	 * A digest of everything that decides the render's length, bar the clock: the stored
	 * file's etag and the resolved config. When it stops matching, the number below is about
	 * a file or a policy that no longer exists and has to be measured again.
	 */
	protected string $signature = '';
	/** A measured render's length **plus slack**, which is what padding fills. */
	protected int $reservedSize = 0;
	/** What PROPFIND reports and the download echoes. */
	protected string $etag = '';
	protected string $createdAt = '';
	protected string $updatedAt = '';

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('reservedSize', 'integer');
	}

	/**
	 * Whether this reservation still describes $signature's file and policy.
	 *
	 * A stale row is never trusted and never silently deleted on a read path - it is
	 * re-measured and overwritten. That is what keeps a download correct on an instance
	 * where an invalidation hook failed to fire, or never existed for that kind of change.
	 */
	public function matches(string $signature): bool {
		return $this->signature === $signature;
	}
}
