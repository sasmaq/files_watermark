<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;

/**
 * The type size a watermark is drawn at on one canvas - a PDF page or an image.
 *
 * A fixed size is a fixed number of points, and so a different fraction of every canvas: the
 * default 24 suits an A4 page, all but disappears into a 4000 × 3000 photo, and covers a
 * 300px image with a few letters. With `dynamicFontSize` on, the size is instead a fixed
 * fraction of the canvas's **shorter side**, and the configured `fontSize` is not read at all.
 * The gap between repetitions scales with the type ({@see TileLattice::GAP_FACTOR}), so the
 * whole pattern scales with it and every canvas carries about the same number of tiles.
 *
 * The shorter side rather than the width or the area: it is what a portrait and a landscape
 * copy of the same page have in common, and it is what the preview scaling in
 * {@see WatermarkService} already measures against.
 *
 * Asked per canvas, so a PDF mixing A4 and A3 pages sizes each page on its own.
 */
final class FontSize {

	/**
	 * The dynamic size as a fraction of the canvas's shorter side.
	 *
	 * 0.04 puts an A4 page (595pt) at 24pt - the shipped fixed default - so switching it on
	 * changes nothing on the commonest document and scales everything else from there. The
	 * settings preview mirrors it (`DYNAMIC_FONT_RATIO` in `WatermarkForm.vue`). Change one,
	 * change the other.
	 */
	public const DYNAMIC_RATIO = 0.04;

	/**
	 * The smallest dynamic size, and the same floor `saveConfig` clamps a fixed size to.
	 *
	 * There is no ceiling. A ceiling is the fixed-size problem again at the other end: the
	 * text would stop growing on exactly the large images this exists for.
	 */
	public const MIN = 6;

	public static function forCanvas(WatermarkConfig $config, float $width, float $height): int {
		if (!$config->getDynamicFontSize()) {
			return $config->getFontSize();
		}

		return max(self::MIN, (int)round(min($width, $height) * self::DYNAMIC_RATIO));
	}
}
