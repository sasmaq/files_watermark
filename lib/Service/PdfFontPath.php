<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * Points both PDF stacks at the app's own font-metrics directory.
 *
 * `K_PATH_FONTS` is a **global constant**, which is not a design anyone would pick, but
 * it is the only mechanism tc-lib-pdf offers that survives a real deployment: its other
 * lookup walks up from the package looking for a directory named `fonts` and requires
 * that directory to be *writable*, which a hardened Nextcloud install will not be.
 *
 * It must be defined **before** the first font call, which is why it is claimed in
 * `Application::__construct()` at app bootstrap and again from the renderer's constructor
 * rather than lazily at draw time.
 *
 * Guarded with `defined()` because a constant cannot be redefined and another app on the
 * same server may have got there first — in which case that app's directory wins and
 * fonts silently come from elsewhere. {@see isUsingOwnFonts()} is how the renderer
 * notices instead of failing obscurely later.
 *
 * Historical note, because it explains a since-removed oddity: TCPDF read this same
 * constant and looked for `helvetica.php` where tc-lib-pdf looks for `helvetica.json`, so
 * while both stacks coexisted the directory had to carry both formats, and the path had to
 * end in a separator because TCPDF concatenated it with the filename directly. Neither
 * applies now — tc-lib-pdf joins with `DIRECTORY_SEPARATOR` itself.
 */
final class PdfFontPath {

	public const CONSTANT = 'K_PATH_FONTS';

	/** @return string absolute path to the font-metrics directory, no trailing separator */
	public static function directory(): string {
		return (string)realpath(__DIR__ . '/../../resources/fonts');
	}

	/**
	 * Define the constant unless something already did. Idempotent and safe to call on
	 * every render.
	 */
	public static function register(): void {
		if (!defined(self::CONSTANT)) {
			define(self::CONSTANT, self::directory());
		}
	}

	/**
	 * Whether the constant actually points at this app's fonts. False means another app
	 * defined it first, so `helvetica.json` will not be found and text rendering will
	 * throw — worth logging rather than leaving as a mystery.
	 */
	public static function isUsingOwnFonts(): bool {
		if (!defined(self::CONSTANT)) {
			return false;
		}
		return rtrim((string)constant(self::CONSTANT), DIRECTORY_SEPARATOR)
			=== rtrim(self::directory(), DIRECTORY_SEPARATOR);
	}
}
