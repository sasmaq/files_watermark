<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\AppInfo\Application;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * How the image renderers should treat Arabic that arrives already shaped.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A SETTING AT ALL, WHEN {@see ShapedText::isAlreadyShaped()} DECIDES CORRECTLY.
 *
 * The detection is a question about *bytes* - does this string contain Arabic presentation
 * forms - and bytes are all this app can see. What it cannot see is where a directory's
 * display names came from, and there is one case the bytes genuinely do not settle: text
 * holding presentation forms in **logical** rather than visual order. Skipping the shaper
 * leaves it unjoined and running the wrong way; running the shaper fixes it. Both are one
 * `occ` command away, and neither is a code change or a support ticket.
 *
 * So `auto` is the answer for every instance that has not been told otherwise, and the other
 * two exist so that an admin who can *see* the rendered watermark is never stuck arguing with
 * a heuristic about their own data.
 * ---------------------------------------------------------------------------
 *
 * ```
 * occ config:app:set files_watermark arabic_shaping --value auto     # shape unless already shaped (default)
 * occ config:app:set files_watermark arabic_shaping --value always   # shape unconditionally
 * occ config:app:set files_watermark arabic_shaping --value never    # draw the text exactly as configured
 * ```
 *
 * **This affects the image renderers only.** {@see PdfWatermarker} never calls
 * {@see ShapedText::shape()} - `getTextCell()` runs its own Bidi pass inside tc-lib-pdf - so
 * there is no second shaping to suppress there and nothing here would reach it. That
 * asymmetry is the same one {@see ShapedText} opens with, and it is why the reported bug was
 * visible in JPEG and PNG watermarks while the PDF ones read correctly.
 *
 * Read on the delivery path like every other `occ` setting in this app, so a bad value
 * degrades to the default rather than throwing - the reasoning in {@see ConfiguredLimits}
 * applies unchanged, and here it is sharper: a typo must not turn every watermarked download
 * on the instance into an HTTP 500.
 */
class ArabicShaping {

	/** App-config key. Deliberately not on the policy table - see {@see ConfiguredLimits}. */
	public const KEY_MODE = 'arabic_shaping';

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The configured mode, or {@see ShapedText::MODE_AUTO} when it is missing or unusable.
	 */
	public function mode(): string {
		try {
			$value = $this->appConfig->getValueString(
				Application::APP_ID,
				self::KEY_MODE,
				ShapedText::MODE_AUTO,
			);
		} catch (AppConfigTypeConflictException) {
			// Stored with an explicit non-string type (`occ config:app:set --type=integer`).
			$this->logger->warning('files_watermark: {key} is not stored as a string, using "{default}"', [
				'key' => self::KEY_MODE,
				'default' => ShapedText::MODE_AUTO,
			]);
			return ShapedText::MODE_AUTO;
		}

		// An admin typing `Never` or a trailing space in an `occ` command means what they
		// wrote; only genuinely unrecognised values are refused.
		$mode = strtolower(trim($value));

		if ($mode === '') {
			return ShapedText::MODE_AUTO;
		}

		if (!in_array($mode, ShapedText::MODES, true)) {
			// Named in full, because the alternative is an admin re-reading the docs to find
			// out which of three words they got wrong.
			$this->logger->warning('files_watermark: {key} is "{configured}", which is not one of {valid}; using "{default}"', [
				'key' => self::KEY_MODE,
				'configured' => $value,
				'valid' => implode(', ', ShapedText::MODES),
				'default' => ShapedText::MODE_AUTO,
			]);
			return ShapedText::MODE_AUTO;
		}

		return $mode;
	}
}
