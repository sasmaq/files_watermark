<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Middleware;

/**
 * A controller was about to read a protected file's stored bytes, and is not let run.
 *
 * Thrown from `beforeController()` - the only way a middleware can stop a controller - and
 * turned back into a response by the `afterException()` of the middleware that threw it,
 * in whatever shape that controller's client expects an error in.
 */
class ProtectedFileException extends \RuntimeException {
}
