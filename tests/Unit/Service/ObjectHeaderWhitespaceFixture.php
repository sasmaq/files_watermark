<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

/**
 * Builds a PDF whose indirect object headers separate their tokens with something
 * other than the single spaces tc-lib-pdf-parser insists on - `3\n0\nobj` rather than
 * `3 0 obj`.
 *
 * PDF 32000-1 §7.2.2 allows any whitespace there, and real producers use it: Google's
 * exporters write every header in a document with line feeds. See
 * {@see \OCA\FilesWatermark\Service\PdfObjectHeaderNormalizer} for what the parser does
 * with them and why the repair has to preserve the file's byte count.
 *
 * Hand-built for the reason {@see ResourcelessPageFixture} is - tc-lib-pdf writes
 * single spaces, so nothing routed through `writePdf()` can produce this - and with the
 * offsets computed during assembly rather than written down, so the cross-reference
 * table stays correct whatever separator the test asks for. A fixture with a stale
 * table would fail for the wrong reason and look like the bug it is meant to prove
 * fixed.
 */
trait ObjectHeaderWhitespaceFixture {

	/**
	 * A one-page PDF whose object headers use `$separator` between the object number,
	 * the generation number and `obj`.
	 *
	 * The content stream is left uncompressed and made to contain the byte sequence
	 * `9 0 obj`, which is not a header and must survive untouched: a repair that
	 * scanned the whole file instead of the offsets in the table would rewrite it and
	 * corrupt the stream while leaving the document superficially intact.
	 */
	private function buildPdfWithHeaderSeparator(string $separator): string {
		// Text drawn inside the page, chosen to look like an object header to any
		// whole-file search for one.
		$content = "BT /F1 12 Tf 72 700 Td (9 0 obj) Tj ET\n0 0 1 rg 72 600 200 100 re f\n";

		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
				. '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
			4 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
			5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		];

		$pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $num => $body) {
			$offsets[$num] = strlen($pdf);
			$pdf .= $num . $separator . '0' . $separator . "obj\n" . $body . "\nendobj\n";
		}

		$count = count($objects);
		$xrefOffset = strlen($pdf);
		$pdf .= 'xref' . "\n" . '0 ' . ($count + 1) . "\n" . "0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}
		$pdf .= 'trailer' . "\n" . '<< /Size ' . ($count + 1) . ' /Root 1 0 R >>' . "\n"
			. "startxref\n$xrefOffset\n%%EOF\n";

		return $pdf;
	}

	/**
	 * Whether the renderer's own parser resolves the document's `/Root`.
	 *
	 * This is the assertion that matters, because the failure being fixed is not an
	 * exception from the parser but a *successful* parse in which the catalogue came
	 * back as the null object - §7.3.10's reading of a reference to an object that is
	 * not there. tc-lib-pdf only turns that into an error later, when it looks for the
	 * page tree and finds nothing to look in.
	 */
	private function rootResolves(string $raw): bool {
		[$xref, $objects] = (new \Com\Tecnick\Pdf\Parser\Parser([]))->parse($raw);
		$root = $xref['trailer']['root'] ?? '';
		$object = $objects[$root] ?? null;

		if (!is_array($object)) {
			return false;
		}

		return !(count($object) === 1 && ($object[0][0] ?? null) === 'null');
	}
}
