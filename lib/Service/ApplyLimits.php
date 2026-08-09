<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * The ceiling on marking a file, in source bytes.
 *
 * Marking itself costs one insert - it is the *fetches* that render, every one of them,
 * synchronously inside the request, on a PHP worker rather than on a background job that
 * could be given its own limits. So the cap is enforced by `WatermarkService::mark()`, at
 * the only moment refusing is still a choice: past it the file is promised a watermark on
 * every delivery, and a ceiling discovered then would deny the download of a file nobody
 * was warned about. A file watermarked only because it is leaving through a share never has
 * such a moment, so `watermarkForDownload()` applies the same cap there instead.
 *
 * ---------------------------------------------------------------------------
 * WHY THE DEFAULT IS 64 MiB, WHICH IS SMALLER THAN IT LOOKS.
 *
 * This bounds *memory*, not disk, and the file's own size is the smallest part of what a
 * render costs. One render of an N-byte file holds, at peak:
 *
 *  - N for `getContent()`, held as a PHP string while it is staged to a temp file for the
 *    renderer
 *  - several × N for tc-lib-pdf's parsed object graph, which is the dominant term and the
 *    one that is not linear in any predictable way - a compressed object stream expands to
 *    whatever it expands to
 *  - the output document the renderer assembles before it is written out
 *
 * So peak sits somewhere around 4-6 × N for a PDF, against a `memory_limit` that is 512M
 * on a stock Nextcloud. 64 MiB of source is already the pessimistic end of comfortable
 * there, which is why the number is not the round 256 MiB {@see ArchiveLimits} uses: that
 * one bounds a temp *filesystem*, and disk is far cheaper than a worker's heap.
 *
 * **Raising this alone is not enough.** An admin who lifts the cap without lifting PHP's
 * `memory_limit` has moved the failure from a clean 413 at mark time to a fatal mid-render
 * at fetch time, which is the worse outcome twice over: a marked file whose render fails is
 * refused rather than served clean, so the file stops being downloadable **by anybody**, and
 * it fails once per fetch rather than once per file, with only the log to say why.
 *
 * ```
 * occ config:app:set files_watermark apply_max_bytes --value 134217728
 * ```
 * ---------------------------------------------------------------------------
 *
 * **What this does not bound.** The cap is in bytes on disk, and an image's decoded size
 * has little to do with that: GD holds roughly 4 bytes per pixel, so a highly compressed
 * PNG a few MiB wide can decode to far more than this cap allows a PDF to be. That is
 * {@see ImageLimits}, which is a separate ceiling on a separate quantity.
 */
class ApplyLimits extends ConfiguredLimits {

	/** Source bytes accepted for one on-demand apply. */
	public const KEY_MAX_BYTES = 'apply_max_bytes';

	public const DEFAULT_MAX_BYTES = 67108864; // 64 MiB of source content

	public function maxBytes(): int {
		return $this->limit(self::KEY_MAX_BYTES, self::DEFAULT_MAX_BYTES);
	}
}
