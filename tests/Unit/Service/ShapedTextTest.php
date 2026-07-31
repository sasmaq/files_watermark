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

	/** @return list<int> */
	private function codepoints(string $text): array {
		$out = [];
		foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
			$out[] = mb_ord($char, 'UTF-8');
		}
		return $out;
	}
}
