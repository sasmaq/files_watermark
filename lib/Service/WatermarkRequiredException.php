<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * A marked file had to be watermarked and could not be.
 *
 * **This is a denial, not a warning.** A mark is a promise that nobody reads the file
 * without their name on it, so the one thing a failed render may not do is fall back to the
 * original - that would hand the clean bytes to precisely the reader the mark exists to
 * name. Every delivery path turns this into a 403.
 *
 * It carries the path because the archive path needs it: a member that cannot be rendered
 * aborts the whole download, and the error has to say which file did it.
 */
class WatermarkRequiredException extends \RuntimeException {

	public function __construct(
		private string $path,
		string $message = '',
		?\Throwable $previous = null,
	) {
		parent::__construct($message !== '' ? $message : "This file could not be watermarked: $path", 0, $previous);
	}

	public function getPath(): string {
		return $this->path;
	}
}
