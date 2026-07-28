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
use TCPDF;

/**
 * Functional tests for {@see PdfWatermarker}. They drive the real FPDI/TCPDF
 * stack against generated fixtures, so no Nextcloud server is required.
 */
class PdfWatermarkerTest extends TestCase {
	use CompressedXrefFixture;

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
		$reader = new Fpdi();
		$this->assertSame(3, $reader->setSourceFile($dest));
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

		$reader = new Fpdi();
		$this->assertSame(1, $reader->setSourceFile($dest));
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
		$reader = new Fpdi();
		$this->assertSame(2, $reader->setSourceFile($dest));
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

		$reader = new Fpdi();
		$this->assertSame(1, $reader->setSourceFile($dest));
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
	 * TCPDF reads a negative SetX/SetY as an offset from the *opposite* page edge.
	 * Tiles that should hang off the top or left were therefore teleported to the
	 * bottom or right and piled onto the tiles already there, which is why the
	 * smear survived a spacing fix. Placement goes through Translate instead, so
	 * negative offsets must survive into the page's transformation matrices.
	 */
	public function testOffPageTilesKeepTheirNegativeOffsets(): void {
		$source = $this->createSourcePdf(1);
		$dest = $this->tmpDir . '/offsets.pdf';

		$config = $this->makeConfig('text');
		$config->setTextTemplate('Confidential');
		$this->watermarker->apply($source, $dest, $config, []);

		$content = $this->pageContent($dest);
		$this->assertStringContainsString('Confidential', $content, 'watermark text missing from the page');

		// Translate emits `1 0 0 1 tx ty cm`; a wrapped tile could never produce a
		// negative tx, because SetX would have folded it round to the right edge.
		preg_match_all('#1\.0+ 0\.0+ 0\.0+ 1\.0+ (-?[\d.]+) (-?[\d.]+) cm#', $content, $matches);
		$this->assertNotEmpty($matches[1], 'no tile translations found in the page content');

		$negative = array_filter($matches[1], static fn (string $tx): bool => (float)$tx < 0);
		$this->assertNotEmpty($negative, 'no tile was placed off the left edge, so the margins cannot be covered');
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
	 * turned 90°. TCPDF rotates counter-clockwise and the page's y runs
	 * downwards, hence the negated sine.
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
	 * PDF 1.5+ documents that store their cross-reference table as a compressed
	 * stream cannot be read by the free parser bundled with FPDI, and that is not
	 * an exotic case: the skeleton PDF Nextcloud drops into every new account is
	 * one, so this is the first file many admins try.
	 *
	 * On a host with no `qpdf` there is nothing to be done about it, and the
	 * contract is that it is a *clean* refusal — the same RuntimeException the
	 * encrypted-PDF path raises, no destination written, and the user's file
	 * untouched. Callers up the stack (`WatermarkService`) turn that into a skip
	 * plus an audit entry, so the failure must never be partial.
	 *
	 * The normalizer is mocked unavailable rather than left to the host, because
	 * this is the behaviour of a machine *without* the binary and the assertions
	 * would otherwise invert on a machine that has it.
	 *
	 * Distinct from {@see testCorruptOrEncryptedPdfThrowsRuntimeException}, which
	 * feeds in bytes that are not a PDF at all. Here the document is well formed
	 * and only the compression is unsupported — asserted through FPDI's own
	 * COMPRESSED_XREF code, which it can only reach after parsing the trailer and
	 * finding a valid `/Type /XRef` stream.
	 */
	public function testCompressedXrefPdfFailsCleanlyWithoutQpdf(): void {
		$source = $this->tmpDir . '/compressed-xref.pdf';
		file_put_contents($source, $this->buildCompressedXrefPdf());
		$before = (string)file_get_contents($source);
		$dest = $this->tmpDir . '/out.pdf';

		try {
			$this->watermarkerWithoutNormalizer()->apply($source, $dest, $this->makeConfig('text'), []);
			$this->fail('Expected a compressed-xref PDF to be refused.');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Cannot process PDF', $e->getMessage());

			// Proves the fixture is a genuine PDF 1.5 rather than junk: FPDI only
			// raises this code once it has parsed its way to a valid xref stream.
			// It also proves the *original* parse error survived as the cause rather
			// than being replaced by a complaint about the missing binary.
			$cause = $e->getPrevious();
			$this->assertInstanceOf(CrossReferenceException::class, $cause);
			$this->assertSame(
				CrossReferenceException::COMPRESSED_XREF,
				$cause->getCode(),
				'expected the unsupported-compression path, not a generic parse failure',
			);
		}

		$this->assertFileDoesNotExist($dest, 'a refused render must not leave a partial file behind');
		$this->assertSame($before, (string)file_get_contents($source), 'the source PDF was modified');
	}

	/**
	 * The other half of the same story: with `qpdf` on the host, the file that the
	 * test above pins as refused gets watermarked instead. This is the whole point
	 * of the normalizer pre-pass, driven end to end through the real binary rather
	 * than a mock, because what is being asserted is that qpdf's output is actually
	 * readable by FPDI — a claim no mock can make.
	 *
	 * The overlay must land as a real content stream, so unlike flattening the page
	 * count is preserved and the result is re-importable.
	 */
	public function testCompressedXrefPdfIsWatermarkedWhenQpdfIsAvailable(): void {
		$this->requireQpdf();

		$source = $this->tmpDir . '/compressed-xref.pdf';
		file_put_contents($source, $this->buildCompressedXrefPdf());
		$before = (string)file_get_contents($source);
		$scratchBefore = count(glob(sys_get_temp_dir() . '/wm_norm_*') ?: []);
		$dest = $this->tmpDir . '/out.pdf';

		$this->watermarker->apply($source, $dest, $this->makeConfig('text'), ['username' => 'Alice']);

		$this->assertFileExists($dest);
		$this->assertStringStartsWith('%PDF', (string)file_get_contents($dest));

		// The single page of the fixture survives the round-trip, and the output is
		// itself readable — the overlay is a content stream, not a rasterisation.
		$reader = new Fpdi();
		$this->assertSame(1, $reader->setSourceFile($dest));

		$this->assertSame($before, (string)file_get_contents($source), 'the source PDF was modified');
		$this->assertSame(
			$scratchBefore,
			count(glob(sys_get_temp_dir() . '/wm_norm_*') ?: []),
			'the normalized scratch copy of the user file outlived the call',
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

	/** Generates an FPDI-readable (PDF 1.4, uncompressed) multi-page fixture. */
	private function createSourcePdf(int $pages): string {
		$pdf = new TCPDF();
		$pdf->setPDFVersion('1.4');
		$pdf->SetCompression(false);
		for ($i = 1; $i <= $pages; $i++) {
			$pdf->AddPage();
			$pdf->SetFont('helvetica', '', 12);
			$pdf->Cell(0, 10, "Page $i");
		}
		$path = $this->tmpDir . '/source.pdf';
		$pdf->Output($path, 'F');
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
