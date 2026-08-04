<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\AppInfo\Application;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reading an `occ`-set ceiling, with the rule that a bad one never reaches the caller.
 *
 * Shared by {@see ArchiveLimits} and {@see ApplyLimits}. The two hold different numbers
 * for different paths, but the policy for *reading* one is the same and is the part worth
 * having in one place: every failure degrades to the shipped default rather than
 * propagating. These are read on request paths - a folder download, a file action - and a
 * typo in an admin's `occ` command must not become an HTTP 500 on every one of them. The
 * app has shipped that exact shape of bug once already, from a mistyped system tag.
 *
 * **There is deliberately no "unlimited".** A value below 1 is refused and the default
 * used instead. These caps are not preferences, they are the bounds that keep a render
 * from exhausting a temp filesystem or a PHP worker's memory, and `0` reads far too much
 * like "off" for something that would quietly remove them. An admin who wants a much
 * larger ceiling sets a much larger number, which at least states the cost.
 */
abstract class ConfiguredLimits {

	public function __construct(
		protected IAppConfig $appConfig,
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * A configured ceiling, or $default when it is missing or unusable.
	 */
	protected function limit(string $key, int $default): int {
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
