<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * The PDF is locked with a real password, so no watermark can be drawn on it.
 *
 * Told apart from every other unreadable PDF because the policy treats it differently: the
 * README promises that a password-protected document is *skipped* - left unmarked and
 * served as stored, with an audit row saying so - where a document that is merely broken
 * is marked and then refused at delivery. The password is what still protects it; the app
 * never guesses one and never uses the owner entry as a bypass.
 */
class PasswordProtectedPdfException extends \RuntimeException {
}
