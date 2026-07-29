<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\PdfNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see PdfNormalizer}.
 *
 * The rewrite cases drive the real `qpdf` and skip without it, in the same shape
 * as {@see PdfFlattenerTest} — a host with no binary is a supported configuration,
 * and mocking the binary away would assert nothing worth asserting: the claim
 * under test is what qpdf *actually* produces, and that the renderer can then read it.
 */
class PdfNormalizerTest extends TestCase {
	use PdfFixtures;

	private PdfNormalizer $normalizer;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$this->normalizer = new PdfNormalizer($this->createMock(LoggerInterface::class));
		$this->tmpDir = sys_get_temp_dir() . '/wm_norm_test_' . bin2hex(random_bytes(6));
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
	 * **The reason this class still exists.** Permission-only encryption — an empty
	 * user password with the flags set — is common on documents that circulate freely,
	 * and the renderer refuses it just as hard as a real password. `--decrypt` gets it
	 * back, and this is now the only gap the normalizer closes that nothing else does.
	 */
	public function testEmptyPasswordEncryptionIsRemoved(): void {
		$this->requireBinary();

		$source = $this->tmpDir . '/restricted.pdf';
		$this->runQpdf(sprintf(
			'--encrypt "" ownerpw 256 -- %s %s',
			escapeshellarg($this->createSourcePdf()),
			escapeshellarg($source),
		));

		$dest = $this->tmpDir . '/normalized.pdf';
		$this->normalizer->normalize($source, $dest);

		$this->assertSame(1, $this->readPageCount($dest));
	}

	/**
	 * A real password is where the pre-pass legitimately stops. It must fail rather
	 * than write something — {@see \OCA\FilesWatermark\Service\PdfWatermarker} treats
	 * the destination as valid the moment this returns.
	 */
	public function testPasswordProtectedPdfFailsAndWritesNothing(): void {
		$this->requireBinary();

		$source = $this->tmpDir . '/locked.pdf';
		$this->runQpdf(sprintf(
			'--encrypt userpw ownerpw 256 -- %s %s',
			escapeshellarg($this->createSourcePdf()),
			escapeshellarg($source),
		));

		$dest = $this->tmpDir . '/normalized.pdf';

		try {
			$this->normalizer->normalize($source, $dest);
			$this->fail('expected a password-protected PDF to be refused');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Cannot normalize PDF', $e->getMessage());
		}

		$this->assertFileDoesNotExist($dest, 'a failed rewrite must not leave a partial file behind');
	}

	public function testGarbageInputFailsAndWritesNothing(): void {
		$this->requireBinary();

		$source = $this->tmpDir . '/not-a.pdf';
		file_put_contents($source, 'this is not a PDF at all');
		$dest = $this->tmpDir . '/normalized.pdf';

		try {
			$this->normalizer->normalize($source, $dest);
			$this->fail('expected unparseable bytes to be refused');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Cannot normalize PDF', $e->getMessage());
		}

		$this->assertFileDoesNotExist($dest);
	}

	public function testMissingSourceFailsBeforeShellingOut(): void {
		$dest = $this->tmpDir . '/normalized.pdf';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Cannot normalize PDF');

		$this->normalizer->normalize($this->tmpDir . '/nope.pdf', $dest);
	}

	/**
	 * `isAvailable()` is what callers consult to decide whether to attempt a rewrite
	 * at all, so it has to agree with what is actually on PATH — in both directions.
	 */
	public function testIsAvailableMatchesThePath(): void {
		$onPath = false;
		foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
			if ($dir !== '' && is_executable(rtrim($dir, '/') . '/' . PdfNormalizer::BINARY)) {
				$onPath = true;
				break;
			}
		}

		$this->assertSame($onPath, $this->normalizer->isAvailable());
		// Memoized, so a second call must not disagree with the first.
		$this->assertSame($onPath, $this->normalizer->isAvailable());
	}

	/**
	 * Without the binary the failure has to be an exception, never a silent
	 * no-op: the caller reads a successful return as "the destination is now a
	 * readable PDF", and would hand the renderer a path that does not exist.
	 */
	public function testNormalizeThrowsWhenTheBinaryIsMissing(): void {
		if ($this->normalizer->isAvailable()) {
			$this->markTestSkipped(PdfNormalizer::BINARY . ' is installed on this host');
		}

		$source = $this->createSourcePdf();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('is not installed');

		$this->normalizer->normalize($source, $this->tmpDir . '/normalized.pdf');
	}

	private function requireBinary(): void {
		if (!$this->normalizer->isAvailable()) {
			$this->markTestSkipped(PdfNormalizer::BINARY . ' is not installed on this host');
		}
	}

	private function runQpdf(string $args): void {
		$output = [];
		$status = 0;
		exec(PdfNormalizer::BINARY . ' ' . $args . ' 2>&1', $output, $status);
		// qpdf exits 3 on warnings, which is still a usable fixture.
		$this->assertContains($status, [0, 3], 'could not build the fixture: ' . implode(' ', $output));
	}

	/** A plain single-page PDF to build encrypted fixtures from. */
	private function createSourcePdf(): string {
		$path = $this->tmpDir . '/source.pdf';
		$this->writePdf($path, [['text' => 'Page 1']]);
		return $path;
	}

}
