<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * The file is bigger than {@see ApplyLimits} allows a watermark render to be.
 *
 * Raised when the file is *marked*, not when it is fetched, and that is the whole design of
 * the cap: a file this app refuses to render is a file it must refuse to promise a
 * watermark for. Marking it and discovering the problem at download time would leave the
 * reader with a 403 on a file nobody ever said no to.
 *
 * A sibling of {@see ImageTooLargeException}, which is the same refusal measured in pixels
 * rather than bytes. Neither implies the other - a 2 GB PDF has no pixels, and a 3 KB PNG
 * can have four billion - so both are checked and both answer 413.
 */
class FileTooLargeException extends \RuntimeException {
}
