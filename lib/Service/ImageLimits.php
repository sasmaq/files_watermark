<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * The ceiling on an image's *decoded* size, in pixels.
 *
 * {@see ApplyLimits} bounds bytes on disk, which for an image says almost nothing about
 * what rendering it costs. Both GD and Imagick work on an uncompressed bitmap, and the
 * compression ratio between the two is unbounded: a PNG of a single flat colour is a few
 * kilobytes on disk and a few gigabytes decoded. That gap is the classic decompression
 * bomb, and until this existed it was the one way left to exhaust a worker through this
 * app - the byte cap waves such a file straight through.
 *
 * ---------------------------------------------------------------------------
 * WHY 40 MEGAPIXELS.
 *
 * GD's truecolor images cost **4 bytes per pixel**, and `ImageWatermarker` composites in
 * place rather than onto a second canvas, so the decoded bitmap is the peak term:
 *
 *   40,000,000 px × 4 B = 160 MB, on top of the source string the byte cap already allows
 *
 * That fits a stock 512M `memory_limit` with room for the rest of the request, and it is
 * chosen to sit **above ordinary photography rather than below it**: a 24 MP camera frame
 * (6000 × 4000) and a full 8K image (7680 × 4320, 33.2 MP) both pass. What it refuses is
 * the 50 MP-and-up end and anything synthetic - which is the whole point, since a real
 * bomb is not 41 MP, it is four gigapixels.
 *
 * This is a **separate** setting from `apply_max_bytes` and not a replacement for it.
 * Neither implies the other: a 2 GB PDF has no pixels, and a 3 KB PNG can have four
 * billion.
 *
 * ```
 * occ config:app:set files_watermark image_max_pixels --value 80000000
 * ```
 * ---------------------------------------------------------------------------
 *
 * Enforced **twice, against different bytes**, and it has to be. When a mark is placed the
 * only cheap measurement available is the image header - `assertPixelsAllowedFromHeader()`
 * reads the first few KiB rather than the whole file - and that is what refuses the mark
 * under both triggers. The render then measures the image it actually receives
 * (`assertPixelsAllowed()`, inside `renderToTemp()`), because an overwrite keeps the mark:
 * the bytes being decoded on a fetch are not necessarily the bytes that were measured when
 * the mark went on, and a guard that only ran at mark time would leave the bomb reachable
 * by uploading it over something already marked.
 */
class ImageLimits extends ConfiguredLimits {

	/** Pixels (width × height) accepted for a decode. */
	public const KEY_MAX_PIXELS = 'image_max_pixels';

	public const DEFAULT_MAX_PIXELS = 40000000; // 40 MP - about 160 MB decoded

	public function maxPixels(): int {
		return $this->limit(self::KEY_MAX_PIXELS, self::DEFAULT_MAX_PIXELS);
	}
}
