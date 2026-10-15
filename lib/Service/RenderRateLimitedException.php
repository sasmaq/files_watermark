<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * An anonymous address has asked for more renders than {@see AnonymousRenderLimit} allows.
 *
 * Deliberately not a {@see WatermarkRequiredException}: that one means "this file cannot be
 * watermarked" and is answered with a 403, which a client reads as permanent. This one is
 * temporary, and every caller answers it with a 429 that says when to come back.
 */
class RenderRateLimitedException extends \RuntimeException {

	public function __construct(
		private int $retryAfter,
		?\Throwable $previous = null,
	) {
		parent::__construct('Too many watermarked downloads from this address; try again later.', 0, $previous);
	}

	/** Seconds until the window this was counted in has certainly passed. */
	public function getRetryAfter(): int {
		return $this->retryAfter;
	}
}
