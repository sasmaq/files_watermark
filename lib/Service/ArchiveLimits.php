<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * The ceilings on {@see \OCA\FilesWatermark\Dav\ZipInterceptorPlugin}'s pre-render pass.
 *
 * `on_share` must never leak a clean original, so every member of an archive is rendered
 * to a temp file *before* any bytes go out - that is what lets a failed render abort with
 * a real 403 instead of a truncated archive. The cost is temp disk and CPU proportional to
 * the folder, and these two numbers are what bounds it.
 *
 * **Why app config and not the policy table.** The watermark policy is one row an admin
 * approves in a form; these are host tuning, sized by the server's temp filesystem and how
 * long a request may take, and they mean nothing to the watermark that comes out. Putting
 * them in the policy would also have meant either two more fields on a form about
 * appearance, or two more columns with no way to set them - and a stored setting with no
 * way in is exactly what the group and per-user overrides turned out to be.
 *
 * ```
 * occ config:app:set files_watermark archive_max_members --value 500
 * occ config:app:set files_watermark archive_max_bytes   --value 1073741824
 * ```
 *
 * The rule that a bad value never reaches the caller, and the reasoning behind refusing a
 * `0` that would read as "unlimited", live in {@see ConfiguredLimits} - they are shared
 * with {@see ApplyLimits} rather than restated here.
 */
class ArchiveLimits extends ConfiguredLimits {

	/** Members rendered per archive. */
	public const KEY_MAX_MEMBERS = 'archive_max_members';
	/** Source bytes rendered per archive. */
	public const KEY_MAX_BYTES = 'archive_max_bytes';

	public const DEFAULT_MAX_MEMBERS = 200;
	public const DEFAULT_MAX_BYTES = 268435456; // 256 MiB of source content

	public function maxMembers(): int {
		return $this->limit(self::KEY_MAX_MEMBERS, self::DEFAULT_MAX_MEMBERS);
	}

	public function maxBytes(): int {
		return $this->limit(self::KEY_MAX_BYTES, self::DEFAULT_MAX_BYTES);
	}
}
