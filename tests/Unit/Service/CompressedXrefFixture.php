<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

/**
 * Builds a PDF 1.5 fixture whose cross-reference table is a compressed stream.
 *
 * Shared by {@see PdfWatermarkerTest} and {@see PdfNormalizerTest} because the two
 * assert opposite halves of one story - refused without `qpdf`, watermarked with
 * it - and a fixture that drifted between them would let both pass while the
 * feature was broken.
 */
trait CompressedXrefFixture {

	/**
	 * A minimal but well-formed PDF 1.5 whose cross-reference table is a
	 * compressed stream object (`/Type /XRef`, `/Filter /FlateDecode`) instead of
	 * a classic `xref` table.
	 *
	 * Built byte by byte because **TCPDF cannot produce one**: it writes a classic
	 * xref table whatever `setPDFVersion()` and `SetCompression()` are set to, and
	 * FPDI parses its output happily. Anyone tempted to simplify this into
	 * `createSourcePdf()` with `setPDFVersion('1.5')` will get a fixture that no
	 * longer reproduces the bug and a test that passes for the wrong reason.
	 *
	 * The offsets have to be real. FPDI seeks to `startxref`, reads the object it
	 * finds there and checks `/Type`, so a fixture with bogus offsets fails with
	 * INVALID_DATA and would prove nothing about compression support.
	 */
	private function buildCompressedXrefPdf(): string {
		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
				. '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
		];
		$content = "BT /F1 24 Tf 72 700 Td (Compressed xref fixture) Tj ET\n";
		$objects[4] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
		$objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

		// The binary comment on line 2 is what marks the file as containing binary
		// data, as a real producer would emit.
		$pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $num => $body) {
			$offsets[$num] = strlen($pdf);
			$pdf .= "$num 0 obj\n$body\nendobj\n";
		}

		// The xref stream is itself an indirect object, so it has to record its own
		// offset - which is only known once everything before it has been written.
		$xrefNum = count($objects) + 1;
		$xrefOffset = strlen($pdf);

		// /W [1 4 2]: one type byte, a 4-byte offset, a 2-byte generation number.
		$entries = pack('CNn', 0, 0, 65535);
		foreach ($offsets as $offset) {
			$entries .= pack('CNn', 1, $offset, 0);
		}
		$entries .= pack('CNn', 1, $xrefOffset, 0);

		$stream = gzcompress($entries);
		$dict = '<< /Type /XRef /Size ' . ($xrefNum + 1) . ' /W [1 4 2] /Root 1 0 R '
			. '/Filter /FlateDecode /Length ' . strlen($stream) . ' >>';
		$pdf .= "$xrefNum 0 obj\n$dict\nstream\n" . $stream . "\nendstream\nendobj\n";
		$pdf .= "startxref\n$xrefOffset\n%%EOF\n";

		return $pdf;
	}
}
