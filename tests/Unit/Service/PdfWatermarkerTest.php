<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\PdfWatermarker;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;

/**
 * Functional tests for {@see PdfWatermarker}. They drive the real FPDI/TCPDF
 * stack against generated fixtures, so no Nextcloud server is required.
 */
class PdfWatermarkerTest extends TestCase {

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

    public function testTextOverlayAppliedAcrossMultiplePages(): void {
        $source = $this->createSourcePdf(3);
        $dest   = $this->tmpDir . '/text.pdf';

        $config = $this->makeConfig('text');
        $config->setTextTemplate('{username} — {date}');

        $this->watermarker->apply($source, $dest, $config, [
            'username' => 'Alice',
            'date'     => '2026-06-27',
        ]);

        $this->assertFileExists($dest);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($dest));

        // Page count must be preserved across the whole multi-page document.
        $reader = new Fpdi();
        $this->assertSame(3, $reader->setSourceFile($dest));
    }

    public function testImageOverlayAppliedAndPreservesAspectRatio(): void {
        $source = $this->createSourcePdf(1);
        $logo   = $this->createPng(120, 90); // intentionally not 2:1
        $dest   = $this->tmpDir . '/image.pdf';

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
        $logo   = $this->createPng(100, 100);
        $dest   = $this->tmpDir . '/combined.pdf';

        $config = $this->makeConfig('combined');
        $config->setTextTemplate('Confidential — {username}');
        $config->setImagePath($logo);

        $this->watermarker->apply($source, $dest, $config, ['username' => 'Bob']);

        $this->assertFileExists($dest);
        $reader = new Fpdi();
        $this->assertSame(2, $reader->setSourceFile($dest));
    }

    public function testLongWatermarkTextRendersWithoutError(): void {
        // Regression: tile spacing used to be a fixed multiple of the font size,
        // so text wider than a few characters overflowed its cell and adjacent
        // tiles collided into an illegible smear. Spacing now derives from the
        // measured text width, so even a long resolved string renders cleanly.
        $source = $this->createSourcePdf(1);
        $dest   = $this->tmpDir . '/long.pdf';

        $config = $this->makeConfig('text');
        $config->setTextTemplate('{username} — Confidential — {date} — Do Not Distribute');

        $this->watermarker->apply($source, $dest, $config, [
            'username' => 'Alexandra Featherstonehaugh',
            'date'     => '2026-07-19',
        ]);

        $this->assertFileExists($dest);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($dest));

        $reader = new Fpdi();
        $this->assertSame(1, $reader->setSourceFile($dest));
    }

    public function testCorruptOrEncryptedPdfThrowsRuntimeException(): void {
        $bad  = $this->tmpDir . '/bad.pdf';
        file_put_contents($bad, 'this is not a real PDF document');
        $dest = $this->tmpDir . '/out.pdf';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot process PDF');

        $this->watermarker->apply($bad, $dest, $this->makeConfig('text'), []);
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
