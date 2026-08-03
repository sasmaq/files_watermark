<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;

/**
 * Watermarks JPEG, PNG and WEBP.
 *
 * **GD is the default engine, and Imagick remains fully supported** as the alternative.
 * The preference used to run the other way round, and the reason for the flip is the same
 * one behind {@see PdfWatermarker} having no external binaries: output should not depend on
 * what the host happens to have installed. GD is bundled with essentially every PHP build
 * and Nextcloud server already requires it, while Imagick is optional everywhere and on the
 * RHEL 9 target is EPEL-only. Preferring Imagick meant two hosts running the same config
 * could produce visibly different files, and which one you got was an accident of packaging.
 *
 * Imagick is not deprecated here and is not merely a fallback for a missing extension: it is
 * selected whenever GD cannot decode the input - today that means **WebP on a GD built
 * without libwebp**, which used to be a hard error telling the admin to install Imagick even
 * when Imagick was sitting right there. See {@see engineForMime()} for the whole rule.
 *
 * The two paths are kept equivalent rather than merely both-working: same tile steps, same
 * rotation sense (GD's `imagettftext()` measures counter-clockwise and Imagick's
 * `annotateImage()` clockwise, hence the negated angle), same 30%-width centred logo.
 *
 * Both engines draw with the **bundled** face ({@see ShapedText::bundledFontPath()}), the same
 * one the PDF renderer embeds, so output does not vary with the host's installed fonts and a
 * JPEG carries the same letterforms as a PDF. The old GD bitmap-font fallback is gone with the
 * system-font list that made it necessary; a missing bundled font is a broken install and is
 * refused rather than drawn badly.
 */
class ImageWatermarker {

	public const ENGINE_GD = 'gd';
	public const ENGINE_IMAGICK = 'imagick';

	/**
	 * @param ?string $preferredEngine `gd`, `imagick`, or null to select per file. Nextcloud's
	 *                                 container cannot resolve a scalar and falls back to the
	 *                                 default, so in production this is always null; it exists
	 *                                 so the tests can drive both engines on a host that has
	 *                                 both, which is otherwise impossible now that one of them
	 *                                 always wins.
	 */
	public function __construct(
		private ?string $preferredEngine = null,
	) {
	}

	public function apply(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders): void {
		$mime = (string)mime_content_type($sourcePath);

		if ($this->engineForMime($mime) === self::ENGINE_IMAGICK) {
			$this->applyWithImagick($sourcePath, $destPath, $config, $placeholders);
		} else {
			$this->applyWithGd($sourcePath, $destPath, $config, $placeholders, $mime);
		}
	}

	/**
	 * Which engine will handle `$mime`, and why - GD first, Imagick where GD cannot go.
	 *
	 * Public because it is the one piece of behaviour worth asserting directly: everything
	 * else about engine choice is only observable through rendered pixels, which cannot tell
	 * the two engines apart on purpose.
	 *
	 * @throws \RuntimeException when neither engine can read the format, naming which of the
	 *                           two limits was hit rather than reporting a generic failure
	 */
	public function engineForMime(string $mime): string {
		if ($this->preferredEngine === self::ENGINE_IMAGICK) {
			if (!$this->hasImagick()) {
				throw new \RuntimeException('Imagick was requested but the extension is not installed.');
			}
			return self::ENGINE_IMAGICK;
		}

		if ($this->preferredEngine === self::ENGINE_GD) {
			if (!$this->gdCanRead($mime)) {
				throw new \RuntimeException("GD was requested but cannot read $mime on this build.");
			}
			return self::ENGINE_GD;
		}

		if ($this->gdCanRead($mime)) {
			return self::ENGINE_GD;
		}

		if ($this->hasImagick()) {
			return self::ENGINE_IMAGICK;
		}

		if ($mime === 'image/webp' && $this->hasGd()) {
			throw new \RuntimeException('GD was compiled without WebP support. Install Imagick or recompile GD with libwebp.');
		}

		if (!$this->hasGd()) {
			throw new \RuntimeException('Neither GD nor Imagick is available; images cannot be watermarked.');
		}

		throw new \RuntimeException("Unsupported image type: $mime");
	}

	/** Whether this GD build can decode `$mime`. WebP is the only conditional one. */
	private function gdCanRead(string $mime): bool {
		if (!$this->hasGd()) {
			return false;
		}
		return match ($mime) {
			'image/jpeg', 'image/png' => true,
			'image/webp' => $this->hasGdWebpSupport(),
			default => false,
		};
	}

	/*
	 * The three capability probes are protected rather than inlined so tests can simulate a
	 * host that lacks one of them. Every branch of engineForMime() otherwise depends on how
	 * the machine running the suite happens to be built, which is the kind of host-dependent
	 * coverage this app has been removing everywhere else.
	 */

	protected function hasGd(): bool {
		return extension_loaded('gd');
	}

	protected function hasGdWebpSupport(): bool {
		return function_exists('imagecreatefromwebp') && function_exists('imagewebp');
	}

	protected function hasImagick(): bool {
		return class_exists('Imagick');
	}

	private function applyWithImagick(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders): void {
		$image = new \Imagick($sourcePath);
		$width = $image->getImageWidth();
		$height = $image->getImageHeight();
		$alpha = $config->getOpacity() / 100;

		if (in_array($config->getType(), ['text', 'combined'], true)) {
			$text = $this->resolvePlaceholders($config->getTextTemplate() ?? '{username} {date}', $placeholders);
			$color = $config->getColor();
			$fontSize = $config->getFontSize();
			$rotation = $config->getRotation();

			// Shaped here rather than trusted to the backend: ImageMagick only reorders and
			// joins Arabic when it was built against Raqm/HarfBuzz, which no host can be
			// required to have. Doing it in PHP is what makes the output the same everywhere.
			$text = ShapedText::shape($text);

			$draw = new \ImagickDraw();
			$draw->setFont($this->fontFor($text));
			$draw->setFontSize($fontSize);
			$draw->setFillColor(new \ImagickPixel($color));
			$draw->setFillOpacity($alpha);

			// Measure the text this font will actually draw, rather than guessing from the
			// type size. queryFontMetrics answers for the same $draw that renders it.
			$metrics = $image->queryFontMetrics($draw, $text);
			$textWidth = max(1.0, (float)$metrics['textWidth']);
			$lineHeight = max(1.0, (float)$metrics['textHeight']);
			// annotateImage anchors at the left end of the baseline, so the centre of the
			// text sits half its width to the right and rather less than half its height
			// above - ascender up, descender down.
			$anchorToCentreX = $textWidth / 2;
			$anchorToCentreY = - ((float)$metrics['ascender'] + (float)$metrics['descender']) / 2;

			foreach (TileLattice::positions($width, $height, $textWidth, $lineHeight, $rotation, $fontSize) as [$cx, $cy]) {
				[$offsetX, $offsetY] = TileLattice::rotateOffset($anchorToCentreX, $anchorToCentreY, $rotation);
				// annotateImage places text at the given pixel coords and rotates it in place,
				// avoiding the cumulative-transform bug that $draw->rotate() in a loop would
				// cause. Its angle runs clockwise, hence the negation.
				$image->annotateImage($draw, $cx - $offsetX, $cy - $offsetY, -$rotation, $text);
			}
		}

		$imagePath = $config->getImagePath();
		if (
			in_array($config->getType(), ['image', 'combined'], true)
			&& $imagePath !== null && $imagePath !== '' && file_exists($imagePath)
		) {
			$watermark = new \Imagick($imagePath);
			$wmW = intval($width * 0.3);
			$wmH = intval($watermark->getImageHeight() * ($wmW / $watermark->getImageWidth()));
			$watermark->resizeImage($wmW, $wmH, \Imagick::FILTER_LANCZOS, 1);
			$watermark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $alpha, \Imagick::CHANNEL_ALPHA);
			$image->compositeImage(
				$watermark,
				\Imagick::COMPOSITE_OVER,
				intval(($width - $wmW) / 2),
				intval(($height - $wmH) / 2)
			);
		}

		$image->writeImage($destPath);
		$image->clear();
	}

	/**
	 * `$mime` is passed in rather than re-detected: {@see engineForMime()} has already
	 * decided this build can read it, and detecting twice invites the two answers to differ.
	 */
	private function applyWithGd(string $sourcePath, string $destPath, WatermarkConfig $config, array $placeholders, string $mime): void {
		$src = match ($mime) {
			'image/jpeg' => imagecreatefromjpeg($sourcePath),
			'image/png' => imagecreatefrompng($sourcePath),
			'image/webp' => imagecreatefromwebp($sourcePath),
			default => throw new \RuntimeException("Unsupported image type: $mime"),
		};

		// GD reports a file it cannot decode by returning false and emitting a warning, and
		// every call below takes the handle without looking. Truncated uploads are the
		// realistic source: the MIME type is read from the first bytes, so a half-written
		// JPEG passes engineForMime() and fails here.
		if ($src === false) {
			throw new \RuntimeException("GD could not decode this $mime file; it may be truncated or corrupt.");
		}

		$width = imagesx($src);
		$height = imagesy($src);

		if (in_array($config->getType(), ['text', 'combined'], true)) {
			$text = $this->resolvePlaceholders($config->getTextTemplate() ?? '{username} {date}', $placeholders);
			$color = $this->hexToRgb($config->getColor());
			$opacity = intval((1 - $config->getOpacity() / 100) * 127);
			$textColor = imagecolorallocatealpha($src, $color[0], $color[1], $color[2], $opacity);
			// False only for a palette image whose 256 entries are already spoken for. The
			// alternative to refusing is drawing with colour index -1, which GD renders as
			// whatever happens to sit at that slot.
			if ($textColor === false) {
				throw new \RuntimeException('GD could not allocate the watermark colour; the image palette is full.');
			}
			$fontSize = $config->getFontSize();
			$rotation = $config->getRotation();
			// FreeType draws code points in the order given and joins nothing, so Arabic
			// has to arrive already shaped and already in visual order.
			$text = ShapedText::shape($text);
			$fontPath = $this->fontFor($text);

			// imagettfbbox measures the glyphs this font will actually draw, at angle 0
			// so the lattice can do the rotating. Its eight values are the corners
			// relative to the baseline origin, with y negative above the baseline.
			$box = imagettfbbox($fontSize, 0, $fontPath, $text);
			// False when FreeType cannot open the face. fontFor() has already established the
			// file is there, so this is a font that is present but unreadable - measuring it
			// as nothing would tile the whole page at one point.
			if ($box === false) {
				throw new \RuntimeException('FreeType could not measure the bundled font; the file is present but unusable.');
			}
			$left = min($box[0], $box[6]);
			$right = max($box[2], $box[4]);
			$top = min($box[5], $box[7]);
			$bottom = max($box[1], $box[3]);
			$textWidth = max(1.0, (float)($right - $left));
			$lineHeight = max(1.0, (float)($bottom - $top));
			// imagettftext anchors at the left end of the baseline, which is neither
			// corner of that box; this is the step from the anchor to its centre.
			$anchorToCentreX = ($left + $right) / 2;
			$anchorToCentreY = ($top + $bottom) / 2;

			foreach (TileLattice::positions($width, $height, $textWidth, $lineHeight, $rotation, $fontSize) as [$cx, $cy]) {
				[$offsetX, $offsetY] = TileLattice::rotateOffset($anchorToCentreX, $anchorToCentreY, $rotation);
				imagettftext(
					$src,
					$fontSize,
					$rotation,
					(int)round($cx - $offsetX),
					(int)round($cy - $offsetY),
					$textColor,
					$fontPath,
					$text,
				);
			}
		}

		$imagePath = $config->getImagePath();
		if (
			in_array($config->getType(), ['image', 'combined'], true)
			&& $imagePath !== null && $imagePath !== '' && file_exists($imagePath)
		) {
			$watermarkMime = mime_content_type($imagePath);
			// False for a type GD does not read here, and false again if it does read the
			// type but cannot decode this particular file. The logo is dropped either way and
			// the text half still renders - the same thing an unsupported type has always
			// done, rather than a new failure mode for a corrupt one.
			$wm = match ($watermarkMime) {
				'image/png' => imagecreatefrompng($imagePath),
				'image/jpeg' => imagecreatefromjpeg($imagePath),
				default => false,
			};

			if ($wm !== false) {
				$wmOrigW = imagesx($wm);
				$wmOrigH = imagesy($wm);
				$wmW = intval($width * 0.3);
				$wmH = intval($wmOrigH * ($wmW / $wmOrigW));
				$scaled = imagescale($wm, $wmW, $wmH);
				imagedestroy($wm);

				// imagescale() fails on a zero target dimension - a logo scaled below one
				// pixel by a very narrow source image. Nothing to composite, so the source
				// image goes out with its text watermark and without the logo.
				if ($scaled !== false) {
					$dstX = intval(($width - $wmW) / 2);
					$dstY = intval(($height - $wmH) / 2);
					imagecopymerge($src, $scaled, $dstX, $dstY, 0, 0, $wmW, $wmH, $config->getOpacity());
					imagedestroy($scaled);
				}
			}
		}

		match ($mime) {
			'image/jpeg' => imagejpeg($src, $destPath, 90),
			'image/png' => imagepng($src, $destPath),
			'image/webp' => imagewebp($src, $destPath, 90),
		};

		imagedestroy($src);
	}

	/**
	 * The TrueType file every watermark is drawn with.
	 *
	 * **One face, whatever the text.** This used to walk a list of system fonts - DejaVu,
	 * Liberation, macOS Arial - chosen by *name*, and a name cannot express "has the glyphs
	 * this string needs": two of those three carry no Arabic at all, so the result depended
	 * on what the host happened to have installed. The bundled face removes the host from
	 * the question entirely and matches what the PDF renderer embeds, so a JPEG and a PDF of
	 * the same file carry the same letterforms.
	 *
	 * **Refuses rather than drawing something wrong.** The font is committed to the
	 * repository, so its absence means a broken install, not a routine condition - and every
	 * alternative is worse than failing: GD's bitmap font would draw Arabic as mojibake, a
	 * Latin TTF would draw a row of empty boxes. Both produce a *valid image file that no one
	 * can read*, which is the outcome worth ruling out. Throwing puts it on the app's existing
	 * honest-failure path: skipped with an audit row for the in-place triggers, denied for
	 * `on_share`, a named error for an on-demand apply.
	 */
	private function fontFor(string $text): string {
		$bundled = ShapedText::bundledFontPath();
		if ($bundled === null) {
			throw new \RuntimeException(
				'Cannot draw this watermark: the bundled font (resources/fonts) could not be read. '
					. 'Drawing with a substitute would produce an unreadable image rather than a missing one.',
			);
		}

		return $bundled;
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
