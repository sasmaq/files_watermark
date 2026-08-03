<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

/**
 * Builds a PDF whose page declares no `/Resources` and inherits none.
 *
 * That is legal - `/Resources` is required only in the sense that a page's content
 * cannot name what is not there, and a page drawing nothing but paths needs no font,
 * image or graphics state - and it is what tc-lib-pdf's import turns into a Form
 * XObject with a `/Resources` entry that has **no value at all**. See
 * {@see \OCA\FilesWatermark\Service\PdfWatermarker::repairEmptyFormResources()} for
 * what that does to the rest of the dictionary.
 *
 * Hand-built for the same reason {@see CompressedXrefFixture} is: tc-lib-pdf always
 * writes a `/Resources` dictionary on the pages it produces, so no fixture routed
 * through `writePdf()` can reproduce this. Anyone simplifying this into
 * `createSourcePdf()` gets a test that passes without ever reaching the case.
 */
trait ResourcelessPageFixture {

	/**
	 * A one-page PDF drawing a filled rectangle - content that names no resource -
	 * on a page with no `/Resources` key and a `/Pages` node that has none to inherit.
	 *
	 * The content stream is **compressed**, which is what makes the consequence
	 * visible: the missing value shifts every later entry of the form dictionary onto
	 * the wrong key, `/Filter` included, so a reader hands deflate bytes to the
	 * content interpreter and draws nothing.
	 */
	private function buildResourcelessPagePdf(): string {
		$content = gzcompress("0 0 1 rg 72 600 200 100 re f\n");

		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>',
			4 => '<< /Filter /FlateDecode /Length ' . strlen($content) . " >>\nstream\n"
				. $content . "\nendstream",
		];

		$pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $num => $body) {
			$offsets[$num] = strlen($pdf);
			$pdf .= "$num 0 obj\n$body\nendobj\n";
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
}
