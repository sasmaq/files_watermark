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
 * selected whenever GD cannot decode the input — today that means **WebP on a GD built
 * without libwebp**, which used to be a hard error telling the admin to install Imagick even
 * when Imagick was sitting right there. See {@see engineForMime()} for the whole rule.
 *
 * The two paths are kept equivalent rather than merely both-working: same tile steps, same
 * rotation sense (GD's `imagettftext()` measures counter-clockwise and Imagick's
 * `annotateImage()` clockwise, hence the negated angle), same 30%-width centred logo.
 *
 * One honest quality cliff remains, and it belongs to GD alone: with no TrueType font on the
 * host, {@see findSystemFont()} returns null and the text falls back to GD's built-in bitmap
 * font, which cannot rotate. That is a font problem rather than an engine one — bundling a
 * font is tracked in `doc/tasks.md` — so it deliberately does *not* switch engines. Engine
 * choice depends only on format support, which keeps it predictable.
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
	 * Which engine will handle `$mime`, and why — GD first, Imagick where GD cannot go.
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

			$draw = new \ImagickDraw();
			$draw->setFont('DejaVu-Sans-Bold');
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
			// above — ascender up, descender down.
			$anchorToCentreX = $textWidth / 2;
			$anchorToCentreY = -((float)$metrics['ascender'] + (float)$metrics['descender']) / 2;

			foreach (TileLattice::positions($width, $height, $textWidth, $lineHeight, $rotation, $fontSize) as [$cx, $cy]) {
				[$offsetX, $offsetY] = TileLattice::rotateOffset($anchorToCentreX, $anchorToCentreY, $rotation);
				// annotateImage places text at the given pixel coords and rotates it in place,
				// avoiding the cumulative-transform bug that $draw->rotate() in a loop would
				// cause. Its angle runs clockwise, hence the negation.
				$image->annotateImage($draw, $cx - $offsetX, $cy - $offsetY, -$rotation, $text);
			}
		}

		if (in_array($config->getType(), ['image', 'combined'], true) && $config->getImagePath() && file_exists($config->getImagePath())) {
			$watermark = new \Imagick($config->getImagePath());
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

		$width = imagesx($src);
		$height = imagesy($src);

		if (in_array($config->getType(), ['text', 'combined'], true)) {
			$text = $this->resolvePlaceholders($config->getTextTemplate() ?? '{username} {date}', $placeholders);
			$color = $this->hexToRgb($config->getColor());
			$opacity = intval((1 - $config->getOpacity() / 100) * 127);
			$textColor = imagecolorallocatealpha($src, $color[0], $color[1], $color[2], $opacity);
			$fontSize = $config->getFontSize();
			$rotation = $config->getRotation();
			$fontPath = $this->findSystemFont();

			if ($fontPath !== null) {
				// imagettfbbox measures the glyphs this font will actually draw, at angle 0
				// so the lattice can do the rotating. Its eight values are the corners
				// relative to the baseline origin, with y negative above the baseline.
				$box = imagettfbbox($fontSize, 0, $fontPath, $text);
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
			} else {
				// No TTF font available: fall back to built-in pixelated font. It cannot
				// rotate, so the lattice is asked for an unrotated one — spacing a tilted
				// pattern for text that will be drawn flat is how tiles end up on top of
				// each other. imagestring's font ids only go up to 5.
				$gdFontSize = max(1, min(5, intval($fontSize / 4)));
				$textWidth = max(1.0, (float)(imagefontwidth($gdFontSize) * strlen($text)));
				$lineHeight = max(1.0, (float)imagefontheight($gdFontSize));

				foreach (TileLattice::positions($width, $height, $textWidth, $lineHeight, 0, $fontSize) as [$cx, $cy]) {
					// imagestring anchors at the top-left corner, not the baseline.
					imagestring(
						$src,
						$gdFontSize,
						(int)round($cx - $textWidth / 2),
						(int)round($cy - $lineHeight / 2),
						$text,
						$textColor,
					);
				}
			}
		}

		if (in_array($config->getType(), ['image', 'combined'], true) && $config->getImagePath() && file_exists($config->getImagePath())) {
			$watermarkMime = mime_content_type($config->getImagePath());
			$wm = match ($watermarkMime) {
				'image/png' => imagecreatefrompng($config->getImagePath()),
				'image/jpeg' => imagecreatefromjpeg($config->getImagePath()),
				default => null,
			};

			if ($wm !== null) {
				$wmOrigW = imagesx($wm);
				$wmOrigH = imagesy($wm);
				$wmW = intval($width * 0.3);
				$wmH = intval($wmOrigH * ($wmW / $wmOrigW));
				$scaled = imagescale($wm, $wmW, $wmH);
				imagedestroy($wm);

				$dstX = intval(($width - $wmW) / 2);
				$dstY = intval(($height - $wmH) / 2);
				imagecopymerge($src, $scaled, $dstX, $dstY, 0, 0, $wmW, $wmH, $config->getOpacity());
				imagedestroy($scaled);
			}
		}

		match ($mime) {
			'image/jpeg' => imagejpeg($src, $destPath, 90),
			'image/png' => imagepng($src, $destPath),
			'image/webp' => imagewebp($src, $destPath, 90),
		};

		imagedestroy($src);
	}

	private function findSystemFont(): ?string {
		$candidates = [
			// Linux (most Nextcloud servers)
			'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
			'/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
			// macOS (development environments)
			'/System/Library/Fonts/Supplemental/Arial Bold.ttf',
			'/System/Library/Fonts/Supplemental/Arial.ttf',
			'/System/Library/Fonts/Geneva.ttf',
			'/Library/Fonts/Arial.ttf',
		];
		foreach ($candidates as $path) {
			if (file_exists($path)) {
				return $path;
			}
		}
		return null;
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
