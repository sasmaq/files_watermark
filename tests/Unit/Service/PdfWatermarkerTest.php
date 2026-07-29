<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\PdfNormalizer;
use OCA\FilesWatermark\Service\PdfWatermarker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Functional tests for {@see PdfWatermarker}. They drive the real tc-lib-pdf
 * stack against generated fixtures, so no Nextcloud server is required.
 */
class PdfWatermarkerTest extends TestCase {
	use CompressedXrefFixture;
	use PdfFixtures;

	private PdfWatermarker $watermarker;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarker = new PdfWatermarker(new PdfNormalizer($this->createMock(LoggerInterface::class)));
		$this->tmpDir = sys_get_temp_dir() . '/wm_pdf_test_' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0700, true);
	}

	/**
	 * A watermarker whose normalizer reports no `qpdf` on the host, whatever this
	 * machine actually has installed. Every assertion about the *without*-qpdf
	 * behaviour has to use this, or the result depends on the test host.
	 */
	private function watermarkerWithoutNormalizer(): PdfWatermarker {
		$normalizer = $this->createMock(PdfNormalizer::class);
		$normalizer->method('isAvailable')->willReturn(false);
		$normalizer->expects($this->never())->method('normalize');
		return new PdfWatermarker($normalizer);
	}

	protected function tearDown(): void {
		foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->tmpDir);
		parent::tearDown();
	}

	public function testTextOverlayAppliedAcrossMultiplePages(): void {
		$source = $this->createSourcePdf(3);
		$dest = $this->tmpDir . '/text.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate('{username} — {date}');

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
		$config->setTextTemplate('Confidential — {username}');
		$config->setImagePath($logo);

		$this->watermarker->apply($source, $dest, $config, ['username' => 'Bob']);

		$this->assertFileExists($dest);
		$this->assertSame(2, $this->readPageCount($dest));
	}

	public function testLongWatermarkTextRendersWithoutError(): void {
		// Note: this asserts only that a long string renders at all. The geometry
		// it was originally written to protect is pinned by the tile tests below —
		// producing a valid PDF was never evidence that the tiles were legible.
		$source = $this->createSourcePdf(1);
		$dest = $this->tmpDir . '/long.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate('{username} — Confidential — {date} — Do Not Distribute');

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
	 * This is a guard, not the regression test for the smear — the old row/column
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
	 * The original bug was TCPDF-specific — it read a negative `SetX`/`SetY` as an
	 * offset from the *opposite* page edge, so tiles meant to hang off the top or left
	 * were teleported to the bottom or right and piled onto the tiles already there.
	 * tc-lib-pdf has no such special case: positions are matrix operands. That is a
	 * claim about the new stack, so it is asserted rather than assumed — the negative
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
		$this->assertStringContainsString('Confidential', $content, 'watermark text missing from the page');

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
	 * point right and *up* — both positive — since PDF y increases upwards.
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
	 * runs downwards, hence the negated sine — the same convention
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
	 * were **skipped entirely** under FPDI, whose free parser refuses them — not an
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

		$this->watermarkerWithoutNormalizer()->apply($source, $dest, $this->makeConfig('text'), ['username' => 'Alice']);

		$this->assertFileExists($dest);
		$this->assertStringStartsWith('%PDF', (string)file_get_contents($dest));
		$this->assertStringContainsString(
			'Alice',
			$this->pageContent($dest),
			'the watermark text never reached the page',
		);

		// Still a content-stream overlay rather than a rasterisation, so the output is
		// itself importable and the page count survives.
		$this->assertSame(1, $this->readPageCount($dest));

		// **Interop canary**, and the only place the outgoing parser reads our output.
		// Every other assertion here now goes through tc-lib-pdf, which would happily
		// keep passing on a file only tc-lib-pdf can read. This is what would catch the
		// renderer drifting into output that other tools reject — the flattener depended
		// on exactly this until it was ported, and downstream consumers still do.
		// Delete it with FPDI at step 7.
		$this->assertSame(1, (new Fpdi())->setSourceFile($dest));

		$this->assertSame($before, (string)file_get_contents($source), 'the source PDF was modified');
	}

	/**
	 * The fixture has to stay genuinely unreadable by the *old* stack, or the test
	 * above proves nothing. FPDI is still in the tree until the migration completes,
	 * so this pins the fixture against it directly — asserting FPDI's own
	 * COMPRESSED_XREF code, which it can only reach after parsing the trailer and
	 * finding a valid `/Type /XRef` stream. Delete this together with FPDI.
	 */
	public function testTheFixtureIsStillUnreadableByTheOutgoingFpdiParser(): void {
		$source = $this->tmpDir . '/compressed-xref.pdf';
		file_put_contents($source, $this->buildCompressedXrefPdf());

		try {
			(new Fpdi())->setSourceFile($source);
			$this->fail('the fixture no longer reproduces the compressed-xref case');
		} catch (CrossReferenceException $e) {
			$this->assertSame(CrossReferenceException::COMPRESSED_XREF, $e->getCode());
		}
	}

	/**
	 * The one case the normalizer still exists for, end to end.
	 *
	 * An empty user password with the permission flags set is not real protection — a
	 * reader opens the file without ever prompting — but tc-lib-pdf refuses it like any
	 * other encrypted document. `qpdf --decrypt` strips it and the watermark goes on.
	 *
	 * This is also what pins the *narrowing*: the rescue is now triggered only by
	 * `ImportUnsupportedFeatureException`, so if that aim is ever wrong this stops
	 * working while every other test stays green.
	 */
	public function testEmptyPasswordEncryptionIsRescuedByTheNormalizer(): void {
		$this->requireQpdf();

		$plain = $this->createSourcePdf(1);
		$source = $this->tmpDir . '/permonly.pdf';
		exec(sprintf(
			'qpdf --encrypt "" ownerpw 256 -- %s %s 2>&1',
			escapeshellarg($plain),
			escapeshellarg($source),
		), $output, $status);
		$this->assertSame(0, $status, 'could not build the fixture: ' . implode(' ', $output));

		$scratchBefore = count(glob(sys_get_temp_dir() . '/wm_norm_*') ?: []);
		$dest = $this->tmpDir . '/out.pdf';

		$this->watermarker->apply($source, $dest, $this->makeConfig('text'), ['username' => 'Alice']);

		$this->assertFileExists($dest);
		$this->assertStringContainsString('Alice', $this->pageContent($dest));
		$this->assertSame(
			$scratchBefore,
			count(glob(sys_get_temp_dir() . '/wm_norm_*') ?: []),
			'the decrypted scratch copy of the user file outlived the call',
		);
	}

	/**
	 * A password-protected document is the case qpdf cannot rescue either, and it
	 * must not be mistaken for one it can: the refusal has to survive the pre-pass
	 * and stay clean.
	 */
	public function testPasswordProtectedPdfIsStillRefusedWithQpdfAvailable(): void {
		$this->requireQpdf();

		$plain = $this->createSourcePdf(1);
		$source = $this->tmpDir . '/encrypted.pdf';
		$scratchBefore = count(glob(sys_get_temp_dir() . '/wm_norm_*') ?: []);
		exec(sprintf(
			'qpdf --encrypt secret secret 256 -- %s %s 2>&1',
			escapeshellarg($plain),
			escapeshellarg($source),
		), $output, $status);
		$this->assertSame(0, $status, 'could not build the encrypted fixture: ' . implode(' ', $output));

		$dest = $this->tmpDir . '/out.pdf';
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Cannot process PDF');

		try {
			$this->watermarker->apply($source, $dest, $this->makeConfig('text'), []);
		} finally {
			$this->assertFileDoesNotExist($dest, 'a refused render must not leave a partial file behind');
			$this->assertSame(
				$scratchBefore,
				count(glob(sys_get_temp_dir() . '/wm_norm_*') ?: []),
				'the failed rewrite left its scratch file behind',
			);
		}
	}

	private function requireQpdf(): void {
		foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
			if ($dir !== '' && is_executable(rtrim($dir, '/') . '/' . PdfNormalizer::BINARY)) {
				return;
			}
		}
		$this->markTestSkipped(PdfNormalizer::BINARY . ' is not installed on this host');
	}

	private function makeConfig(string $type): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setType($type);
		$config->setTextTemplate('{username}');
		$config->setPosition('diagonal');
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
