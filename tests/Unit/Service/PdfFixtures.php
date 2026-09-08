<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Tcpdf;
use OCA\FilesWatermark\Service\PdfFontPath;

/**
 * Builds and inspects PDFs with tc-lib-pdf, for tests that need a source document or
 * want to read a rendered one back.
 *
 * Shared because all three PDF suites were doing this with FPDI + TCPDF, which the
 * migration removed - and because two details are easy to get wrong in every copy.
 * Sizes are in **points** throughout, matching the renderer, and every document
 * declares the temp directory in `allowedPaths`: tc-lib-pdf refuses local reads
 * outside an allowlist, and supplying one replaces the defaults rather than extending
 * them, so the font directory has to be named too.
 */
trait PdfFixtures {

	/** A4 and A5 in points, for fixtures that care about page geometry. */
	private const A4_WIDTH = 595.276;
	private const A4_HEIGHT = 841.89;
	private const A5_WIDTH = 419.528;
	private const A5_HEIGHT = 595.276;

	/**
	 * Write a PDF with one page per entry in `$pages`.
	 *
	 * Each entry may set `text`, `width` and `height`; sizes default to A4 portrait.
	 * Orientation is derived, so a landscape page is simply one whose width exceeds
	 * its height.
	 *
	 * @param list<array{text?: string, width?: float, height?: float}> $pages
	 */
	private function writePdf(string $path, array $pages): void {
		$pdf = $this->newPdfDocument();
		$font = $pdf->font->insert($pdf->pon, 'helvetica', '', 12);

		foreach ($pages as $page) {
			$width = $page['width'] ?? self::A4_WIDTH;
			$height = $page['height'] ?? self::A4_HEIGHT;

			$pdf->addPage([
				'format' => '',
				'width' => $width,
				'height' => $height,
				'orientation' => $width > $height ? 'L' : 'P',
			]);

			if (($page['text'] ?? '') !== '') {
				$pdf->page->addContent($font['out']);
				$pdf->page->addContent($pdf->getTextCell(
					$page['text'],
					20,
					20,
					$width - 40,
					20,
					drawcell: false,
				));
			}
		}

		$raw = $pdf->getOutPDFString();
		file_put_contents($path, $raw);
	}

	/** Pages in `$path`, as the renderer's own parser counts them. */
	private function readPageCount(string $path): int {
		$pdf = $this->newPdfDocument();
		return $pdf->getSourcePageCount($pdf->setImportSourceFile($path));
	}

	/**
	 * Page sizes in points, keyed by 1-based page number.
	 *
	 * @return array<int, array{width: float, height: float}>
	 */
	private function readPageSizes(string $path): array {
		$pdf = $this->newPdfDocument();
		$sourceId = $pdf->setImportSourceFile($path);
		$count = $pdf->getSourcePageCount($sourceId);

		$sizes = [];
		for ($page = 1; $page <= $count; $page++) {
			$template = $pdf->importPage($sourceId, $page);
			$sizes[$page] = [
				'width' => $pdf->toUnit($template->getWidth()),
				'height' => $pdf->toUnit($template->getHeight()),
			];
		}

		return $sizes;
	}

	/**
	 * Write an encrypted PDF, using the renderer's own encryption rather than an
	 * external tool - this suite spawns no processes either.
	 *
	 * An empty `$userPassword` is the permission-flags-only case: not real protection,
	 * since a reader opens it without prompting, but still an encrypted document as far
	 * as any parser is concerned.
	 *
	 * `$mode` selects the cipher, using the library's own numbering: 0 is RC4-40, 1
	 * RC4-128, 2 AES-128, 3 AES-256 R5 and 4 AES-256 R6. The default is AES-128, which
	 * is what a contemporary office suite emits.
	 *
	 * `$text` is drawn on the page so a test can prove the *content* survived the round
	 * trip and not merely the page count - a decryptor that produced a blank page of the
	 * right size would otherwise pass.
	 */
	private function writeEncryptedPdf(string $path, string $userPassword, int $mode = 2, string $text = ''): void {
		$encrypt = new Encrypt(
			enabled: true,
			file_id: md5($path),
			mode: $mode,
			permissions: ['modify', 'copy'],
			user_pass: $userPassword,
			owner_pass: 'owner-pass',
		);

		$pdf = new Tcpdf(
			'pt',
			fileOptions: ['allowedPaths' => $this->allowedFontAndTempPaths()],
			objEncrypt: $encrypt,
		);
		$pdf->addPage();

		if ($text !== '') {
			$font = $pdf->font->insert($pdf->pon, 'helvetica', '', 12);
			$pdf->page->addContent($font['out']);
			$pdf->page->addContent($pdf->getTextCell($text, 20, 20, self::A4_WIDTH - 40, 20, drawcell: false));
		}

		file_put_contents($path, $pdf->getOutPDFString());
	}

	/**
	 * The cipher modes {@see writeEncryptedPdf} understands, as a data provider.
	 *
	 * All five are exercised wherever encryption is under test, because the decryptor
	 * takes a different path through key derivation for each: RC4 against AES, a
	 * per-object key against a document-wide one, and R5's plain SHA-256 against R6's
	 * iterated hash. A test that only covered AES-128 would leave four of them unproven.
	 *
	 * The two RC4 modes make `Encrypt` emit an `E_USER_DEPRECATED`, which is correct and
	 * is not about this app: writing RC4 is deprecated because it is broken, while
	 * *reading* it is exactly what a watermarker meets in a decade-old scanned file. The
	 * deprecation comes from the fixture writer, never from the decryptor.
	 *
	 * @return array<string, array{int}>
	 */
	public static function encryptionModeProvider(): array {
		return [
			'RC4-40' => [0],
			'RC4-128' => [1],
			'AES-128' => [2],
			'AES-256 R5' => [3],
			'AES-256 R6' => [4],
		];
	}

	private function newPdfDocument(): Tcpdf {
		return new Tcpdf('pt', fileOptions: ['allowedPaths' => $this->allowedFontAndTempPaths()]);
	}

	/** @return list<string> */
	private function allowedFontAndTempPaths(): array {
		$paths = [PdfFontPath::directory(), sys_get_temp_dir()];
		$real = realpath(sys_get_temp_dir());
		if ($real !== false) {
			$paths[] = $real;
		}

		return $paths;
	}
}
