<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Com\Tecnick\Pdf\Import\PageTemplateInterface;
use Com\Tecnick\Pdf\Tcpdf;
use OCA\FilesWatermark\Db\WatermarkConfig;

/**
 * Draws the watermark over every page of a PDF, keeping the original pages as real
 * content. The overlay is a content stream, so the text layer survives: selection,
 * copy, search and screen-reader access all keep working.
 *
 * The trade-off is deliberate and worth knowing. A separate layer can be stripped by
 * a determined user with ordinary tools. A `PdfFlattener` once rebuilt each page as a
 * bitmap to prevent that, and was removed along with every other external-binary
 * dependency — rasterising needs a PDF interpreter, which is not something to bundle.
 * This app deters and traces; it does not prevent.
 *
 * Renders with tc-lib-pdf, the successor to TCPDF by the same author. It replaced
 * FPDI + TCPDF because its parser reads PDF 1.5+ documents whose cross-reference
 * table is a compressed stream, which the free FPDI parser refuses and which most
 * modern producers emit.
 *
 * Pure PHP, and deliberately so: this app spawns no processes. Documents the parser
 * will not open — anything encrypted, including files locked with an empty password
 * purely to set permission flags — are refused here and skipped by the caller. An
 * external rewriter (`qpdf --decrypt`) used to rescue that case and was removed with
 * the rest of the binary dependencies.
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

	/** Matches the metrics committed in `resources/fonts`. */
	private const FONT_FAMILY = 'helvetica';
	private const FONT_STYLE = 'B';

	public function __construct() {
		// Before any font call. Also claimed at app bootstrap; see PdfFontPath for why
		// this is a global constant rather than an injected path.
		PdfFontPath::register();
	}

	public function apply(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders): void {
		// Points, so user units and PDF units coincide and the page geometry read
		// off the imported template needs no conversion.
		$pdf = new Tcpdf('pt', fileOptions: ['allowedPaths' => $this->allowedPaths($sourcePath, $config)]);
		[$sourceId, $pageCount] = $this->openSource($pdf, $sourcePath);

		for ($page = 1; $page <= $pageCount; $page++) {
			$template = $this->addImportedPage($pdf, $sourceId, $page);
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
	}

	/**
	 * Add a page matching the source page's size and place the imported page on it.
	 *
	 * This is the library's own `addPageFromImport()` with one correction, and the
	 * correction is the whole reason it is unrolled here.
	 *
	 * **The imported form keeps the source page's coordinate system.** Its `/BBox` is the
	 * source's visible box *in source coordinates* — `[72 72 540 720]` for a page cropped
	 * 72pt on each side — while the new page is created at the box's *size*, 468×648, with
	 * its origin at (0, 0). `addPageFromImport()` then places the form at (0, 0) with an
	 * identity matrix, so content that lives at x≥72 in the source is drawn at x≥72 on a
	 * page only 468 wide. The content is pushed off the top-right corner.
	 *
	 * How bad depends entirely on the crop. At a 72pt origin a quarter of the page is lost;
	 * measured on a `/CropBox [300 300 612 792]` fixture, **98% of the content ends up off
	 * the page** — which is indistinguishable from "the watermark blanked my file", because
	 * every byte is still in there, just drawn where nothing can see it.
	 *
	 * Placing the form at `(-x0, +y0)` cancels the offset. The signs are not symmetric
	 * because {@see \Com\Tecnick\Pdf\Tcpdf::useImportedPage()} takes y from the *top* and
	 * flips it internally: it emits `yPt = pageHeight - y - formHeight`, so with the page
	 * and the form the same height, a positive `y` moves the form down by exactly `y`.
	 *
	 * A page whose box starts at the origin — the overwhelming majority, and every fixture
	 * this app had before — offsets by zero and is unaffected.
	 */
	private function addImportedPage(Tcpdf $pdf, string $sourceId, int $page): PageTemplateInterface {
		$template = $pdf->importPage($sourceId, $page);
		$width = $pdf->toUnit($template->getWidth());
		$height = $pdf->toUnit($template->getHeight());

		$pdf->addPage([
			'format' => '',
			'width' => $width,
			'height' => $height,
			'orientation' => $width > $height ? 'L' : 'P',
		]);

		[$xpos, $ypos] = $this->importOffset($template);

		$pdf->useImportedPage(
			$template,
			$pdf->toUnit($xpos),
			$pdf->toUnit($ypos),
			$width,
			$height,
			['keepAspectRatio' => false],
		);

		return $template;
	}

	/**
	 * Where to place the imported form so its visible box starts at the page origin.
	 *
	 * `getMediaBox()` reports the box the import was sized from, in points, as
	 * `[x0, y0, x1, y1]` — despite the name that is the **CropBox** when the source has
	 * one, and a non-zero `(x0, y0)` is what pushes the content off the page.
	 *
	 * The offset has to be applied in the *rotated* frame, because the library's own form
	 * matrix is built for a box anchored at the origin — it uses the box's width and
	 * height, never its coordinates. For a page cropped to `[300 300 612 792]` it emits
	 * `[0 -1 1 0 0 312]` at 90°, mapping `(x, y)` to `(y, 312 - x)`; translating by
	 * `(-x0, -y0)` there moves the content further off the page rather than back onto it.
	 * Rotating the correction with the page is what makes all four orientations land.
	 *
	 * Returned as `useImportedPage()` arguments, not as a raw translation: that method
	 * measures y from the *top* and emits `pageHeight - y - formHeight`, so with the page
	 * and form the same height a positive y moves the form **down**. Hence the sign flips
	 * on the second component.
	 *
	 * @return array{float, float} the `$xpos, $ypos` to place the form at
	 */
	private function importOffset(PageTemplateInterface $template): array {
		[$x0, $y0] = $template->getMediaBox();

		// PDF requires /Rotate to be a multiple of 90 but permits negatives.
		$rotation = ((($template->getRotation() % 360) + 360) % 360);

		return match ($rotation) {
			90 => [-$y0, -$x0],
			180 => [$x0, -$y0],
			270 => [$y0, $x0],
			// 0, and anything a malformed /Rotate produces: unrotated placement, which is
			// also what this did before any of it was corrected.
			default => [-$x0, $y0],
		};
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
	 * Register `$sourcePath` for import and return its id and page count.
	 *
	 * Every parse failure is final. There is no rescue pass: the app spawns no
	 * processes, so a document tc-lib-pdf cannot open — encrypted, or damaged beyond
	 * its tolerance — is refused here and the trigger's own policy takes over (skip
	 * plus an audit row for the in-place triggers, deny for `on_share`).
	 *
	 * @return array{0: string, 1: int} source id and page count
	 */
	private function openSource(Tcpdf $pdf, string $sourcePath): array {
		try {
			$sourceId = $pdf->setImportSourceFile($sourcePath);
			return [$sourceId, $pdf->getSourcePageCount($sourceId)];
		} catch (\Exception $cause) {
			throw $this->unreadable($cause);
		}
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
		$lineHeight = $fontSize * TileLattice::LINE_HEIGHT_FACTOR;
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
	 * The geometry itself now lives in {@see TileLattice}, because the image renderer
	 * needed the same lattice and had been making do with a fixed grid that ignored the
	 * text. This stays as the entry point rather than being replaced at every call site:
	 * it is the regression test for the illegible-watermark bug, its 22 assertions are
	 * pinned to this signature, and those assertions passing unchanged across the
	 * extraction is what proves the move was faithful.
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
		return TileLattice::positions($pageWidth, $pageHeight, $textWidth, $lineHeight, $rotation, $fontSize);
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
