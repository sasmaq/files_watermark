<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;

class PdfWatermarker {

	public function __construct(
		private PdfNormalizer $normalizer,
	) {
	}

	public function apply(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders): void {
		$normalized = null;
		try {
			[$pdf, $pageCount, $normalized] = $this->openSource($sourcePath);

			for ($page = 1; $page <= $pageCount; $page++) {
				$tplIdx = $pdf->importPage($page);
				$size = $pdf->getTemplateSize($tplIdx);
				$pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
				$pdf->useTemplate($tplIdx);

				if (in_array($config->getType(), ['text', 'combined'], true)) {
					$this->applyTextOverlay($pdf, $config, $placeholders, $size['width'], $size['height']);
				}

				if (in_array($config->getType(), ['image', 'combined'], true) && $config->getImagePath()) {
					$this->applyImageOverlay($pdf, $config, $size['width'], $size['height']);
				}
			}

			$pdf->Output($destPath, 'F');
		} finally {
			// The rewritten copy is scratch: FPDI has read everything it needs by the
			// time Output() returns, and on the failure paths it is worthless. Either
			// way it is a plaintext copy of user content and must not outlive the call.
			if ($normalized !== null && is_file($normalized)) {
				unlink($normalized);
			}
		}
	}

	/**
	 * Open `$sourcePath` for import, falling back to a normalized rewrite of it.
	 *
	 * The direct read is tried first and the rewrite only happens after FPDI has
	 * genuinely refused the file, so documents that already work are never touched
	 * and cost nothing. What the fallback buys is the compressed-cross-reference
	 * case (most PDF 1.5+ files) and empty-password encryption — see
	 * {@see PdfNormalizer}.
	 *
	 * @return array{0: Fpdi, 1: int, 2: string|null} the reader, its page count, and
	 *                                                the temp rewrite to delete, if one was made
	 */
	private function openSource(string $sourcePath): array {
		$pdf = $this->newDocument();
		try {
			return [$pdf, $pdf->setSourceFile($sourcePath), null];
		} catch (\Exception $cause) {
			// No rewriter on this host: behave exactly as before it existed, keeping
			// the parser's own exception as the cause so callers (and the tests that
			// assert on COMPRESSED_XREF) still see why the file was refused.
			if (!$this->normalizer->isAvailable()) {
				throw $this->unreadable($cause);
			}

			$normalized = $this->normalizeSource($sourcePath, $cause);

			// A fresh document, not the one that just threw: a failed setSourceFile
			// leaves the reader holding a half-parsed source.
			$pdf = $this->newDocument();
			try {
				return [$pdf, $pdf->setSourceFile($normalized), $normalized];
			} catch (\Exception $retry) {
				unlink($normalized);
				// The rewrite parsed as a PDF for qpdf but still not for FPDI, so the
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
		} catch (\RuntimeException $e) {
			// qpdf could not read it either — a real password, or damage past repair.
			// The original parse error stays the cause: it is the one that describes
			// the document, while this one only says the rescue attempt failed.
			if (is_file($normalized)) {
				unlink($normalized);
			}
			throw $this->unreadable($cause);
		}

		return $normalized;
	}

	private function newDocument(): Fpdi {
		$pdf = new Fpdi('P', 'pt');
		$pdf->SetPrintHeader(false);
		$pdf->SetPrintFooter(false);
		// Watermark cells are positioned manually (including beyond the page edge
		// for the tiled overlay); without this TCPDF would insert spurious pages.
		$pdf->SetAutoPageBreak(false);
		return $pdf;
	}

	private function unreadable(\Exception $cause): \RuntimeException {
		return new \RuntimeException(
			'Cannot process PDF: the file may be encrypted, password-protected, or use unsupported compression. '
			. $cause->getMessage(),
			0,
			$cause,
		);
	}

	private function applyTextOverlay(Fpdi $pdf, WatermarkConfig $config, array $placeholders, float $width, float $height): void {
		$text = $this->resolvePlaceholders($config->getTextTemplate() ?? '{username} {date}', $placeholders);
		$color = $this->hexToRgb($config->getColor());
		$alpha = round($config->getOpacity() / 100, 2);

		$fontSize = $config->getFontSize();
		$pdf->SetFont('helvetica', 'B', $fontSize);
		$pdf->SetTextColor($color[0], $color[1], $color[2]);
		$pdf->SetAlpha($alpha);

		// TCPDF's Rotate is counter-clockwise-positive, the opposite of SVG's
		// clockwise-positive `rotate()` used by the settings live preview. The preview
		// tilts by `rotate(-rotation)` (uphill ↗), so to match it visually TCPDF must
		// rotate by +rotation — passing -rotation here tilted the text the other way.
		$angle = $config->getRotation();

		// GetStringWidth measures the string in the current font, so the tile is
		// sized to the text actually being drawn rather than a guess.
		$textWidth = max(1.0, $pdf->GetStringWidth($text));
		$lineHeight = $fontSize * 1.2;

		foreach (self::tilePositions($width, $height, $textWidth, $lineHeight, $angle, $fontSize) as [$cx, $cy]) {
			$pdf->StartTransform();
			// Position with Translate, never SetXY. TCPDF reads a negative
			// SetX/SetY as an offset from the *opposite* page edge — SetXY(-361,
			// -93.6) on A4 lands at (234, 748) — so every tile meant to hang off the
			// top or left edge was teleported into the middle of the page and piled
			// onto the tiles already there. That, not the spacing, is what made real
			// watermarks an illegible smear with bare bands along the top and left
			// margins. Translate is a plain matrix op with no such special case, so
			// SetXY only ever sees (0, 0) and an offset stays an offset.
			$pdf->Translate($cx - $textWidth / 2, $cy - $lineHeight / 2);
			$pdf->Rotate($angle, $textWidth / 2, $lineHeight / 2);
			$pdf->SetXY(0, 0);
			$pdf->Cell($textWidth, $lineHeight, $text, 0, 0, 'C');
			$pdf->StopTransform();
		}

		$pdf->SetAlpha(1);
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

		// TCPDF rotates counter-clockwise, so on a y-downwards page the text of a
		// positive-angle watermark reads up and to the right. `$across` is that
		// direction turned 90°; the two are orthonormal, which is what makes the
		// projections below a change of basis.
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

	private function applyImageOverlay(Fpdi $pdf, WatermarkConfig $config, float $width, float $height): void {
		$imagePath = $config->getImagePath();
		if (!$imagePath || !file_exists($imagePath)) {
			return;
		}

		$alpha = round($config->getOpacity() / 100, 2);
		$pdf->SetAlpha($alpha);

		// Scale to 30% of the page width while preserving the logo's real aspect ratio.
		$imgW = $width * 0.3;
		$dimensions = @getimagesize($imagePath);
		if ($dimensions && $dimensions[0] > 0) {
			$imgH = $imgW * ($dimensions[1] / $dimensions[0]);
		} else {
			$imgH = $imgW * 0.5;
		}
		$x = ($width - $imgW) / 2;
		$y = ($height - $imgH) / 2;
		$pdf->Image($imagePath, $x, $y, $imgW, $imgH, '', '', '', false, 300, '', false, false, 0);

		$pdf->SetAlpha(1);
	}

	private function resolvePlaceholders(string $template, array $placeholders): string {
		$search = array_map(fn ($k) => '{' . $k . '}', array_keys($placeholders));
		$replace = array_values($placeholders);
		return str_replace($search, $replace, $template);
	}

	private function hexToRgb(string $hex): array {
		$hex = ltrim($hex, '#');
		return [
			hexdec(substr($hex, 0, 2)),
			hexdec(substr($hex, 2, 2)),
			hexdec(substr($hex, 4, 2)),
		];
	}
}
