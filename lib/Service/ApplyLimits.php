<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * The ceiling on a single on-demand apply, in source bytes.
 *
 * An on-demand apply is the one expensive thing an ordinary user can ask for directly,
 * and until this existed nothing bounded it. `ApiController::applyWatermark()` renders
 * **synchronously, inside the request**, so the cost lands on a PHP worker rather than on
 * a background job that can be given its own limits.
 *
 * ---------------------------------------------------------------------------
 * WHY THE DEFAULT IS 64 MiB, WHICH IS SMALLER THAN IT LOOKS.
 *
 * This bounds *memory*, not disk, and the file's own size is the smallest part of what a
 * render costs. One apply of an N-byte file holds, at peak:
 *
 *  - N for `getContent()`, staged to a temp file for the renderer
 *  - several × N for tc-lib-pdf's parsed object graph, which is the dominant term and the
 *    one that is not linear in any predictable way - a compressed object stream expands to
 *    whatever it expands to
 *  - N again for the second `getContent()` that {@see OriginalStore::store} preserves
 *  - ~N reading the finished render back before `putContent()`
 *
 * So peak sits somewhere around 4-6 × N for a PDF, against a `memory_limit` that is 512M
 * on a stock Nextcloud. 64 MiB of source is already the pessimistic end of comfortable
 * there, which is why the number is not the round 256 MiB {@see ArchiveLimits} uses: that
 * one bounds a temp *filesystem*, and disk is far cheaper than a worker's heap.
 *
 * **Raising this alone is not enough.** An admin who lifts the cap without lifting PHP's
 * `memory_limit` has moved the failure from a clean 413 to a fatal mid-render, which is
 * the worse outcome - `watermarkInPlace()` is destructive, and the window where the
 * original has been read but the watermarked bytes are not yet written is exactly where
 * an OOM is least welcome.
 *
 * ```
 * occ config:app:set files_watermark apply_max_bytes --value 134217728
 * ```
 * ---------------------------------------------------------------------------
 *
 * **What this does not bound.** The cap is in bytes on disk, and an image's decoded size
 * has little to do with that: GD holds roughly 4 bytes per pixel, so a highly compressed
 * PNG a few MiB wide can decode to far more than this cap allows a PDF to be. A pixel
 * ceiling is a separate guard and is not built - see `doc/tasks.md`.
 */
class ApplyLimits extends ConfiguredLimits {

	/** Source bytes accepted for one on-demand apply. */
	public const KEY_MAX_BYTES = 'apply_max_bytes';

	public const DEFAULT_MAX_BYTES = 67108864; // 64 MiB of source content

	public function maxBytes(): int {
		return $this->limit(self::KEY_MAX_BYTES, self::DEFAULT_MAX_BYTES);
	}
}
