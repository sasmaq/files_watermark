<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\ShapedText;
use PHPUnit\Framework\TestCase;

/**
 * {@see ShapedText} — the shared answer to "can Helvetica draw this?" and "what does this
 * text look like once Arabic is shaped?".
 *
 * The assertions are on **code points**, not on "a string came back". Arabic that is
 * unshaped, or shaped but left in logical order, is still a perfectly valid PHP string and
 * still renders into a perfectly valid image — just one no reader can make sense of. Every
 * cheaper check passes on that.
 */
class ShapedTextTest extends TestCase {

	/**
	 * The probe string from the plan: `الاختبار` exercises reordering, medial forms and a
	 * lam-alef ligature in one word, so a half-working shaper cannot pass it.
	 */
	private const PROBE = 'الاختبار';

	public function testShapingProducesPresentationForms(): void {
		$shaped = ShapedText::shape(self::PROBE);
		$codepoints = $this->codepoints($shaped);

		$this->assertNotEmpty($codepoints);
		foreach ($codepoints as $codepoint) {
			$this->assertGreaterThanOrEqual(
				0xFE70,
				$codepoint,
				sprintf('U+%04X is not an Arabic presentation form, so the text was not shaped', $codepoint),
			);
			$this->assertLessThanOrEqual(0xFEFF, $codepoint);
		}
	}

	/**
	 * Eight letters in, seven glyphs out: lam followed by alef is a single glyph in Arabic,
	 * and a shaper that skipped the ligature would return eight.
	 */
	public function testLamAlefBecomesOneLigatureGlyph(): void {
		$this->assertCount(8, $this->codepoints(self::PROBE));

		$codepoints = $this->codepoints(ShapedText::shape(self::PROBE));

		$this->assertCount(7, $codepoints);
		$ligatures = array_filter($codepoints, static fn (int $c): bool => $c >= 0xFEF5 && $c <= 0xFEFC);
		$this->assertCount(1, $ligatures, 'the lam-alef pair did not become a ligature');
	}

	/**
	 * The whole shaped sequence, glyph by glyph, for four strings that put the lam-alef
	 * ligature in a different place each time.
	 *
	 * **Counting glyphs is not enough, and this suite learned that the hard way.** Every
	 * other test in this file passed while `tc-lib-unicode` was silently eating the first
	 * character of any string containing a lam-alef pair (see
	 * `patches/tc-lib-unicode-lam-alef.php` for the defect). The probe word hid it perfectly:
	 * its first letter *is* an alef, so losing it still left seven code points, still all in
	 * Presentation Forms-B, still starting at reh — every assertion above is satisfied by
	 * output that reads `الاختبار` minus a letter, with a stray lam where the alef should be.
	 *
	 * Only pinning the exact sequence catches it, so that is what this does. The cases are
	 * chosen so the ligature is never at the position whose loss would go unnoticed: a word
	 * where the first letter is unrelated to the ligature, one where the ligature ends the
	 * word, and one where Latin brackets the Arabic.
	 *
	 * @param list<int> $expected
	 *
	 * @dataProvider shapedSequenceProvider
	 */
	public function testShapedSequenceIsExact(string $text, array $expected, string $because): void {
		$this->assertSame($expected, $this->codepoints(ShapedText::shape($text)), $because);
	}

	/** @return array<string, array{string, list<int>, string}> */
	public static function shapedSequenceProvider(): array {
		return [
			// Visual order throughout: the array reads left to right across the page, so the
			// last letter of each Arabic word comes first.
			'probe word' => [
				self::PROBE,
				[0xFEAD, 0xFE8E, 0xFE92, 0xFE98, 0xFEA7, 0xFEFB, 0xFE8D],
				'reh, alef, beh, teh, khah, lam-alef, alef — the leading alef must survive',
			],
			'ligature after an unrelated first letter' => [
				'سلام',
				[0xFEE1, 0xFEFC, 0xFEB3],
				'the seen must be drawn as an initial form, not dropped in favour of a stray lam',
			],
			'ligature ends the word' => [
				'بلا',
				[0xFEFC, 0xFE91],
				'beh-initial then the lam-alef ligature; two glyphs from three letters',
			],
			'latin either side' => [
				'xلاy',
				[0x78, 0xFEFB, 0x79],
				'neither Latin letter may be consumed by the ligature',
			],
		];
	}

	/**
	 * A multi-word Latin name inside an Arabic line keeps its own left-to-right order.
	 *
	 * UAX #9 rule N1: a neutral between two same-direction characters takes that direction,
	 * so the space inside `John Doe` is `L` and the name is **one** left-to-right run.
	 * `tc-lib-unicode` never ran N1 at all — it gated the rule on a character type that the
	 * library never assigns — so each Latin word became its own run and a two-word name came
	 * out backwards: `سري - John Doe` drew as `Doe John - سري`. See
	 * `patches/patch-tc-lib-unicode-bidi-n1.php`.
	 *
	 * That is the default template shape (`سري - {displayname}`) for an Arabic deployment,
	 * and a watermark naming the wrong person is the one thing it exists not to do.
	 *
	 * Asserted on the **ASCII projection** — the non-Arabic characters of the shaped string,
	 * in the order they are drawn. It is the whole of what this rule governs, and unlike a
	 * full expected string it stays readable in source: the Arabic half would have to be
	 * written as presentation-form escapes, which
	 * {@see testShapedSequenceIsExact} already covers properly.
	 *
	 * @dataProvider rtlLatinRunProvider
	 */
	public function testLatinRunsAreNotReorderedInsideRtl(string $text, string $expected, string $because): void {
		$this->assertSame($expected, $this->asciiProjection(ShapedText::shape($text)), $because);
	}

	/** @return array<string, array{string, string, string}> */
	public static function rtlLatinRunProvider(): array {
		return [
			'the default template shape' => [
				'سري - John Doe',
				'John Doe - ',
				'the two words of the name must not swap',
			],
			'three Latin words' => [
				'محمد - John Q Public',
				'John Q Public - ',
				'a longer name reverses more visibly, and the middle initial moves too',
			],
			'no separator between the scripts' => [
				'سري John Doe',
				'John Doe ',
				'the run is decided by N1, not by the hyphen happening to be there',
			],
			'brackets and digits in the Latin run' => [
				'سري - Acme Corp Ltd (2026)',
				'Acme Corp Ltd (2026) - ',
				'N0 keeps the bracket pair with its run; the digits ride along',
			],
			'Latin base direction' => [
				'John Doe - سري',
				'John Doe - ',
				'an LTR paragraph was always correct and must stay that way',
			],
			'Arabic between two Latin words' => [
				'John سري Doe',
				'John  Doe',
				'the Arabic sits between them, so the two Latin runs are genuinely separate',
			],
			'digits before the name' => [
				'سري 123 John Doe',
				'John Doe 123 ',
				'the number is its own run and stays right of the name in an RTL line',
			],
		];
	}

	/**
	 * Arabic reads right to left, so the *last* letter of the source is the *first* glyph
	 * drawn. Without this the text renders backwards — which is exactly what GD does when
	 * handed the raw string, and it looks like a font problem rather than an ordering one.
	 */
	public function testTextIsReorderedIntoVisualOrder(): void {
		$codepoints = $this->codepoints(ShapedText::shape(self::PROBE));

		// U+FEAD is the final form of reh, the last letter of the probe word.
		$this->assertSame(0xFEAD, $codepoints[0], 'the last source letter should be drawn first');
	}

	/** @dataProvider latinProvider */
	public function testLatinIsLeftExactlyAsItIs(string $text): void {
		$this->assertSame($text, ShapedText::shape($text));
		$this->assertFalse(ShapedText::mayNeedShaping($text), 'Latin-1 needs no reordering or joining');
	}

	/** @return array<string, array{string}> */
	public static function latinProvider(): array {
		return [
			'plain' => ['Alice - 2026-07-31'],
			'punctuation' => ['Confidential! (do not share) #1'],
			// Latin-1 needs no shaping, so it must skip the Bidi pass untouched.
			'latin-1 accents' => ['Mönch Café'],
		];
	}

	public function testArabicIsRecognisedAsNeedingShaping(): void {
		$this->assertTrue(ShapedText::mayNeedShaping(self::PROBE));
		$this->assertTrue(ShapedText::mayNeedShaping('محمد - 2026-07-31'), 'mixed text still needs it');
	}

	/**
	 * The image renderers draw through FreeType and need real bytes on disk. Those bytes
	 * are the *same* ones the PDF renderer embeds — inflated from the one archive in
	 * `resources/fonts` rather than committed a second time, so the two cannot drift.
	 */
	public function testTheBundledFontIsAUsableTrueTypeFile(): void {
		$path = ShapedText::bundledFontPath();

		$this->assertNotNull($path, 'the bundled font could not be produced');
		$this->assertFileExists($path);
		$this->assertSame(
			"\x00\x01\x00\x00",
			substr((string)file_get_contents($path), 0, 4),
			'not a TrueType file',
		);

		if (extension_loaded('gd') && function_exists('imagettfbbox')) {
			$this->assertNotFalse(
				@imagettfbbox(24, 0, $path, ShapedText::shape(self::PROBE)),
				'FreeType rejected the bundled font',
			);
		}
	}

	/** The cache is keyed by the archive, so asking twice gives the same file. */
	public function testTheBundledFontIsCachedRatherThanReinflated(): void {
		$this->assertSame(ShapedText::bundledFontPath(), ShapedText::bundledFontPath());
	}

	/**
	 * The non-Arabic characters of a shaped string, in the order they are drawn.
	 *
	 * Everything Arabic shaping produces lands in Presentation Forms-B (U+FE70–U+FEFF), so
	 * dropping everything outside printable ASCII leaves exactly the Latin, digits, spaces
	 * and punctuation whose *ordering* rule N1 governs.
	 */
	private function asciiProjection(string $text): string {
		return (string)preg_replace('/[^\x20-\x7E]/u', '', $text);
	}

	/** @return list<int> */
	private function codepoints(string $text): array {
		$out = [];
		foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
			$out[] = mb_ord($char, 'UTF-8');
		}
		return $out;
	}
}
