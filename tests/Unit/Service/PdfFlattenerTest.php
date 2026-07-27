<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\PdfFlattener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;

/**
 * Tests for {@see PdfFlattener}.
 *
 * The rasterise leg needs the real `pdftoppm`, so those cases skip when the
 * binary is absent — a host without poppler-utils is a supported configuration,
 * not a broken checkout. Everything reachable without it (availability, the
 * ceilings, fail-closed behaviour) runs everywhere.
 */
class PdfFlattenerTest extends TestCase {

	private PdfFlattener $flattener;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$this->flattener = new PdfFlattener($this->createMock(LoggerInterface::class));
		$this->tmpDir = sys_get_temp_dir() . '/wm_flat_test_' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0700, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->tmpDir);
		parent::tearDown();
	}

	private function requireRenderer(): void {
		if (!$this->flattener->isAvailable()) {
			$this->markTestSkipped(PdfFlattener::RENDERER . ' is not installed on this host');
		}
	}

	public function testAvailabilityTracksTheRendererOnPath(): void {
		// Whatever this host has, the probe and a PATH lookup must agree — the
		// admin form and the API gate both hang off this answer.
		$found = false;
		foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
			if ($dir !== '' && is_executable(rtrim($dir, '/') . '/' . PdfFlattener::RENDERER)) {
				$found = true;
				break;
			}
		}

		$this->assertSame($found, $this->flattener->isAvailable());
	}

	public function testFlattenedOutputHasNoExtractableTextLayer(): void {
		// The actual security claim. If text survives, the watermark is still a
		// separate object and the feature has not done its job.
		$this->requireRenderer();
		$source = $this->createSourcePdf(['Sensitive body text', 'CONFIDENTIAL watermark']);
		$dest = $this->tmpDir . '/flat.pdf';

		$this->flattener->flatten($source, $dest, 72);

		$this->assertStringNotContainsString('Sensitive body text', $this->extractText($dest));
		$this->assertStringNotContainsString('CONFIDENTIAL watermark', $this->extractText($dest));
		// ...while the source, for contrast, does carry it.
		$this->assertStringContainsString('Sensitive body text', $this->extractText($source));
	}

	public function testEachSourcePageYieldsExactlyOnePage(): void {
		// Catches the margin / auto-page-break spill: a full-bleed image on a page
		// with default margins pushes onto a second page, doubling the document.
		$this->requireRenderer();
		$source = $this->createSourcePdf(['One', 'Two', 'Three']);
		$dest = $this->tmpDir . '/pages.pdf';

		$this->flattener->flatten($source, $dest, 72);

		$reader = new Fpdi();
		$this->assertSame(3, $reader->setSourceFile($dest));
	}

	public function testPageGeometrySurvivesForNonA4AndLandscapePages(): void {
		$this->requireRenderer();
		$source = $this->tmpDir . '/mixed.pdf';
		$pdf = new TCPDF('P', 'pt', 'A4');
		$pdf->setPDFVersion('1.4');
		$pdf->SetCompression(false);
		$pdf->SetPrintHeader(false);
		$pdf->SetPrintFooter(false);
		$pdf->AddPage('P', 'A5');
		$pdf->AddPage('L', 'A4');
		$pdf->Output($source, 'F');

		$expected = $this->pageSizes($source);
		$dest = $this->tmpDir . '/mixed-flat.pdf';

		$this->flattener->flatten($source, $dest, 72);

		$actual = $this->pageSizes($dest);
		$this->assertCount(2, $actual);
		foreach ($expected as $page => $size) {
			$this->assertEqualsWithDelta($size['width'], $actual[$page]['width'], 1.0, "page $page width");
			$this->assertEqualsWithDelta($size['height'], $actual[$page]['height'], 1.0, "page $page height");
		}
	}

	public function testHigherDpiProducesALargerFile(): void {
		// Proves the DPI knob reaches the renderer rather than being ignored.
		$this->requireRenderer();
		$source = $this->createSourcePdf(['Resolution probe']);

		$this->flattener->flatten($source, $this->tmpDir . '/low.pdf', 72);
		$this->flattener->flatten($source, $this->tmpDir . '/high.pdf', 200);

		$this->assertGreaterThan(
			(int)filesize($this->tmpDir . '/low.pdf'),
			(int)filesize($this->tmpDir . '/high.pdf'),
		);
	}

	public function testOutputIsStillAValidPdf(): void {
		$this->requireRenderer();
		$source = $this->createSourcePdf(['Round trip']);
		$dest = $this->tmpDir . '/valid.pdf';

		$this->flattener->flatten($source, $dest, 72);

		$this->assertStringStartsWith('%PDF', (string)file_get_contents($dest));
		$reader = new Fpdi();
		$this->assertSame(1, $reader->setSourceFile($dest));
	}

	public function testCorruptSourceFailsClosed(): void {
		// No output file, and an exception — never a silent pass-through of the
		// unflattened input, which would be the removable-overlay version.
		$this->requireRenderer();
		$bad = $this->tmpDir . '/bad.pdf';
		file_put_contents($bad, 'this is not a real PDF document');
		$dest = $this->tmpDir . '/never.pdf';

		try {
			$this->flattener->flatten($bad, $dest, 72);
			$this->fail('Expected a RuntimeException for a corrupt source');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Cannot flatten PDF', $e->getMessage());
		}

		$this->assertFileDoesNotExist($dest);
	}

	public function testMissingSourceFailsClosed(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Cannot flatten PDF');

		$this->flattener->flatten($this->tmpDir . '/absent.pdf', $this->tmpDir . '/out.pdf');
	}

	public function testPageCeilingIsEnforced(): void {
		$this->requireRenderer();
		$source = $this->createSourcePdf(array_fill(0, 201, 'page'));
		$dest = $this->tmpDir . '/too-many.pdf';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('exceeds the 200 page ceiling');

		$this->flattener->flatten($source, $dest, 72);
	}

	public function testDpiIsClampedToTheSupportedRange(): void {
		// A caller cannot make the renderer produce a 20000-DPI page: that is a
		// memory and disk denial of service, not a quality setting.
		$this->requireRenderer();
		$source = $this->createSourcePdf(['Clamp']);

		$this->flattener->flatten($source, $this->tmpDir . '/absurd.pdf', 20000);
		$this->flattener->flatten($source, $this->tmpDir . '/max.pdf', PdfFlattener::MAX_DPI);

		$this->assertSame(
			filesize($this->tmpDir . '/max.pdf'),
			filesize($this->tmpDir . '/absurd.pdf'),
			'a DPI above the ceiling should render exactly as the ceiling does',
		);
	}

	public function testNoPageBitmapsAreLeftBehind(): void {
		$this->requireRenderer();
		$before = count(glob(sys_get_temp_dir() . '/wm_flat_*') ?: []);
		$source = $this->createSourcePdf(['One', 'Two']);

		$this->flattener->flatten($source, $this->tmpDir . '/clean.pdf', 72);

		$this->assertSame($before, count(glob(sys_get_temp_dir() . '/wm_flat_*') ?: []));
	}

	/** @param list<string> $pages one line of body text per page */
	private function createSourcePdf(array $pages): string {
		$pdf = new TCPDF('P', 'pt', 'A4');
		$pdf->setPDFVersion('1.4');
		$pdf->SetCompression(false);
		$pdf->SetPrintHeader(false);
		$pdf->SetPrintFooter(false);
		foreach ($pages as $text) {
			$pdf->AddPage();
			$pdf->SetFont('helvetica', '', 14);
			$pdf->Text(60, 120, $text);
		}
		$path = $this->tmpDir . '/source.pdf';
		$pdf->Output($path, 'F');
		return $path;
	}

	/** @return array<int, array{width: float, height: float}> */
	private function pageSizes(string $pdf): array {
		$reader = new Fpdi();
		$count = $reader->setSourceFile($pdf);
		$sizes = [];
		for ($page = 1; $page <= $count; $page++) {
			$size = $reader->getTemplateSize($reader->importPage($page));
			$sizes[$page] = ['width' => (float)$size['width'], 'height' => (float)$size['height']];
		}
		return $sizes;
	}

	/**
	 * Text recoverable from the PDF's content streams. Crude next to a real
	 * extractor, but it is looking for the *absence* of glyphs, and TCPDF writes
	 * page text as plain `(...) Tj` / `[(...)] TJ` operators.
	 */
	private function extractText(string $pdf): string {
		$raw = (string)file_get_contents($pdf);
		preg_match_all('#stream\r?\n(.*?)endstream#s', $raw, $matches);

		$text = $raw;
		foreach ($matches[1] as $stream) {
			$inflated = @gzuncompress($stream);
			if ($inflated === false) {
				$inflated = @gzinflate($stream);
			}
			if ($inflated !== false) {
				$text .= $inflated;
			}
		}

		return $text;
	}
}
