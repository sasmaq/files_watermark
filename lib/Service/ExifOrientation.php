<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * The EXIF orientation of a JPEG, and the GD transform that puts its pixels upright.
 *
 * ---------------------------------------------------------------------------
 * WHY A WATERMARKED PHOTO CAME OUT SIDEWAYS.
 *
 * A phone stores a portrait photo as landscape pixels plus a tag - Orientation 6, "rotate me
 * 90 degrees clockwise" - and every viewer honours the tag. GD does not: it decodes the raw
 * pixels and writes a JPEG with no EXIF at all, so the tag that made the photo upright was
 * dropped and the delivered copy lay on its side, with the watermark drawn across it at the
 * wrong angle. Applying the tag before drawing makes the pixels themselves upright, which
 * is the only form that survives a re-encode.
 * ---------------------------------------------------------------------------
 *
 * Read by hand rather than through `exif_read_data()`: `ext-exif` is optional on every
 * platform this app runs on, and the one value needed sits at a fixed place in the first
 * segment of the file. Anything this cannot parse is orientation 1 - "as stored" - which is
 * exactly what GD did before, so a malformed header costs nothing it did not cost already.
 */
final class ExifOrientation {

	/** The APP1 segment is capped at 64 KB by its own length field; read no further. */
	private const HEADER_BYTES = 65536 + 4;

	private const TAG_ORIENTATION = 0x0112;

	/**
	 * The orientation tag of the JPEG at $path, 1 to 8, or 1 when there is none.
	 */
	public static function read(string $path): int {
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			return 1;
		}
		$bytes = (string)fread($handle, self::HEADER_BYTES);
		fclose($handle);

		return self::fromBytes($bytes);
	}

	/** {@see read}, for bytes already in hand. */
	public static function fromBytes(string $bytes): int {
		if (!str_starts_with($bytes, "\xFF\xD8")) {
			return 1;
		}

		$offset = 2;
		$length = strlen($bytes);
		while ($offset + 4 <= $length) {
			if ($bytes[$offset] !== "\xFF") {
				return 1;
			}
			$marker = ord($bytes[$offset + 1]);
			// Start of scan or end of image: no APP1 came before the pixels.
			if ($marker === 0xDA || $marker === 0xD9) {
				return 1;
			}
			$segmentLength = self::uint('n', $bytes, $offset + 2, 2);
			// The length counts its own two bytes; anything shorter is a broken header.
			if ($segmentLength === null || $segmentLength < 2) {
				return 1;
			}
			if ($marker === 0xE1 && $segmentLength > 8 && substr($bytes, $offset + 4, 6) === "Exif\x00\x00") {
				return self::fromTiff(substr($bytes, $offset + 10, $segmentLength - 8));
			}
			$offset += 2 + $segmentLength;
		}

		return 1;
	}

	/** The orientation entry of IFD0 in a TIFF block. */
	private static function fromTiff(string $tiff): int {
		$order = substr($tiff, 0, 2);
		if ($order === 'II') {
			[$short, $long] = ['v', 'V'];
		} elseif ($order === 'MM') {
			[$short, $long] = ['n', 'N'];
		} else {
			return 1;
		}

		$ifd = self::uint($long, $tiff, 4, 4);
		$entries = $ifd === null ? null : self::uint($short, $tiff, $ifd, 2);
		if ($ifd === null || $entries === null) {
			return 1;
		}

		for ($i = 0; $i < $entries; $i++) {
			$entry = $ifd + 2 + $i * 12;
			$tag = self::uint($short, $tiff, $entry, 2);
			if ($tag === null) {
				return 1;
			}
			if ($tag !== self::TAG_ORIENTATION) {
				continue;
			}
			// A SHORT, left-justified in the four-byte value field.
			$value = self::uint($short, $tiff, $entry + 8, 2) ?? 1;

			return ($value >= 1 && $value <= 8) ? $value : 1;
		}

		return 1;
	}

	/** An unsigned integer of $length bytes at $offset, or null when that runs past the end. */
	private static function uint(string $format, string $bytes, int $offset, int $length): ?int {
		if ($offset < 0 || $offset + $length > strlen($bytes)) {
			return null;
		}

		$value = unpack($format, substr($bytes, $offset, $length));

		return $value === false ? null : (int)$value[1];
	}

	/**
	 * $image turned upright for $orientation. May be a new image; the old one is destroyed.
	 *
	 * `imagerotate()` turns counter-clockwise, so "rotate 90 clockwise" is -90.
	 */
	public static function apply(\GdImage $image, int $orientation): \GdImage {
		if ($orientation === 2 || $orientation === 4 || $orientation === 5 || $orientation === 7) {
			imageflip($image, $orientation === 2 || $orientation === 7 ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
		}

		$angle = match ($orientation) {
			3 => 180,
			5, 6, 7 => -90,
			8 => 90,
			default => 0,
		};
		if ($angle === 0) {
			return $image;
		}

		$rotated = imagerotate($image, $angle, 0);
		if ($rotated === false) {
			// Upright is a nicety next to the watermark itself; the photo still goes out
			// watermarked, merely as GD found it.
			return $image;
		}

		imagedestroy($image);

		return $rotated;
	}
}
