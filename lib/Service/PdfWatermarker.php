<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Com\Tecnick\Pdf\Import\ImportUnsupportedFeatureException;
use Com\Tecnick\Pdf\Tcpdf;
use OCA\FilesWatermark\Db\WatermarkConfig;

/**
 * Draws the watermark over every page of a PDF, keeping the original pages as real
 * content rather than pictures of themselves — the text layer survives, unlike
 * {@see PdfFlattener}.
 *
 * Renders with tc-lib-pdf, the successor to TCPDF by the same author. It replaced
 * FPDI + TCPDF because its parser reads PDF 1.5+ documents whose cross-reference
 * table is a compressed stream, which the free FPDI parser refuses and which most
 * modern producers emit. {@see PdfNormalizer} is still the fallback for what
 * tc-lib-pdf will not open — anything encrypted, including the empty-password
 * permission-flag case.
 *
 * Two things about this library are unlike TCPDF and easy to get wrong:
 *
 * - **Drawing primitives return content-stream strings**; they do not mutate the
 *   document. Nothing appears until the string reaches `page->addContent()`, so
 *   every overlay here is assembled and then handed over in one piece.
 * - **Font metrics are not shipped by Composer.** `resources/fonts` supplies them
 *   via {@see PdfFontPath}; without it every text call throws.
 */
class PdfWatermarker {

	/** Both the tc-lib-pdf and TCPDF metrics in `resources/fonts` are Helvetica. */
	private const FONT_FAMILY = 'helvetica';
	private const FONT_STYLE = 'B';

	public function __construct(
		private PdfNormalizer $normalizer,
	) {
		// Before any font call, and before TCPDF is constructed anywhere else in the
		// request — see PdfFontPath for why this is a global constant.
		PdfFontPath::register();
	}

	public function apply(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders): void {
		$normalized = null;
		try {
			// Points, so user units and PDF units coincide and the page geometry read
			// off the imported template needs no conversion.
			$pdf = new Tcpdf('pt', fileOptions: ['allowedPaths' => $this->allowedPaths($sourcePath, $config)]);
			[$sourceId, $pageCount, $normalized] = $this->openSource($pdf, $sourcePath);

			for ($page = 1; $page <= $pageCount; $page++) {
				// Adds a page matching the source page's size and places the imported
				// page on it, which is FPDI's importPage + AddPage + useTemplate in one
				// call. Mixed-size and landscape documents survive as a result.
				$template = $pdf->addPageFromImport($sourceId, $page);
				$width = $pdf->toUnit($template->getWidth());
				$height = $pdf->toUnit($template->getHeight());

				if (in_array($config->getType(), ['text', 'combined'], true)) {
					$this->applyTextOverlay($pdf, $config, $placeholders, $width, $height);
				}

				if (in_array($config->getType(), ['image', 'combined'], true) && $config->getImagePath()) {
					$this->applyImageOverlay($pdf, $config, $width, $height);
				}
			}

			$this->write($pdf, $destPath);
		} finally {
			// The rewritten copy is scratch: everything needed has been read by the time
			// the output is written, and on the failure paths it is worthless. Either
			// way it is a plaintext copy of user content and must not outlive the call.
			if ($normalized !== null && is_file($normalized)) {
				unlink($normalized);
			}
		}
	}

	/**
	 * Directories tc-lib-pdf is permitted to read from.
	 *
	 * The library refuses local reads outside an allowlist, which is a sensible default
	 * for a renderer that also fetches remote assets — but everything this app feeds it
	 * is a temp copy, so without this the source PDF and the logo are both rejected
	 * ("Unable to read image file"). Kept to the specific directories in play rather
	 * than opened up wholesale.
	 *
	 * Supplying this **replaces** the library's defaults rather than adding to them,
	 * which is why the font directory has to be named here too — omit it and the
	 * metrics that were loading a moment ago start failing instead.
	 *
	 * @return list<string>
	 */
	private function allowedPaths(string $sourcePath, WatermarkConfig $config): array {
		$paths = [PdfFontPath::directory(), sys_get_temp_dir(), dirname($sourcePath)];

		$imagePath = $config->getImagePath();
		if ($imagePath && file_exists($imagePath)) {
			$paths[] = dirname($imagePath);
		}

		// Both the literal and the resolved form of each directory. On macOS the temp
		// dir is `/var/folders/...`, a symlink to `/private/var/folders/...`; listing
		// only the resolved path leaves the unresolved one the caller actually passes
		// looking like an unauthorised location.
		$resolved = [];
		foreach ($paths as $path) {
			$resolved[] = $path;
			$real = realpath($path);
			if ($real !== false) {
				$resolved[] = $real;
			}
		}

		return array_values(array_unique(array_filter($resolved)));
	}

	/**
	 * Register `$sourcePath` for import, falling back to a normalized rewrite of it.
	 *
	 * The direct read is tried first, so documents that already work are never rewritten
	 * and cost nothing. The rewrite is then aimed at exactly one failure: encryption.
	 * tc-lib-pdf refuses every encrypted document and says so with its own exception
	 * type, and `qpdf --decrypt` recovers the empty-password ones — files locked purely
	 * to set permission flags, which a reader opens without ever prompting.
	 *
	 * @return array{0: string, 1: int, 2: string|null} source id, page count, and the
	 *                                                  temp rewrite to delete, if one was made
	 */
	private function openSource(Tcpdf $pdf, string $sourcePath): array {
		try {
			$sourceId = $pdf->setImportSourceFile($sourcePath);
			return [$sourceId, $pdf->getSourcePageCount($sourceId), null];
		} catch (\Exception $cause) {
			// Encryption only. tc-lib-pdf raises this distinctly, which FPDI never did,
			// so the rescue can be aimed at the one case qpdf actually fixes instead of
			// being tried against every parse failure. A corrupt or truncated file now
			// fails immediately rather than paying for a rewrite that cannot help it.
			if (!$cause instanceof ImportUnsupportedFeatureException) {
				throw $this->unreadable($cause);
			}

			// No rewriter on this host: behave exactly as if it did not exist, keeping
			// the parser's own exception as the cause so callers see why the file was
			// refused rather than a complaint about a missing binary.
			if (!$this->normalizer->isAvailable()) {
				throw $this->unreadable($cause);
			}

			$normalized = $this->normalizeSource($sourcePath, $cause);

			try {
				$sourceId = $pdf->setImportSourceFile($normalized);
				return [$sourceId, $pdf->getSourcePageCount($sourceId), $normalized];
			} catch (\Exception $retry) {
				unlink($normalized);
				// The rewrite parsed for qpdf but still not for the renderer, so the
				// second failure is the informative one.
				throw $this->unreadable($retry);
			}
		}
	}

	/** @throws \RuntimeException if the rewrite fails, chaining the original parse error */
	private function normalizeSource(string $sourcePath, \Exception $cause): string {
		$normalized = tempnam(sys_get_temp_dir(), 'wm_norm_');
		if ($normalized === false) {
			throw $this->unreadable($cause);
		}

		try {
			$this->normalizer->normalize($sourcePath, $normalized);
		} catch (\RuntimeException) {
			// qpdf could not read it either — a real password, or damage past repair.
			// The original parse error stays the cause: it describes the document,
			// while this one only says the rescue attempt failed.
			if (is_file($normalized)) {
				unlink($normalized);
			}
			throw $this->unreadable($cause);
		}

		return $normalized;
	}

	private function applyTextOverlay(
		Tcpdf $pdf,
		WatermarkConfig $config,
		array $placeholders,
		float $width,
		float $height,
	): void {
		$text = $this->resolvePlaceholders($config->getTextTemplate() ?? '{username} {date}', $placeholders);
		$fontSize = (float)$config->getFontSize();

		// The font has to be active on this page before anything is measured, or the
		// widths come from whatever was current instead.
		try {
			$font = $pdf->font->insert($pdf->pon, self::FONT_FAMILY, self::FONT_STYLE, $fontSize);
		} catch (\Exception $e) {
			// Overwhelmingly the cause is K_PATH_FONTS having been claimed by another
			// app before this one loaded, which surfaces as a bare "unable to read
			// file: helveticab.json" and sends the reader hunting for a missing file
			// that is in fact right there. Say so instead.
			if (!PdfFontPath::isUsingOwnFonts()) {
				throw new \RuntimeException(sprintf(
					'Cannot process PDF: font metrics are unreachable because %s points at "%s" '
					. 'instead of this app\'s "%s" — another app defined it first. %s',
					PdfFontPath::CONSTANT,
					defined(PdfFontPath::CONSTANT) ? (string)constant(PdfFontPath::CONSTANT) : '(undefined)',
					PdfFontPath::directory(),
					$e->getMessage(),
				), 0, $e);
			}
			throw $e;
		}
		$pdf->page->addContent($font['out']);

		$textWidth = max(1.0, $this->measure($pdf, $text));
		$lineHeight = $fontSize * 1.2;
		$angle = $config->getRotation();

		$content = $pdf->color->getPdfColor($config->getColor())
			. $pdf->graph->getAlpha(round($config->getOpacity() / 100, 2));

		foreach (self::tilePositions($width, $height, $textWidth, $lineHeight, $angle, $config->getFontSize()) as [$cx, $cy]) {
			// Rotate about the tile's own centre, then draw the cell around that same
			// point. tc-lib-pdf takes the pivot in user coordinates and does the flip
			// into PDF space itself, so no manual y inversion belongs here.
			$content .= $pdf->graph->getStartTransform();
			$content .= $pdf->graph->getRotation($angle, $cx, $cy);
			$content .= $pdf->getTextCell(
				$text,
				$cx - $textWidth / 2,
				$cy - $lineHeight / 2,
				$textWidth,
				$lineHeight,
				drawcell: false,
			);
			$content .= $pdf->graph->getStopTransform();
		}

		$content .= $pdf->graph->getAlpha(1.0);
		$pdf->page->addContent($content);
	}

	/**
	 * Width of `$text` in the current font.
	 *
	 * There is no public string-measuring call, so this draws the cell and throws the
	 * content away, keeping only the bounding box it recorded. The returned string is
	 * deliberately discarded — it is never added to a page, so nothing is rendered.
	 */
	private function measure(Tcpdf $pdf, string $text): float {
		$pdf->getTextCell($text, 0, 0, 0, 0, drawcell: false);
		return (float)($pdf->getLastTextBBox()['w'] ?? 0.0);
	}

	/**
	 * Centres of the watermark tiles needed to cover one page, in page
	 * coordinates (origin top-left, y downwards). Centres outside the page are
	 * expected and required — they are what covers the edges and corners.
	 *
	 * The lattice is built in the text's *own* rotated frame rather than as a grid
	 * of rows and columns: spacing runs `textWidth + gap` along the direction the
	 * text reads and `lineHeight + gap` across it. That keeps neighbouring tiles
	 * clear of each other at any angle and puts the gap where it is meaningful —
	 * between adjacent lines of text. Stepping a row/column grid by the text's
	 * unrotated width and height, as this did before, instead spaced tiles by a
	 * bounding box that inflates with rotation, so the density of the pattern
	 * depended on the angle the user happened to pick.
	 *
	 * Pure geometry, and deliberately untouched by the move off TCPDF: it is the
	 * regression test for the illegible-watermark bug, so if its assertions start
	 * failing the fault is in the caller above, not here.
	 *
	 * @return list<array{float, float}> `[x, y]` centre of each tile
	 */
	public static function tilePositions(
		float $pageWidth,
		float $pageHeight,
		float $textWidth,
		float $lineHeight,
		int $rotation,
		int $fontSize,
	): array {
		// Breathing room between repetitions, scaled to the type size so the
		// density looks the same at every font size.
		$gap = $fontSize * 2;
		$stepAlong = $textWidth + $gap;
		$stepAcross = $lineHeight + $gap;

		// A positive-angle watermark reads up and to the right on a y-downwards page.
		// `$across` is that direction turned 90°; the two are orthonormal, which is
		// what makes the projections below a change of basis.
		$rad = deg2rad((float)$rotation);
		$along = [cos($rad), -sin($rad)];
		$across = [sin($rad), cos($rad)];

		// How far the page extends along each axis of that frame, measured by
		// projecting its corners, so the lattice is only as large as it needs to be.
		$alongOffsets = [];
		$acrossOffsets = [];
		foreach ([[0.0, 0.0], [$pageWidth, 0.0], [0.0, $pageHeight], [$pageWidth, $pageHeight]] as [$x, $y]) {
			$alongOffsets[] = $x * $along[0] + $y * $along[1];
			$acrossOffsets[] = $x * $across[0] + $y * $across[1];
		}

		$positions = [];
		$firstAlong = (int)floor(min($alongOffsets) / $stepAlong);
		$lastAlong = (int)ceil(max($alongOffsets) / $stepAlong);
		$firstAcross = (int)floor(min($acrossOffsets) / $stepAcross);
		$lastAcross = (int)ceil(max($acrossOffsets) / $stepAcross);

		for ($i = $firstAlong; $i <= $lastAlong; $i++) {
			for ($j = $firstAcross; $j <= $lastAcross; $j++) {
				$u = $i * $stepAlong;
				$v = $j * $stepAcross;
				$positions[] = [
					$u * $along[0] + $v * $across[0],
					$u * $along[1] + $v * $across[1],
				];
			}
		}

		return $positions;
	}

	private function applyImageOverlay(Tcpdf $pdf, WatermarkConfig $config, float $width, float $height): void {
		$imagePath = $config->getImagePath();
		if (!$imagePath || !file_exists($imagePath)) {
			return;
		}

		// Scale to 30% of the page width. getImageDimensionsByKey derives the other
		// side from the intrinsic pixel size, so the logo's aspect ratio is preserved
		// without measuring the file here.
		$imageId = $pdf->image->add($imagePath);
		// The library takes the target width as an int; sub-point precision on a logo
		// placement is not worth carrying, and rounding keeps the aspect ratio honest.
		$dimensions = $pdf->image->getImageDimensionsByKey(
			$pdf->image->getKey($imagePath),
			(int)round($width * 0.3),
		);
		$imgW = (float)$dimensions['width'];
		$imgH = (float)$dimensions['height'];

		$content = $pdf->graph->getAlpha(round($config->getOpacity() / 100, 2));
		$content .= $pdf->image->getSetImage(
			$imageId,
			($width - $imgW) / 2,
			($height - $imgH) / 2,
			$imgW,
			$imgH,
			$height,
		);
		$content .= $pdf->graph->getAlpha(1.0);
		$pdf->page->addContent($content);
	}

	/**
	 * tc-lib-pdf builds the document in memory and hands back a string, where TCPDF
	 * wrote the file itself. Failures are surfaced rather than leaving the caller with
	 * a path that does not exist or holds a truncated PDF.
	 */
	private function write(Tcpdf $pdf, string $destPath): void {
		$raw = $pdf->getOutPDFString();
		if (file_put_contents($destPath, $raw) !== strlen($raw)) {
			if (is_file($destPath)) {
				unlink($destPath);
			}
			throw new \RuntimeException('Cannot process PDF: the watermarked file could not be written.');
		}
	}

	private function unreadable(\Exception $cause): \RuntimeException {
		return new \RuntimeException(
			'Cannot process PDF: the file may be encrypted, password-protected, or use unsupported compression. '
			. $cause->getMessage(),
			0,
			$cause,
		);
	}

	private function resolvePlaceholders(string $template, array $placeholders): string {
		$search = array_map(fn ($k) => '{' . $k . '}', array_keys($placeholders));
		$replace = array_values($placeholders);
		return str_replace($search, $replace, $template);
	}
}
