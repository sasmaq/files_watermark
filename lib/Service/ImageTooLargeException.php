<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * An image whose decoded size would exceed {@see ImageLimits::maxPixels}.
 *
 * A `RuntimeException` like every other refusal the render path raises, so the delivery
 * triggers need no new handling: `on_download` already degrades to the clean original and
 * `on_share` already denies on any render failure, which are the right answers here.
 *
 * It exists as its own type for one caller. `ApiController` maps a generic `RuntimeException`
 * to **422**, which is the honest status for "this file cannot be watermarked" but the wrong
 * one for "this file is too big" - the on-demand endpoint already answers **413** for a file
 * over the byte cap, and a user who hits the two caps should not get two different statuses
 * for the same class of refusal.
 */
class ImageTooLargeException extends \RuntimeException {
}
