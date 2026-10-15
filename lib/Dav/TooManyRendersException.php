<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\FilesWatermark\Service\RenderRateLimitedException;
use Sabre\DAV\Exception;
use Sabre\DAV\Server;

/**
 * The DAV face of {@see RenderRateLimitedException}: a 429 with a `Retry-After`.
 *
 * Its own class rather than core's `OCA\DAV\Connector\Sabre\Exception\TooManyRequests`,
 * which extends `NotAuthenticated` - an anonymous client handed an authentication failure
 * may answer it with a credentials prompt, and nothing here is about credentials.
 */
class TooManyRendersException extends Exception {

	public function __construct(
		private int $retryAfter,
		?\Throwable $previous = null,
	) {
		parent::__construct('Too many watermarked downloads from this address; try again later.', 0, $previous);
	}

	public static function from(RenderRateLimitedException $e): self {
		return new self($e->getRetryAfter(), $e);
	}

	public function getHTTPCode() {
		return 429;
	}

	/**
	 * @return array<string, string>
	 */
	public function getHTTPHeaders(Server $server) {
		return ['Retry-After' => (string)$this->retryAfter];
	}
}
