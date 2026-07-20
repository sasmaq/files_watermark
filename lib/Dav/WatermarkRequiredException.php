<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

/**
 * An archive member had to be watermarked but could not be.
 *
 * Raised during {@see ZipInterceptorPlugin}'s pre-render pass, before any bytes are sent,
 * so the download can be denied outright instead of shipping the clean original.
 */
class WatermarkRequiredException extends \RuntimeException {

	public function __construct(
		private string $path,
	) {
		parent::__construct("Member could not be watermarked: $path");
	}

	public function getPath(): string {
		return $this->path;
	}
}
