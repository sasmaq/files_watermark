<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Com\Tecnick\Unicode\Bidi;

/**
 * Arabic text handling, shared by the two renderers.
 *
 * Two things the image renderers need and cannot do for themselves:
 *
 * - **the shaped, reordered form of a string** ({@see shape}), because neither GD nor a plain
 *   ImageMagick build will join Arabic letters or put them in visual order;
 * - **a real font file** ({@see bundledFontPath}), because they draw through FreeType and need
 *   bytes on disk rather than the metrics the PDF renderer consumes.
 *
 * **The PDF path must not call {@see shape()}.** `getTextCell()` runs the same Bidi pass
 * itself — verified by reading the bytes it emits, which are exactly the shaped codepoints —
 * so shaping first would put an already-visual string through reordering twice. That
 * asymmetry is the one thing to keep straight here: the PDF renderer hands over the original
 * string, the image renderers hand over the shaped one.
 */
final class ShapedText {

	/** Font program committed in `resources/fonts`, zlib-compressed. */
	private const FONT_ARCHIVE = 'ibmplexsansarabicb.z';

	/**
	 * Whether `$text` may need shaping — i.e. reaches beyond Latin-1.
	 *
	 * Not a font question any more: one face draws everything. It is a cheap guard so that
	 * Latin text skips the Bidi pass entirely, and the threshold stays U+00FF because
	 * nothing below it needs reordering or contextual forms.
	 */
	public static function mayNeedShaping(string $text): bool {
		return preg_match('/[^\x{0000}-\x{00FF}]/u', $text) === 1;
	}

	/**
	 * `$text` in visual order, with Arabic letters in their contextual forms.
	 *
	 * Arabic is written right to left and its letters change shape according to their
	 * neighbours; some pairs fuse into one glyph. `imagettftext()` draws the code points it
	 * is handed, in the order handed, so without this an Arabic watermark comes out as
	 * disconnected letters running backwards — legible to nobody, and *still a valid image*,
	 * which is why it needs asserting rather than eyeballing. ImageMagick shapes only when
	 * built against Raqm/HarfBuzz, which is not something to require of a host, so both
	 * image backends are fed the same shaped string and produce the same output everywhere.
	 *
	 * Measured on the probe string `الاختبار`: 8 code points in, 7 glyphs out, every one in
	 * Arabic Presentation Forms-B, including one lam-alef ligature — which is where the
	 * eighth went.
	 *
	 * **Depends on the `patches/` fixes to `tc-lib-unicode` having been applied.** Unpatched,
	 * the library drops the first character of any string containing a lam-alef pair, and
	 * reverses the words of a multi-word Latin name inside an RTL line. Composer applies both
	 * on every install, and `ShapedTextTest` fails if either did not run —
	 * `testShapedSequenceIsExact()` and `testLatinRunsAreNotReorderedInsideRtl()`
	 * respectively. The PDF renderer reaches the same library through `getTextCell()`, so the
	 * two patches cover both rendering paths.
	 */
	public static function shape(string $text): string {
		if (!self::mayNeedShaping($text)) {
			return $text;
		}

		$shaped = '';
		foreach ((new Bidi($text))->getOrdArray() as $codepoint) {
			$char = mb_chr($codepoint, 'UTF-8');
			// Not encodable as UTF-8, so the reordering produced something that is not a code
			// point. Appending it would drop that character silently and shift everything
			// after it; the unshaped source is wrong in a visible way instead of a quiet one.
			if ($char === false) {
				return $text;
			}
			$shaped .= $char;
		}

		return $shaped === '' ? $text : $shaped;
	}

	/**
	 * Path to a TrueType file the image renderers can draw with, or null if unavailable.
	 *
	 * The font is **not committed twice**. `resources/fonts/ibmplexsansarabicb.z` is the font
	 * program the PDF renderer embeds, and it is the original TTF under zlib — verified byte
	 * for byte against upstream. Inflating it here means the glyphs drawn into a JPEG are
	 * provably the same glyphs stamped into a PDF, which two copies of the file could not
	 * guarantee for long.
	 *
	 * Cached in the system temp directory, keyed by a hash of the archive, so the inflate
	 * happens once per host rather than once per watermark — the delivery triggers render on
	 * every fetch. A stale cache cannot be read: change the font and the key changes with it.
	 */
	public static function bundledFontPath(): ?string {
		$archive = PdfFontPath::directory() . DIRECTORY_SEPARATOR . self::FONT_ARCHIVE;
		if (!is_readable($archive)) {
			return null;
		}

		// False if the archive stopped being readable between the check above and here.
		// Without a key there is no cache path to speak of, so the caller is told there is no
		// font — the same answer the is_readable() guard gives.
		$key = hash_file('sha256', $archive);
		if ($key === false) {
			return null;
		}

		$cached = sys_get_temp_dir() . DIRECTORY_SEPARATOR
			. 'files_watermark_font_' . substr($key, 0, 16) . '.ttf';
		if (is_readable($cached) && filesize($cached) > 0) {
			return $cached;
		}

		$bytes = @gzuncompress((string)file_get_contents($archive));
		if ($bytes === false || $bytes === '') {
			return null;
		}

		// Written via a unique temp name and renamed, because two PHP workers can reach this
		// at once and a half-written font file is worse than no font file: FreeType would
		// reject it and the watermark would silently drop to the bitmap fallback.
		$partial = $cached . '.' . bin2hex(random_bytes(6));
		if (file_put_contents($partial, $bytes) === false || !rename($partial, $cached)) {
			@unlink($partial);
			return null;
		}

		return $cached;
	}
}
