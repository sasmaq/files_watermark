<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * Where the repetitions of a tiled watermark go.
 *
 * Extracted from {@see PdfWatermarker}, which had this right, so that
 * {@see ImageWatermarker} stops having it wrong. The image path used to step a fixed grid —
 * `max(210, fontSize * 10)` across and `max(225, fontSize * 11)` down — that never looked at
 * the text at all, so the default `{username} — {date}` template already overlapped its
 * neighbour by 30px at the default font size, and a realistic name with a timestamp ran 329px
 * into it, straight through the tile beyond. The PDF renderer had lived through the same bug
 * and been rebuilt around measured text; the image renderer never got that fix.
 *
 * Pure geometry, in whatever unit the caller measures in — points for PDF pages, pixels for
 * images — with the origin top-left and y downwards, which both coordinate systems share.
 *
 * `PdfWatermarker::tilePositions()` remains the entry point its own 22 assertions are pinned
 * to and now delegates here; those tests passing unchanged is the evidence that moving the
 * body did not alter it.
 */
final class TileLattice {

	/**
	 * Breathing room between repetitions, as a multiple of the type size — so the pattern
	 * keeps the same density at every font size instead of crowding as the text grows.
	 *
	 * Raised from 2.0, which packed the tiles tighter than anyone wanted once they stopped
	 * overlapping. It is the **one** number that sets watermark density, and the settings
	 * preview mirrors it (`GAP_FACTOR` in `WatermarkForm.vue`) so that what an admin
	 * approves on screen is what the renderers draw. Change one, change the other.
	 */
	public const GAP_FACTOR = 3.5;

	/**
	 * Line height as a multiple of the type size, used by the PDF renderer and mirrored by
	 * the preview. The image renderers measure real glyph heights instead, which is closer
	 * still.
	 */
	public const LINE_HEIGHT_FACTOR = 1.2;

	/**
	 * Centres of the tiles needed to cover one page or image. Centres outside the
	 * canvas are expected and required — they are what covers the edges and corners.
	 *
	 * The lattice is built in the text's *own* rotated frame rather than as a grid
	 * of rows and columns: spacing runs `textWidth + gap` along the direction the
	 * text reads and `lineHeight + gap` across it. That keeps neighbouring tiles
	 * clear of each other at any angle and puts the gap where it is meaningful —
	 * between adjacent lines of text. Stepping a row/column grid by the text's
	 * unrotated width and height, as both renderers did at different points in their
	 * history, instead spaces tiles by a bounding box that inflates with rotation, so
	 * the density of the pattern depends on the angle the user happened to pick.
	 *
	 * @return list<array{float, float}> `[x, y]` centre of each tile
	 */
	public static function positions(
		float $canvasWidth,
		float $canvasHeight,
		float $textWidth,
		float $lineHeight,
		int $rotation,
		int $fontSize,
	): array {
		$gap = $fontSize * self::GAP_FACTOR;
		$stepAlong = $textWidth + $gap;
		$stepAcross = $lineHeight + $gap;

		// A positive-angle watermark reads up and to the right on a y-downwards page.
		// `$across` is that direction turned 90°; the two are orthonormal, which is
		// what makes the projections below a change of basis.
		$rad = deg2rad((float)$rotation);
		$along = [cos($rad), -sin($rad)];
		$across = [sin($rad), cos($rad)];

		// How far the canvas extends along each axis of that frame, measured by
		// projecting its corners, so the lattice is only as large as it needs to be.
		$alongOffsets = [];
		$acrossOffsets = [];
		foreach ([[0.0, 0.0], [$canvasWidth, 0.0], [0.0, $canvasHeight], [$canvasWidth, $canvasHeight]] as [$x, $y]) {
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

	/**
	 * A tile-local offset, rotated into canvas coordinates.
	 *
	 * Both renderers place text by an anchor that is *not* its centre — GD and Imagick
	 * both take the left end of the baseline — so centring a rotated tile means rotating
	 * the anchor-to-centre offset by the same angle and stepping back along it. Positive
	 * rotation reads uphill on a y-downwards canvas, matching the settings preview and
	 * the convention `PdfWatermarkerTest` pins.
	 *
	 * @return array{float, float}
	 */
	public static function rotateOffset(float $dx, float $dy, int $rotation): array {
		$rad = deg2rad((float)$rotation);
		return [
			$dx * cos($rad) + $dy * sin($rad),
			-$dx * sin($rad) + $dy * cos($rad),
		];
	}
}
