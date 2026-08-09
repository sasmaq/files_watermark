<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * The timezone `{date}` and `{datetime}` are rendered in.
 *
 * ---------------------------------------------------------------------------
 * WHY `default_timezone` FROM config.php, AND NOT THE TWO OBVIOUS ALTERNATIVES.
 *
 * **Not PHP's process default**, which is what `date()` uses and what this app used to
 * render. Nextcloud sets that to UTC while it boots, so the watermark read UTC on every
 * instance in the world regardless of where the server was or what php.ini said - a document
 * stamped at 09:00 local came out saying 06:00, which reads as a wrong timestamp rather than
 * as a different timezone, because the watermark has no room to write the offset.
 *
 * **Not the reader's own timezone**, which `IDateTimeZone::getTimeZone()` would give and
 * which looks like the consistent choice for an app whose watermark otherwise names whoever
 * is fetching the file. It is the wrong kind of consistency here. The name in a watermark
 * answers *who received this copy*, and it should differ per reader. The timestamp answers
 * *when was this handed out*, which is one instant, and rendering it per reader means two
 * people holding what claims to be the same event at two different times - with nothing on
 * the page to reconcile them. It also breaks the pairing with the audit row, which is the
 * thing an investigation actually joins on.
 *
 * So: one timezone, the instance's, the one an admin has already set for everything else
 * Nextcloud dates - `'default_timezone' => 'Asia/Aden'` in `config.php`.
 * ---------------------------------------------------------------------------
 *
 * Read on the delivery path, so a bad value degrades rather than throws - the discipline
 * {@see ConfiguredLimits} sets out. `new \DateTimeZone()` throws on an identifier PHP does
 * not know, and a typo in `config.php` must not turn every watermarked download on the
 * instance into an HTTP 500.
 */
class InstanceTimeZone {

	/** Nextcloud's own system-config key. Not `logtimezone`, which is for the log file. */
	public const KEY = 'default_timezone';

	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The instance's timezone, falling back to PHP's default when none is configured.
	 *
	 * The fallback is the behaviour this replaced, which makes "no `default_timezone` set"
	 * a no-op rather than a change - an instance that never configured one keeps rendering
	 * exactly what it rendered before.
	 */
	public function get(): \DateTimeZone {
		$configured = trim($this->config->getSystemValueString(self::KEY, ''));

		if ($configured !== '') {
			try {
				return new \DateTimeZone($configured);
			} catch (\Exception) {
				$this->logger->warning(
					'files_watermark: {key} in config.php is "{configured}", which PHP does not recognise as a '
						. 'timezone; watermark dates fall back to {fallback}',
					[
						'key' => self::KEY,
						'configured' => $configured,
						'fallback' => $this->phpDefault(),
					],
				);
			}
		}

		try {
			return new \DateTimeZone($this->phpDefault());
		} catch (\Exception) {
			// date_default_timezone_get() is documented to return a usable identifier, so
			// this is unreachable in practice. UTC rather than a throw, because a watermark
			// with a slightly wrong hour beats a download that answers 500.
			return new \DateTimeZone('UTC');
		}
	}

	/** @return non-empty-string so the `\DateTimeZone` constructor above is satisfiable */
	private function phpDefault(): string {
		return date_default_timezone_get() ?: 'UTC';
	}
}
