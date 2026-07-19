<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

/**
 * The archive exceeds the ceiling on how much content one request will watermark.
 *
 * Only ever raised for best-effort triggers: under `on_share` an over-cap archive is
 * denied ({@see WatermarkRequiredException}) rather than served unwatermarked.
 */
class ArchiveTooLargeException extends \RuntimeException {
}
