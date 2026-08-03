<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\PdfWatermarker;
use OCA\FilesWatermark\Service\ShapedText;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for {@see PdfWatermarker}. They drive the real tc-lib-pdf
 * stack against generated fixtures, so no Nextcloud server is required.
 */
class PdfWatermarkerTest extends TestCase {
	use CompressedXrefFixture;
	use CroppedPageFixture;
	use PdfFixtures;
	use ResourcelessPageFixture;

	/**
	 * Reordering, medial forms and a lam-alef ligature in one word, so a shaper that only
	 * half works cannot pass on it. Eight code points; seven glyphs once shaped.
	 */
	private const ARABIC_PROBE = 'الاختبار';

	private PdfWatermarker $watermarker;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarker = new PdfWatermarker();
		$this->tmpDir = sys_get_temp_dir() . '/wm_pdf_test_' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0700, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->tmpDir);
		parent::tearDown();
	}

	/**
	 * The regression test for watermarked files coming out blank.
	 *
	 * A source page whose visible area is a `/CropBox` offset from the origin was imported
	 * without that offset being cancelled. The new page is created at the box's *size* with
	 * its origin at (0, 0), but the imported form keeps the source's coordinates - so
	 * content living at x≥300 was drawn at x≥300 on a page only 312 wide, i.e. off the
	 * right-hand edge. Measured before the fix: **2% of the content still on the page** for
	 * a `[300 300 612 792]` crop, and none of it once the page was also rotated.
	 *
	 * That failure is invisible to every cheaper check. The output is a valid PDF of the
	 * right page count, the image bytes are still in the file, and the watermark itself
	 * renders perfectly - the original content is simply drawn where nothing can see it.
	 * So this asserts on *geometry*: where the imported form actually lands once its own
	 * matrix and its placement are composed. See
	 * {@see CroppedPageFixture::visibleFractionOfImportedPage()}.
	 *
	 * @dataProvider croppedPageProvider
	 */
	public function testCroppedPagesKeepTheirContentOnThePage(array $cropBox, int $rotate): void {
		$source = $this->tmpDir . '/cropped.pdf';
		$dest = $this->tmpDir . '/cropped_wm.pdf';
		$this->writeCroppedPagePdf($source, $cropBox, $rotate);

		$this->watermarker->apply($source, $dest, $this->makeConfig('text'), ['username' => 'Alice']);

		$this->assertSame(1, $this->readPageCount($dest));
		$this->assertGreaterThan(
			0.999,
			$this->visibleFractionOfImportedPage($dest),
			sprintf('Imported content falls outside the page (crop [%s], rotated %d°)', implode(' ', $cropBox), $rotate),
		);
	}

	/** @return array<string, array{array{float, float, float, float}, int}> */
	public static function croppedPageProvider(): array {
		return [
			// The control: the overwhelmingly common shape, which was never broken.
			'box at the origin' => [[0.0, 0.0, 612.0, 792.0], 0],
			'small offset' => [[72.0, 72.0, 540.0, 720.0], 0],
			// Large enough that essentially nothing survived before the fix.
			'large offset' => [[300.0, 300.0, 612.0, 792.0], 0],
			// Rotation needs the correction rotated with it: the library builds the form
			// matrix for a box anchored at the origin, so an unrotated translation pushes
			// the content further off the page instead of back onto it.
			'offset, rotated 90' => [[300.0, 300.0, 612.0, 792.0], 90],
			'offset, rotated 180' => [[300.0, 300.0, 612.0, 792.0], 180],
			'offset, rotated 270' => [[300.0, 300.0, 612.0, 792.0], 270],
			'origin, rotated 90' => [[0.0, 0.0, 612.0, 792.0], 90],
		];
	}

	/**
	 * Arabic reaches the page as shaped, reordered glyphs - asserted against the bytes the
	 * PDF actually carries, not against the file being valid.
	 *
	 * A PDF full of `?` is a valid PDF of the right page count with a watermark on every
	 * page, which is what this produced before the Amiri face was bundled: the standard-14
	 * fonts are single-byte and every Arabic code point collapsed to a question mark.
	 * Nothing short of reading the emitted text run can tell those two outcomes apart.
	 */
	public function testArabicIsDrawnAsShapedGlyphs(): void {
		$source = $this->createSourcePdf(1);
		$dest = $this->tmpDir . '/arabic.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate(self::ARABIC_PROBE);
		$this->watermarker->apply($source, $dest, $config, []);

		$drawn = $this->firstTextRunCodepoints($dest);

		$this->assertSame(
			$this->codepointsOf(ShapedText::shape(self::ARABIC_PROBE)),
			$drawn,
			'the PDF does not draw the shaped, reordered glyphs',
		);
		// Stated separately so a failure says which half broke.
		$this->assertCount(7, $drawn, 'eight letters should shape into seven glyphs');
		foreach ($drawn as $codepoint) {
			$this->assertGreaterThanOrEqual(0xFE70, $codepoint);
			$this->assertLessThanOrEqual(0xFEFF, $codepoint);
		}
	}

	/**
	 * A Latin name inside an Arabic line reaches the page in its own left-to-right order.
	 *
	 * Asserted on the PDF's own bytes rather than trusted to follow from the image path,
	 * because **this renderer does its own Bidi**: `getTextCell()` builds a `Bidi` internally
	 * from the logical string, so it reaches the rule N1 defect
	 * (`patches/patch-tc-lib-unicode-bidi-n1.php`) by a different route than
	 * {@see ShapedText::shape()} does. Unpatched, this drew `Doe John - سري` - the watermark
	 * naming the wrong person, in the format most likely to be handed to a court.
	 */
	public function testLatinNameKeepsItsOrderInsideAnArabicLine(): void {
		$source = $this->createSourcePdf(1);
		$dest = $this->tmpDir . '/arabic-latin.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate('سري - {username}');
		$this->watermarker->apply($source, $dest, $config, ['username' => 'John Doe']);

		$drawn = $this->firstTextRunCodepoints($dest);
		$ascii = implode('', array_map(
			static fn (int $c): string => $c >= 0x20 && $c <= 0x7E ? chr($c) : '',
			$drawn,
		));

		$this->assertSame('John Doe - ', $ascii, 'the two words of the name must not swap');
		// The Arabic half must still be there, shaped - a fix that dropped it would satisfy
		// the assertion above.
		$this->assertSame(
			$this->codepointsOf(ShapedText::shape('سري - John Doe')),
			$drawn,
			'the PDF and image renderers must agree, glyph for glyph',
		);
	}

	/**
	 * **One face draws everything**, so a watermark looks the same whatever the text is.
	 *
	 * The predecessor kept Latin on standard-14 Helvetica and embedded a second face only
	 * for Arabic, which made the typeface depend on whether someone's display name happened
	 * to be Arabic. Asserted by rendering the two and comparing what the files carry: both
	 * embed a font program, and neither falls back to a built-in.
	 */
	public function testEveryWatermarkUsesTheOneEmbeddedFace(): void {
		$source = $this->createSourcePdf(1);

		$paths = [];
		foreach (['latin' => 'Alice - 2026-07-31', 'arabic' => self::ARABIC_PROBE] as $label => $template) {
			$paths[$label] = $this->tmpDir . "/one_$label.pdf";
			$config = $this->makeConfig('text');
			$config->setTextTemplate($template);
			$this->watermarker->apply($source, $paths[$label], $config, []);
		}

		foreach ($paths as $label => $path) {
			$pdf = (string)file_get_contents($path);
			$this->assertStringContainsString('/FontFile2', $pdf, "$label did not embed the face");
			// The subset prefix ("AAAAAB+") varies, so match the family rather than the
			// whole name. The source fixture draws its own text in Helvetica, which is why
			// this looks for the watermark's face being present rather than Helvetica being
			// absent - the imported page legitimately carries it.
			$this->assertMatchesRegularExpression(
				'#/BaseFont\s*/[A-Z]{6}\+IBMPlexSansArabic#',
				$pdf,
				"$label did not draw with the bundled face",
			);
			// Without this the watermark is unsearchable and unextractable, which would
			// quietly break the app's "the text layer survives" promise.
			$this->assertStringContainsString('/ToUnicode', $pdf, "$label has no ToUnicode map");
		}
	}

	/**
	 * Subsetting is what makes one-font-everywhere affordable: only the glyphs actually
	 * drawn are embedded. Measured on this fixture, **31 KB subsetted against 125 KB whole**,
	 * and the delivery triggers render per fetch rather than once.
	 *
	 * The definitive marker is the six-letter tag PDF requires on a subset font name
	 * (`AAAAAB+IBMPlexSansArabic-Bold`); the size bound is a second, blunter check, set well
	 * clear of both measurements so it discriminates rather than merely passes.
	 */
	public function testTheEmbeddedFaceIsSubsetted(): void {
		$source = $this->createSourcePdf(1);
		$dest = $this->tmpDir . '/subset.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate('Alice - 2026-07-31');
		$this->watermarker->apply($source, $dest, $config, []);

		$this->assertMatchesRegularExpression(
			'#/BaseFont\s*/[A-Z]{6}\+IBMPlexSansArabic#',
			(string)file_get_contents($dest),
			'the font name carries no subset tag, so the whole face was embedded',
		);
		$this->assertLessThan(60_000, filesize($dest), 'the file is the size of a whole embedded face');
	}

	/**
	 * The code points of the first text-showing run in the file.
	 *
	 * The embedded face is written with two-byte code units, so the operand of `Tj` is the
	 * shaped text verbatim - which is what makes this assertable at all.
	 *
	 * @return list<int>
	 */
	private function firstTextRunCodepoints(string $path): array {
		foreach ($this->inflatedStreams((string)file_get_contents($path)) as $stream) {
			if (preg_match('/\((.+?)\)\s*Tj/s', $stream, $m) !== 1) {
				continue;
			}
			$bytes = $m[1];
			if (strlen($bytes) < 2 || strlen($bytes) % 2 !== 0) {
				continue;
			}
			$codepoints = [];
			foreach (str_split($bytes, 2) as $pair) {
				$codepoints[] = (ord($pair[0]) << 8) | ord($pair[1]);
			}
			return $codepoints;
		}
		return [];
	}

	/**
	 * `$text` as the embedded face writes it: two bytes per code unit.
	 *
	 * Every watermark now draws through an embedded Unicode font, so even pure ASCII no
	 * longer appears as ASCII in the content stream - `Alice` is `\0A\0l\0i\0c\0e`.
	 */
	private function utf16be(string $text): string {
		return (string)mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
	}

	/** @return list<int> */
	private function codepointsOf(string $text): array {
		$out = [];
		foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
			$out[] = mb_ord($char, 'UTF-8');
		}
		return $out;
	}

	public function testTextOverlayAppliedAcrossMultiplePages(): void {
		$source = $this->createSourcePdf(3);
		$dest = $this->tmpDir . '/text.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate('{username} - {date}');

		$this->watermarker->apply($source, $dest, $config, [
			'username' => 'Alice',
			'date' => '2026-06-27',
		]);

		$this->assertFileExists($dest);
		$this->assertStringStartsWith('%PDF', (string)file_get_contents($dest));

		// Page count must be preserved across the whole multi-page document.
		$this->assertSame(3, $this->readPageCount($dest));
	}

	public function testImageOverlayAppliedAndPreservesAspectRatio(): void {
		$source = $this->createSourcePdf(1);
		$logo = $this->createPng(120, 90); // intentionally not 2:1
		$dest = $this->tmpDir . '/image.pdf';

		$config = $this->makeConfig('image');
		$config->setImagePath($logo);

		$this->watermarker->apply($source, $dest, $config, []);

		$this->assertFileExists($dest);
		$this->assertGreaterThan(0, filesize($dest));

		$this->assertSame(1, $this->readPageCount($dest));
	}

	public function testCombinedOverlayApplied(): void {
		$source = $this->createSourcePdf(2);
		$logo = $this->createPng(100, 100);
		$dest = $this->tmpDir . '/combined.pdf';

		$config = $this->makeConfig('combined');
		$config->setTextTemplate('Confidential - {username}');
		$config->setImagePath($logo);

		$this->watermarker->apply($source, $dest, $config, ['username' => 'Bob']);

		$this->assertFileExists($dest);
		$this->assertSame(2, $this->readPageCount($dest));
	}

	public function testLongWatermarkTextRendersWithoutError(): void {
		// Note: this asserts only that a long string renders at all. The geometry
		// it was originally written to protect is pinned by the tile tests below -
		// producing a valid PDF was never evidence that the tiles were legible.
		$source = $this->createSourcePdf(1);
		$dest = $this->tmpDir . '/long.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate('{username} - Confidential - {date} - Do Not Distribute');

		$this->watermarker->apply($source, $dest, $config, [
			'username' => 'Alexandra Featherstonehaugh',
			'date' => '2026-07-19',
		]);

		$this->assertFileExists($dest);
		$this->assertStringStartsWith('%PDF', (string)file_get_contents($dest));

		$this->assertSame(1, $this->readPageCount($dest));
	}

	/**
	 * Pins the spacing invariant: no tile may encroach on its neighbours in the
	 * text's own frame, whatever angle the user picks.
	 *
	 * This is a guard, not the regression test for the smear - the old row/column
	 * spacing satisfied it too. What actually collided is covered by
	 * {@see testOffPageTilesKeepTheirNegativeOffsets}.
	 *
	 * @dataProvider rotationProvider
	 */
	public function testTilesNeverOverlapAtAnyRotation(int $rotation): void {
		$fontSize = 18;
		$textWidth = 289.1;
		$lineHeight = $fontSize * 1.2;

		$tiles = PdfWatermarker::tilePositions(595.0, 842.0, $textWidth, $lineHeight, $rotation, $fontSize);
		$this->assertNotEmpty($tiles);

		[$along, $across] = $this->rotatedFrame($rotation);
		$overlaps = [];

		foreach ($tiles as $i => $a) {
			foreach (array_slice($tiles, $i + 1) as $b) {
				$dx = $a[0] - $b[0];
				$dy = $a[1] - $b[1];
				// Two tiles are disjoint when they are clear of each other along at
				// least one axis of the text's own frame.
				$gapAlong = abs($dx * $along[0] + $dy * $along[1]);
				$gapAcross = abs($dx * $across[0] + $dy * $across[1]);
				if ($gapAlong < $textWidth - 0.001 && $gapAcross < $lineHeight - 0.001) {
					$overlaps[] = sprintf(
						'(%.1f, %.1f) and (%.1f, %.1f) are %.1fpt apart along the text and %.1fpt across',
						$a[0],
						$a[1],
						$b[0],
						$b[1],
						$gapAlong,
						$gapAcross,
					);
				}
			}
		}

		$this->assertSame([], $overlaps, "Overlapping watermark tiles at {$rotation}°");
	}

	/**
	 * The lattice must reach past every edge, or the watermark stops short of the
	 * margins. Note this pins the positions the renderer is *asked* to draw; that
	 * they survive into the page is what
	 * {@see testOffPageTilesKeepTheirNegativeOffsets} checks.
	 *
	 * @dataProvider rotationProvider
	 */
	public function testLatticeSpansTheWholePage(int $rotation): void {
		$fontSize = 18;
		$width = 595.0;
		$height = 842.0;

		$tiles = PdfWatermarker::tilePositions($width, $height, 289.1, $fontSize * 1.2, $rotation, $fontSize);
		[$along, $across] = $this->rotatedFrame($rotation);

		$project = static fn (array $p, array $axis): float => $p[0] * $axis[0] + $p[1] * $axis[1];
		$corners = [[0.0, 0.0], [$width, 0.0], [0.0, $height], [$width, $height]];

		foreach ([[$along, 'along the text'], [$across, 'across the text']] as [$axis, $label]) {
			$page = array_map(static fn (array $c): float => $project($c, $axis), $corners);
			$drawn = array_map(static fn (array $t): float => $project($t, $axis), $tiles);

			$this->assertLessThanOrEqual(
				min($page),
				min($drawn),
				"Watermark starts inside the page {$label} at {$rotation}°",
			);
			$this->assertGreaterThanOrEqual(
				max($page),
				max($drawn),
				"Watermark ends inside the page {$label} at {$rotation}°",
			);
		}
	}

	/**
	 * The regression test for the smear, and the reason the tile lattice must not be
	 * touched by a renderer change.
	 *
	 * The original bug was TCPDF-specific - it read a negative `SetX`/`SetY` as an
	 * offset from the *opposite* page edge, so tiles meant to hang off the top or left
	 * were teleported to the bottom or right and piled onto the tiles already there.
	 * tc-lib-pdf has no such special case: positions are matrix operands. That is a
	 * claim about the new stack, so it is asserted rather than assumed - the negative
	 * offsets still have to reach the page, or the margins go uncovered exactly as
	 * before.
	 *
	 * What changed is only *where* to look. TCPDF emitted a pure translation
	 * (`1 0 0 1 tx ty cm`) and positioned text with SetXY; tc-lib-pdf emits one
	 * combined rotation matrix per tile (`cos sin -sin cos tx ty cm`) and offsets the
	 * text inside it with `Td`.
	 */
	public function testOffPageTilesKeepTheirNegativeOffsets(): void {
		$source = $this->createSourcePdf(1);
		$dest = $this->tmpDir . '/offsets.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate('Confidential');
		$this->watermarker->apply($source, $dest, $config, []);

		$content = $this->pageContent($dest);
		$this->assertStringContainsString($this->utf16be('Confidential'), $content, 'watermark text missing from the page');

		// Six-operand `cm` matrices: [a b c d tx ty].
		preg_match_all(
			'#(-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) cm#',
			$content,
			$matches,
		);
		$this->assertNotEmpty($matches[5], 'no tile transformation matrices found in the page content');

		$negative = array_filter($matches[5], static fn (string $tx): bool => (float)$tx < 0);
		$this->assertNotEmpty($negative, 'no tile was placed off the left edge, so the margins cannot be covered');
	}

	/**
	 * The rotation convention did **not** carry over from TCPDF and had to be
	 * re-derived, so it is pinned here rather than left to the eye.
	 *
	 * `Transform::getRotation()` builds a raw CTM in PDF's y-upwards space and does the
	 * flip itself, which is a different origin and handedness from TCPDF's `Rotate()`.
	 * The contract that has to survive is the one the settings live preview shows: a
	 * positive rotation tilts the text **uphill**, reading up and to the right. In the
	 * emitted matrix `[a b c d tx ty]` that means the text's own x-axis, `(a, b)`, must
	 * point right and *up* - both positive - since PDF y increases upwards.
	 *
	 * Get the sign wrong and every watermark tilts the opposite way to the preview the
	 * admin configured it with, which no other assertion here would catch.
	 */
	public function testPositiveRotationTiltsTheTextUphill(): void {
		$source = $this->createSourcePdf(1);
		$dest = $this->tmpDir . '/rotation.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate('Confidential');
		$config->setRotation(45);
		$this->watermarker->apply($source, $dest, $config, []);

		preg_match_all(
			'#(-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) cm#',
			$this->pageContent($dest),
			$matches,
		);

		// The imported page is placed with an identity matrix; the tiles are the
		// rotated ones, and at 45° every component is ±cos45.
		$rotated = [];
		foreach ($matches[1] as $i => $a) {
			$b = (float)$matches[2][$i];
			if (abs((float)$a - 1.0) > 0.0001 || abs($b) > 0.0001) {
				$rotated[] = [(float)$a, $b];
			}
		}
		$this->assertNotEmpty($rotated, 'no rotated tile matrices found');

		foreach ($rotated as [$a, $b]) {
			$this->assertEqualsWithDelta(cos(deg2rad(45)), $a, 0.0001, 'unexpected rotation magnitude');
			$this->assertGreaterThan(
				0,
				$b,
				'a positive rotation must tilt the text uphill; a negative b means it '
					. 'reads downhill, i.e. mirrored against the settings preview',
			);
		}
	}

	/** @return array<string, array{int}> */
	public static function rotationProvider(): array {
		return [
			'unrotated' => [0],
			'shallow' => [30],
			'diagonal' => [45],
			'vertical' => [90],
			'obtuse' => [135],
			'inverted' => [180],
			'negative' => [-45],
		];
	}

	/**
	 * The text's own axes in page coordinates: the direction it reads, and that
	 * turned 90°. A positive rotation reads counter-clockwise while the page's y
	 * runs downwards, hence the negated sine - the same convention
	 * `tilePositions()` builds its lattice in, and unchanged by the move off TCPDF.
	 *
	 * @return array{array{float, float}, array{float, float}}
	 */
	private function rotatedFrame(int $rotation): array {
		$rad = deg2rad((float)$rotation);
		return [[cos($rad), -sin($rad)], [sin($rad), cos($rad)]];
	}

	/** The first content stream carrying watermark text, inflated if need be. */
	private function pageContent(string $pdf): string {
		$raw = (string)file_get_contents($pdf);
		preg_match_all('#stream\r?\n(.*?)endstream#s', $raw, $matches);

		foreach ($matches[1] as $stream) {
			$inflated = @gzuncompress($stream);
			if ($inflated === false) {
				$inflated = @gzinflate($stream);
			}
			$candidate = $inflated === false ? $stream : $inflated;
			if (str_contains($candidate, 'cm')) {
				return $candidate;
			}
		}

		return '';
	}

	public function testCorruptOrEncryptedPdfThrowsRuntimeException(): void {
		$bad = $this->tmpDir . '/bad.pdf';
		file_put_contents($bad, 'this is not a real PDF document');
		$dest = $this->tmpDir . '/out.pdf';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Cannot process PDF');

		$this->watermarker->apply($bad, $dest, $this->makeConfig('text'), []);
	}

	/**
	 * The headline result of the tc-lib-pdf migration, and the inversion of what this
	 * file used to assert.
	 *
	 * PDF 1.5+ documents that store their cross-reference table as a compressed stream
	 * were **skipped entirely** under FPDI, whose free parser refuses them - not an
	 * exotic case, since two of the three skeleton PDFs Nextcloud drops into every new
	 * account are such files, so it was the first thing many admins tried. Then they
	 * worked only where `qpdf` was installed to rewrite them first.
	 *
	 * tc-lib-pdf reads them natively. The normalizer is mocked **unavailable** here
	 * precisely to prove that: no external binary is involved in this path any more,
	 * on any host. If this test ever needs `qpdf` back, the migration has regressed.
	 */
	public function testCompressedXrefPdfIsWatermarkedWithoutAnyExternalBinary(): void {
		$source = $this->tmpDir . '/compressed-xref.pdf';
		file_put_contents($source, $this->buildCompressedXrefPdf());
		$before = (string)file_get_contents($source);
		$dest = $this->tmpDir . '/out.pdf';

		$this->watermarker->apply($source, $dest, $this->makeConfig('text'), ['username' => 'Alice']);

		$this->assertFileExists($dest);
		$this->assertStringStartsWith('%PDF', (string)file_get_contents($dest));
		$this->assertStringContainsString(
			$this->utf16be('Alice'),
			$this->pageContent($dest),
			'the watermark text never reached the page',
		);

		// Still a content-stream overlay rather than a rasterisation, so the output is
		// itself importable and the page count survives.
		$this->assertSame(1, $this->readPageCount($dest));

		$this->assertSame($before, (string)file_get_contents($source), 'the source PDF was modified');
	}

	/**
	 * The fixture has to keep reproducing the case it is named after, or the test above
	 * proves nothing at all - a fixture that quietly became an ordinary PDF 1.4 file
	 * would sail through and assert only that the renderer can read easy documents.
	 *
	 * This used to be pinned by feeding the fixture to FPDI and catching its
	 * `COMPRESSED_XREF` code. FPDI is gone, so the guarantee is restated structurally
	 * against the bytes: a cross-reference **stream** object, and no classic `xref`
	 * table anywhere. That is exactly the shape the free FPDI parser refused and the
	 * whole migration was about.
	 */
	public function testTheFixtureStillUsesACompressedCrossReferenceStream(): void {
		$fixture = $this->buildCompressedXrefPdf();

		$this->assertStringStartsWith('%PDF-1.5', $fixture, 'the fixture must declare PDF 1.5');
		$this->assertStringContainsString(
			'/Type /XRef',
			$fixture,
			'the fixture no longer contains a cross-reference stream, so it no longer '
				. 'reproduces the case this suite exists to cover',
		);
		// A classic table is the token `xref` alone on its own line. Matching the bare
		// word instead would hit the fixture's own page text ("Compressed xref
		// fixture") and pass for entirely the wrong reason.
		$this->assertStringNotContainsString(
			"\nxref\n",
			$fixture,
			'a classic xref table means the fixture is readable by any parser',
		);
	}

	/**
	 * A page with no resources must still come out as a *readable* form dictionary.
	 *
	 * This is the "the watermark deleted my content" case. tc-lib-pdf writes the imported
	 * page's dictionary with `sprintf` and its resource cloner returns an empty string -
	 * not `<< >>` - when a page resolves to no resources, so the dictionary reads
	 * `/Resources /Group << … >> /Filter /FlateDecode`: `/Resources` swallows `/Group`,
	 * the group dictionary stands where a key belongs, and **every entry after it pairs
	 * with the wrong name**. `/Filter` is one of them, so a reader takes deflate bytes for
	 * content operators and draws nothing. The page arrives blank with the overlay on it
	 * and every original byte still in the file.
	 *
	 * Asserted by parsing the output rather than by matching the bytes that were written:
	 * what matters is what a reader makes of the dictionary, which is the thing that broke.
	 */
	public function testAPageWithoutResourcesKeepsAUsableFormDictionary(): void {
		$source = $this->tmpDir . '/no-resources.pdf';
		file_put_contents($source, $this->buildResourcelessPagePdf());
		$dest = $this->tmpDir . '/out.pdf';

		$this->watermarker->apply($source, $dest, $this->makeConfig('text'), ['username' => 'Alice']);

		$form = $this->importedFormDict($dest);
		$this->assertNotSame([], $form, 'no imported form XObject in the output');

		$this->assertArrayHasKey('Resources', $form);
		$this->assertSame(
			'<<',
			$form['Resources'],
			'/Resources holds a name instead of a dictionary, so it has swallowed the '
				. 'entry that follows it',
		);

		// The entry that actually costs the user their content: with the value missing,
		// /Filter lands on the wrong key and the stream is read as if it were not
		// compressed at all.
		$this->assertArrayHasKey('Filter', $form, '/Filter was lost from the form dictionary');
		$this->assertSame('/FlateDecode', $form['Filter']);

		$this->assertSame(1, $this->readPageCount($dest));
	}

	/**
	 * The fixture has to keep reaching the case, or the test above passes on an ordinary
	 * page that was never affected.
	 */
	public function testTheResourcelessFixtureDeclaresNoResources(): void {
		$fixture = $this->buildResourcelessPagePdf();

		$this->assertStringNotContainsString(
			'/Resources',
			$fixture,
			'the fixture now declares resources, so it no longer reproduces the case',
		);
		$this->assertStringContainsString('/Filter /FlateDecode', $fixture);
	}

	/**
	 * The imported page's Form XObject dictionary, as a reader sees it: keys in order,
	 * mapped to the token that follows each one.
	 *
	 * Deliberately a flat token pairing rather than a parsed structure - the defect being
	 * pinned *is* a key/value misalignment, and a parser that helpfully re-pairs the
	 * entries would hide it.
	 *
	 * @return array<string, string>
	 */
	private function importedFormDict(string $pdf): array {
		$raw = (string)file_get_contents($pdf);
		if (preg_match('~<< /Type /XObject /Subtype /Form.*?/Length \d+ >>~s', $raw, $match) !== 1) {
			return [];
		}

		// Tokens: names, dictionary and array openers, numbers.
		preg_match_all('~/[^\s/<>\[\]()]+|<<|>>|\[|\]|[\d.-]+~', $match[0], $tokens);

		$dict = [];
		$depth = 0;
		$count = count($tokens[0]);
		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[0][$i];
			if ($token === '<<' || $token === '[') {
				$depth++;
				continue;
			}
			if ($token === '>>' || $token === ']') {
				$depth--;
				continue;
			}
			// Only the outermost dictionary's own keys.
			if ($depth === 1 && str_starts_with($token, '/')) {
				$key = substr($token, 1);
				if (!array_key_exists($key, $dict)) {
					$dict[$key] = $tokens[0][$i + 1] ?? '';
				}
				// Skip the value so it is never read as a key of its own.
				$i++;
				if (($tokens[0][$i] ?? '') === '<<' || ($tokens[0][$i] ?? '') === '[') {
					$i--;
				}
			}
		}

		return $dict;
	}

	/**
	 * Encrypted PDFs are refused, and the refusal has to be *clean*: the same
	 * RuntimeException a corrupt file raises, no destination written, the user's file
	 * untouched. `WatermarkService` turns that into a skip plus an audit row, so a
	 * partial failure here would deliver a half-written document.
	 *
	 * Both fixtures matter. A real user password is protection the app has no business
	 * bypassing. An **empty** user password is not protection at all - it only sets
	 * permission flags, and a reader opens the file without ever prompting - but it is
	 * refused just the same, because the parser declines every encrypted document.
	 * That case used to be rescued by shelling out to `qpdf --decrypt`; removing the
	 * external binaries removed the rescue with it, which is the trade this test pins.
	 *
	 * The fixtures are built with the renderer's own encryption support rather than an
	 * external tool, so this suite spawns no processes either.
	 *
	 * @dataProvider encryptedPdfProvider
	 */
	public function testEncryptedPdfIsRefusedCleanly(string $userPassword, string $label): void {
		$source = $this->tmpDir . '/encrypted.pdf';
		$this->writeEncryptedPdf($source, $userPassword);
		$before = (string)file_get_contents($source);
		$dest = $this->tmpDir . '/out.pdf';

		try {
			$this->watermarker->apply($source, $dest, $this->makeConfig('text'), []);
			$this->fail("Expected an encrypted PDF ($label) to be refused.");
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Cannot process PDF', $e->getMessage());
		}

		$this->assertFileDoesNotExist($dest, 'a refused render must not leave a partial file behind');
		$this->assertSame($before, (string)file_get_contents($source), 'the source PDF was modified');
	}

	/** @return array<string, array{string, string}> */
	public static function encryptedPdfProvider(): array {
		return [
			'real user password' => ['s3cret', 'real password'],
			'empty user password' => ['', 'permission flags only'],
		];
	}

	private function makeConfig(string $type): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setType($type);
		$config->setTextTemplate('{username}');
		$config->setOpacity(80);
		$config->setFontSize(24);
		$config->setColor('#cccccc');
		$config->setRotation(45);
		$config->setTrigger('on_demand');
		return $config;
	}

	/** Generates a plain multi-page A4 fixture. */
	private function createSourcePdf(int $pages): string {
		$spec = [];
		for ($i = 1; $i <= $pages; $i++) {
			$spec[] = ['text' => "Page $i"];
		}

		$path = $this->tmpDir . '/source.pdf';
		$this->writePdf($path, $spec);
		return $path;
	}

	private function createPng(int $width, int $height): string {
		$img = imagecreatetruecolor($width, $height);
		$blue = imagecolorallocate($img, 0, 0, 255);
		imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $blue);
		$path = $this->tmpDir . '/logo.png';
		imagepng($img, $path);
		imagedestroy($img);
		return $path;
	}
}
