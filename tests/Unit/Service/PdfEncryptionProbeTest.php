<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\PdfEncryptionProbe;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

/**
 * The cheap first look marking on upload takes at every PDF, so that only the ones that
 * might be encrypted are read in full.
 *
 * A wrong "no" is the expensive mistake - a locked document marked into a file nobody can
 * download - so these pin that every real encrypted file says "maybe", wherever its trailer
 * sits, and that doubt answers "maybe" too.
 *
 * @covers \OCA\FilesWatermark\Service\PdfEncryptionProbe
 */
class PdfEncryptionProbeTest extends TestCase {
	use PdfFixtures;

	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$this->tmpDir = sys_get_temp_dir() . '/wm_probe_test_' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0700, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->tmpDir);
		parent::tearDown();
	}

	/** A `File` whose bytes are $bytes, opened as a fresh seekable stream each time. */
	private function file(string $bytes, bool $seekable = true): File {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(strlen($bytes));
		$file->method('fopen')->willReturnCallback(static function () use ($bytes, $seekable) {
			if (!$seekable) {
				// A pipe is the honest stand-in for an object-store stream that cannot seek.
				// `true` closes at once, so nothing here can block on the runner's stdin.
				$pipe = popen('true', 'r');
				return $pipe === false ? false : $pipe;
			}
			$handle = fopen('php://memory', 'r+');
			fwrite($handle, $bytes);
			rewind($handle);
			return $handle;
		});

		return $file;
	}

	/** @dataProvider encryptionModeProvider */
	public function testAnEncryptedPdfIsAMaybe(int $mode): void {
		$path = $this->tmpDir . '/encrypted.pdf';
		$this->writeEncryptedPdf($path, 's3cret', $mode, 'Protected');

		$this->assertTrue(PdfEncryptionProbe::mayBeEncrypted($this->file((string)file_get_contents($path))));
	}

	public function testAPlainPdfIsAFirmNo(): void {
		$path = $this->tmpDir . '/plain.pdf';
		$this->writePdf($path, [['text' => 'Nothing to see']]);

		$this->assertFalse(PdfEncryptionProbe::mayBeEncrypted($this->file((string)file_get_contents($path))));
	}

	/**
	 * **The trailer is found where `startxref` points, not only at the tail.** A
	 * cross-reference stream's dictionary is the trailer, and a large one leaves `/Encrypt`
	 * further from the end than any fixed window.
	 */
	public function testATrailerFarFromTheEndIsFoundThroughStartxref(): void {
		$body = "%PDF-1.7\n" . str_repeat("% filler\n", 2000);
		$offset = strlen($body);
		$bytes = $body
			. "9 0 obj\n<< /Type /XRef /Encrypt 3 0 R /Length 9000 >>\nstream\n"
			. str_repeat("\x00", 9000)
			. "\nendstream\nendobj\nstartxref\n" . $offset . "\n%%EOF\n";

		$this->assertTrue(PdfEncryptionProbe::mayBeEncrypted($this->file($bytes)));
	}

	/** A linearised file names its trailer at the head. */
	public function testATrailerAtTheHeadIsFound(): void {
		$bytes = "%PDF-1.7\n1 0 obj << /Linearized 1 >> endobj\ntrailer << /Encrypt 5 0 R >>\n"
			. str_repeat("% filler\n", 2000) . "startxref\n9\n%%EOF\n";

		$this->assertTrue(PdfEncryptionProbe::mayBeEncrypted($this->file($bytes)));
	}

	/**
	 * Doubt is "maybe": a stream that cannot seek, or a tail with no `startxref`, sends the
	 * caller to the full check rather than past it.
	 */
	public function testWhatCannotBeLookedAtIsAMaybe(): void {
		$long = "%PDF-1.7\n" . str_repeat("% filler\n", 2000) . "%%EOF\n";

		$this->assertTrue(PdfEncryptionProbe::mayBeEncrypted($this->file($long, seekable: false)));
		$this->assertTrue(PdfEncryptionProbe::mayBeEncrypted($this->file($long)), 'no startxref to follow');
	}
}
