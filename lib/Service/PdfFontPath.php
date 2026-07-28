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
 * Three things about that constant are load-bearing, all learned the hard way:
 *
 * 1. It must be defined **before** the first font call, which is why this is invoked from
 *    the renderer's constructor rather than lazily at draw time.
 * 2. TCPDF reads the same constant, and looks for `helvetica.php` where tc-lib-pdf looks
 *    for `helvetica.json`. Defining it therefore *breaks* TCPDF unless both formats live
 *    in the one directory — see `resources/fonts/README.md`. That matters while
 *    `PdfFlattener` is still on TCPDF.
 * 3. TCPDF concatenates the constant with the filename and does not insert a separator,
 *    so the trailing one here is not cosmetic.
 *
 * Guarded with `defined()` because a constant cannot be redefined and another app on the
 * same server may have got there first — in which case that app's directory wins and
 * fonts silently come from elsewhere. {@see isUsingOwnFonts()} is how the renderer
 * notices instead of failing obscurely later.
 */
final class PdfFontPath {

	public const CONSTANT = 'K_PATH_FONTS';

	/** @return string absolute path, with the trailing separator TCPDF requires */
	public static function directory(): string {
		return realpath(__DIR__ . '/../../resources/fonts') . DIRECTORY_SEPARATOR;
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
