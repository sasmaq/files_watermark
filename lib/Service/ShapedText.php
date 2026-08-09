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
 * itself - verified by reading the bytes it emits, which are exactly the shaped codepoints -
 * so shaping first would put an already-visual string through reordering twice. That
 * asymmetry is the one thing to keep straight here: the PDF renderer hands over the original
 * string, the image renderers hand over the shaped one.
 */
final class ShapedText {

	/** Font program committed in `resources/fonts`, zlib-compressed. */
	private const FONT_ARCHIVE = 'ibmplexsansarabicb.z';

	/** Shape unless the text arrives already shaped. The default, and what an admin wants. */
	public const MODE_AUTO = 'auto';

	/** Shape unconditionally - the behaviour before {@see isAlreadyShaped()} existed. */
	public const MODE_ALWAYS = 'always';

	/** Never shape; draw exactly what was configured. */
	public const MODE_NEVER = 'never';

	/** @var list<string> */
	public const MODES = [self::MODE_AUTO, self::MODE_ALWAYS, self::MODE_NEVER];

	/**
	 * `$text` with any byte sequence that is not valid UTF-8 removed.
	 *
	 * **This is what stands between one bad byte and an unreadable watermark.** Every
	 * string drawn here is assembled from places this app does not control - a display
	 * name or an email out of LDAP, a file name off the storage, a template an admin
	 * pasted - and one stray byte from a Windows-1256 or latin-1 round trip is enough to
	 * make the *whole* string undrawable: `preg_match()` with the `u` modifier fails on a
	 * malformed subject rather than reporting no match, and the shaping pass that
	 * {@see mayNeedShaping} gates never runs. The Arabic then renders in isolated forms,
	 * left to right - measured at 435px against the shaped 316px for the same words, with
	 * the lam-alef ligature gone. A valid image nobody can read, produced without an
	 * exception or a log line.
	 *
	 * Dropped rather than substituted: a name that arrives as `Ahmed` plus a broken byte
	 * should watermark as `Ahmed`, not `Ahmed?`. mbstring's substitution character is set
	 * and restored around the call because it is process-global state, and this must not
	 * change what any other code converts.
	 */
	public static function toValidUtf8(string $text): string {
		if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
			return $text;
		}

		$previous = mb_substitute_character();
		mb_substitute_character('none');
		try {
			// The pre-8.3 idiom for mb_scrub(): converting UTF-8 to UTF-8 re-encodes what is
			// valid and applies the substitution rule - here, dropping - to what is not.
			$scrubbed = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

			// Only reachable if mbstring rejects the encoding names, which are literals here -
			// an unknown one is a ValueError on PHP 8, not a `false`. Returning `$text` would
			// hand back the malformed bytes this exists to remove, so an empty watermark line
			// is the only fallback that keeps the promise the return type makes.
			return $scrubbed === false ? '' : $scrubbed;
		} finally {
			mb_substitute_character($previous);
		}
	}

	/**
	 * Whether `$text` may need shaping - i.e. reaches beyond Latin-1.
	 *
	 * Not a font question any more: one face draws everything. It is a cheap guard so that
	 * Latin text skips the Bidi pass entirely, and the threshold stays U+00FF because
	 * nothing below it needs reordering or contextual forms.
	 *
	 * **A subject `preg_match()` cannot even scan counts as "yes".** It returns `false`,
	 * not `0`, on malformed UTF-8, and `false === 1` reads as "no shaping needed" - which
	 * is exactly backwards, since a string carrying a broken byte is the one most likely to
	 * be non-Latin. That inversion is what shipped Arabic watermarks unshaped whenever a
	 * placeholder carried one bad byte. {@see shape()} repairs its input before asking, so
	 * this branch should now be unreachable from there; it stays because this method is
	 * public and its answer must not depend on the caller having scrubbed first.
	 */
	public static function mayNeedShaping(string $text): bool {
		$found = preg_match('/[^\x{0000}-\x{00FF}]/u', $text);
		if ($found === false) {
			return true;
		}

		return $found === 1;
	}

	/**
	 * Whether `$text` has **already** been through a shaper.
	 *
	 * ---------------------------------------------------------------------------
	 * WHY THIS HAS TO BE ASKED, AND WHY IT IS ASKED THIS WAY.
	 *
	 * {@see shape()} is **not idempotent**, and cannot be made so: shaping puts a string into
	 * *visual* order, and a second pass has no way to know that - it reads the visual order as
	 * logical and reverses it. Measured on `محمد`:
	 *
	 *     محمد          U+0645 U+062D U+0645 U+062F   logical, unshaped
	 *     shape(…)      U+FEAA U+FEE4 U+FEA4 U+FEE3   visual, presentation forms
	 *     shape(shape)  U+FEE3 U+FEA4 U+FEE4 U+FEAA   backwards, and still a valid image
	 *
	 * That second line is what a display name typed on Windows can arrive as. Legacy Windows
	 * Arabic tooling, text pasted out of a PDF, and directories populated from either store
	 * **presentation forms**, already in visual order, rather than the U+06xx letters a modern
	 * input method produces. Shaping those again is the reported bug: Arabic reversed in the
	 * rendered image while the same name reads correctly everywhere else in Nextcloud.
	 *
	 * The test is the presentation-form blocks themselves - Forms-A (U+FB50-U+FDFF) and
	 * Forms-B (U+FE70-U+FEFE) - because they are exactly the code points a shaper *emits* and
	 * a keyboard does not. **U+FEFF is deliberately excluded** though it closes the Forms-B
	 * block: it is the byte-order mark, not an Arabic glyph, and one stray BOM off a Windows
	 * clipboard would otherwise stop a perfectly ordinary name from being shaped at all.
	 * ---------------------------------------------------------------------------
	 *
	 * **A subject `preg_match()` cannot scan counts as "no"**, which points the same way as
	 * {@see mayNeedShaping()}: both failures fall towards shaping, because unshaped Arabic is
	 * the failure this app has already shipped once. {@see shape()} repairs its input first,
	 * so that branch is unreachable from there.
	 */
	public static function isAlreadyShaped(string $text): bool {
		return preg_match('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFE}]/u', $text) === 1;
	}

	/**
	 * `$text` in visual order, with Arabic letters in their contextual forms.
	 *
	 * Arabic is written right to left and its letters change shape according to their
	 * neighbours; some pairs fuse into one glyph. `imagettftext()` draws the code points it
	 * is handed, in the order handed, so without this an Arabic watermark comes out as
	 * disconnected letters running backwards - legible to nobody, and *still a valid image*,
	 * which is why it needs asserting rather than eyeballing. ImageMagick shapes only when
	 * built against Raqm/HarfBuzz, which is not something to require of a host, so both
	 * image backends are fed the same shaped string and produce the same output everywhere.
	 *
	 * Measured on the probe string `الاختبار`: 8 code points in, 7 glyphs out, every one in
	 * Arabic Presentation Forms-B, including one lam-alef ligature - which is where the
	 * eighth went.
	 *
	 * **Depends on the `patches/` fixes to `tc-lib-unicode` having been applied.** Unpatched,
	 * the library drops the first character of any string containing a lam-alef pair, and
	 * reverses the words of a multi-word Latin name inside an RTL line. Composer applies both
	 * on every install, and `ShapedTextTest` fails if either did not run -
	 * `testShapedSequenceIsExact()` and `testLatinRunsAreNotReorderedInsideRtl()`
	 * respectively. The PDF renderer reaches the same library through `getTextCell()`, so the
	 * two patches cover both rendering paths.
	 *
	 * **Text that is already shaped is returned untouched** ({@see isAlreadyShaped()}), which
	 * is what `$mode` exists to override. `auto` is the default and the answer for every
	 * instance that has not been told otherwise; `always` restores the unconditional pass for
	 * a directory whose Arabic is logical-order throughout and contains presentation forms
	 * only by accident; `never` draws exactly what was configured, for one that pre-shapes its
	 * own display names. {@see ArabicShaping} is where an admin sets it.
	 *
	 * @param string $mode one of {@see MODES}
	 */
	public static function shape(string $text, string $mode = self::MODE_AUTO): string {
		// Before anything asks a question about this string. Bidi walks it as UTF-8 and the
		// guards below scan it as UTF-8; neither can answer for bytes that are not, and the
		// failure mode of asking anyway is a silently unshaped watermark - see toValidUtf8().
		$text = self::toValidUtf8($text);

		if ($mode === self::MODE_NEVER) {
			return $text;
		}

		if (!self::mayNeedShaping($text)) {
			return $text;
		}

		// Ordered after mayNeedShaping() because it is the more expensive scan of the two and
		// answers false for everything Latin, which is most watermarks.
		if ($mode !== self::MODE_ALWAYS && self::isAlreadyShaped($text)) {
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
	 * program the PDF renderer embeds, and it is the original TTF under zlib - verified byte
	 * for byte against upstream. Inflating it here means the glyphs drawn into a JPEG are
	 * provably the same glyphs stamped into a PDF, which two copies of the file could not
	 * guarantee for long.
	 *
	 * Cached in the system temp directory, keyed by a hash of the archive, so the inflate
	 * happens once per host rather than once per watermark - the delivery triggers render on
	 * every fetch. A stale cache cannot be read: change the font and the key changes with it.
	 */
	public static function bundledFontPath(): ?string {
		$archive = PdfFontPath::directory() . DIRECTORY_SEPARATOR . self::FONT_ARCHIVE;
		if (!is_readable($archive)) {
			return null;
		}

		// False if the archive stopped being readable between the check above and here.
		// Without a key there is no cache path to speak of, so the caller is told there is no
		// font - the same answer the is_readable() guard gives.
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
