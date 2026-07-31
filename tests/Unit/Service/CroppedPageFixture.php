<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

/**
 * Builds a one-page PDF whose visible area is a `/CropBox` **offset from the origin**,
 * with all of its content inside that box — the shape that made watermarked files come
 * out blank.
 *
 * Hand-built byte by byte for the same reason {@see CompressedXrefFixture} is: the
 * renderer writes what it writes, and it will not produce a source page with a cropped,
 * offset visible area on request. Generating this through `writePdf()` would yield a
 * fixture that no longer reproduces the case.
 *
 * The content is a single image, because that is what the report came from — a photo run
 * through a print-to-PDF driver — and because an image is trivially locatable in the
 * output by its bytes.
 */
trait CroppedPageFixture {

	/** The pixel run embedded in the fixture's image, for locating it in the output. */
	private function croppedPagePixels(): string {
		return str_repeat("\xAB\xCD\xEF", 16);
	}

	/**
	 * @param array{float, float, float, float} $cropBox visible area, in points
	 * @param int $rotate a `/Rotate` value; 0 omits the entry entirely
	 */
	private function writeCroppedPagePdf(string $path, array $cropBox, int $rotate = 0): void {
		[$x0, $y0, $x1, $y1] = $cropBox;
		$image = gzcompress($this->croppedPagePixels());

		// The image fills exactly the visible box, in the *source's* coordinates — so if
		// the importer forgets the box origin, the content lands off the page.
		$draw = sprintf("q\n%F 0 0 %F %F %F cm\n/Im0 Do\nQ\n", $x1 - $x0, $y1 - $y0, $x0, $y0);

		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /CropBox [%F %F %F %F ] %s'
				. '/Resources << /XObject << /Im0 5 0 R >> /ProcSet [/PDF /ImageC] >> /Contents 4 0 R >>',
				$x0,
				$y0,
				$x1,
				$y1,
				$rotate === 0 ? '' : "/Rotate $rotate ",
			),
			4 => '<< /Length ' . strlen($draw) . " >>\nstream\n" . $draw . 'endstream',
			5 => '<< /Type /XObject /Subtype /Image /Width 4 /Height 4 /ColorSpace /DeviceRGB '
				. '/BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($image) . " >>\nstream\n" . $image . "\nendstream",
		];

		$pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $num => $body) {
			$offsets[$num] = strlen($pdf);
			$pdf .= "$num 0 obj\n$body\nendobj\n";
		}

		// Real offsets: the parser seeks by them, so a fixture with bogus ones would fail
		// for a reason that has nothing to do with the crop.
		$xrefOffset = strlen($pdf);
		$size = count($objects) + 1;
		$pdf .= "xref\n0 $size\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}
		$pdf .= "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF\n";

		file_put_contents($path, $pdf);
	}

	/**
	 * What fraction of the imported page ends up inside the output page, 0.0–1.0.
	 *
	 * Every byte of a lost page is still in the file — it is drawn somewhere nothing can
	 * see it — so neither the file size nor a search for the image data can tell a good
	 * render from a blank one. This composes the two transforms that decide where the
	 * content actually lands: the imported form's own `/Matrix`, which carries the page
	 * rotation, and the `cm` that places the form on the page.
	 */
	private function visibleFractionOfImportedPage(string $path): float {
		$pdf = (string)file_get_contents($path);

		$page = $this->firstArray($pdf, '/MediaBox');
		$box = $this->firstArray($pdf, '/BBox');
		$matrix = $this->firstArray($pdf, '/Matrix');

		$placement = null;
		foreach ($this->inflatedStreams($pdf) as $stream) {
			if (preg_match('/([-\d.]+)\s+0\s+0\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+cm\s*\/IMP\d+\s+Do/', $stream, $m)) {
				$placement = [(float)$m[1], 0.0, 0.0, (float)$m[2], (float)$m[3], (float)$m[4]];
				break;
			}
		}
		if ($placement === null) {
			return 0.0;
		}

		$combined = $this->concatMatrix($matrix, $placement);

		$xs = [];
		$ys = [];
		foreach ([[$box[0], $box[1]], [$box[2], $box[1]], [$box[0], $box[3]], [$box[2], $box[3]]] as [$x, $y]) {
			$xs[] = $combined[0] * $x + $combined[2] * $y + $combined[4];
			$ys[] = $combined[1] * $x + $combined[3] * $y + $combined[5];
		}

		$area = (max($xs) - min($xs)) * (max($ys) - min($ys));
		if ($area <= 0.0) {
			return 0.0;
		}

		$visibleW = max(0.0, min($page[2], max($xs)) - max($page[0], min($xs)));
		$visibleH = max(0.0, min($page[3], max($ys)) - max($page[1], min($ys)));

		return ($visibleW * $visibleH) / $area;
	}

	/** @return array<int, float> */
	private function firstArray(string $pdf, string $key): array {
		if (!preg_match('/' . preg_quote($key, '/') . '\s*\[([^\]]*)\]/', $pdf, $m)) {
			return [0.0, 0.0, 0.0, 0.0];
		}
		return array_map('floatval', preg_split('/\s+/', trim($m[1])) ?: []);
	}

	/**
	 * `$a` then `$b`, both as PDF's `[a b c d e f]`.
	 *
	 * @param array<int, float> $a
	 * @param array<int, float> $b
	 * @return array<int, float>
	 */
	private function concatMatrix(array $a, array $b): array {
		return [
			$a[0] * $b[0] + $a[1] * $b[2],
			$a[0] * $b[1] + $a[1] * $b[3],
			$a[2] * $b[0] + $a[3] * $b[2],
			$a[2] * $b[1] + $a[3] * $b[3],
			$a[4] * $b[0] + $a[5] * $b[2] + $b[4],
			$a[4] * $b[1] + $a[5] * $b[3] + $b[5],
		];
	}

	/** @return list<string> every stream in the file, inflated where it is compressed */
	private function inflatedStreams(string $pdf): array {
		$streams = [];
		$offset = 0;
		while (($start = strpos($pdf, 'stream', $offset)) !== false) {
			$data = $start + 6;
			if (substr($pdf, $data, 2) === "\r\n") {
				$data += 2;
			} elseif (in_array($pdf[$data] ?? '', ["\n", "\r"], true)) {
				$data += 1;
			}
			$end = strpos($pdf, 'endstream', $data);
			if ($end === false) {
				break;
			}
			$raw = substr($pdf, $data, $end - $data);
			$inflated = @gzuncompress($raw);
			if ($inflated === false) {
				$inflated = @gzinflate($raw);
			}
			$streams[] = $inflated === false ? $raw : $inflated;
			$offset = $end + 9;
		}
		return $streams;
	}
}
