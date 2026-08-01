<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\AppInfo\Application;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The ceilings on {@see \OCA\FilesWatermark\Dav\ZipInterceptorPlugin}'s pre-render pass.
 *
 * `on_share` must never leak a clean original, so every member of an archive is rendered
 * to a temp file *before* any bytes go out — that is what lets a failed render abort with
 * a real 403 instead of a truncated archive. The cost is temp disk and CPU proportional to
 * the folder, and these two numbers are what bounds it.
 *
 * **Why app config and not the policy table.** The watermark policy is one row an admin
 * approves in a form; these are host tuning, sized by the server's temp filesystem and how
 * long a request may take, and they mean nothing to the watermark that comes out. Putting
 * them in the policy would also have meant either two more fields on a form about
 * appearance, or two more columns with no way to set them — and a stored setting with no
 * way in is exactly what the group and per-user overrides turned out to be.
 *
 * ```
 * occ config:app:set files_watermark archive_max_members --value 500
 * occ config:app:set files_watermark archive_max_bytes   --value 1073741824
 * ```
 *
 * **There is deliberately no "unlimited".** A value below 1 is refused and the default
 * used instead: the cap is not a preference, it is the bound that keeps a fail-closed
 * render from exhausting the temp filesystem, and `0` reads far too much like "off" for
 * something that would quietly make a folder download able to fill a disk. An admin who
 * wants a much larger archive sets a much larger number, which at least states the cost.
 */
class ArchiveLimits {

	/** Members rendered per archive. */
	public const KEY_MAX_MEMBERS = 'archive_max_members';
	/** Source bytes rendered per archive. */
	public const KEY_MAX_BYTES = 'archive_max_bytes';

	public const DEFAULT_MAX_MEMBERS = 200;
	public const DEFAULT_MAX_BYTES = 268435456; // 256 MiB of source content

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function maxMembers(): int {
		return $this->limit(self::KEY_MAX_MEMBERS, self::DEFAULT_MAX_MEMBERS);
	}

	public function maxBytes(): int {
		return $this->limit(self::KEY_MAX_BYTES, self::DEFAULT_MAX_BYTES);
	}

	/**
	 * A configured ceiling, or the default when it is unusable.
	 *
	 * Every failure here degrades to the default rather than throwing. This is read on
	 * the delivery path, once per archive request: a typo in an `occ` command must not
	 * become an HTTP 500 on every folder download — the app has shipped that exact shape
	 * of bug once already, from a mistyped system tag.
	 */
	private function limit(string $key, int $default): int {
		try {
			$value = $this->appConfig->getValueInt(Application::APP_ID, $key, $default);
		} catch (AppConfigTypeConflictException) {
			// Stored with an explicit non-numeric type (`occ config:app:set --type=string`).
			$this->logger->warning('files_watermark: {key} is not stored as a number, using the default', [
				'key' => $key,
				'default' => $default,
			]);
			return $default;
		}

		if ($value < 1) {
			$this->logger->warning('files_watermark: {key} must be at least 1, using the default', [
				'key' => $key,
				'configured' => $value,
				'default' => $default,
			]);
			return $default;
		}

		return $value;
	}
}
