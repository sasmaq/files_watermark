<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\ExifOrientation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\FilesWatermark\Service\ExifOrientation
 */
class ExifOrientationTest extends TestCase {

	/**
	 * An EXIF APP1 segment whose IFD0 holds one entry: Orientation.
	 *
	 * Public and static so the renderer's own test can build an oriented JPEG from it.
	 */
	public static function app1(int $orientation, bool $bigEndian = false): string {
		[$short, $long, $order] = $bigEndian ? ['n', 'N', 'MM'] : ['v', 'V', 'II'];

		$tiff = $order . pack($short, 42) . pack($long, 8)
			. pack($short, 1)
			. pack($short, 0x0112) . pack($short, 3) . pack($long, 1) . pack($short, $orientation) . "\0\0"
			. pack($long, 0);
		$payload = "Exif\0\0" . $tiff;

		return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
	}

	/** @dataProvider provideByteOrders */
	public function testTheOrientationIsReadInEitherByteOrder(bool $bigEndian): void {
		$jpeg = "\xFF\xD8" . self::app1(6, $bigEndian) . "\xFF\xDA\x00\x02";

		$this->assertSame(6, ExifOrientation::fromBytes($jpeg));
	}

	/** @return array<string, array{0: bool}> */
	public static function provideByteOrders(): array {
		return ['Intel' => [false], 'Motorola' => [true]];
	}

	/** An APP1 that comes after other segments - JFIF's APP0 most of all - is still found. */
	public function testAnExifSegmentAfterJfifIsFound(): void {
		$app0 = "\xFF\xE0" . pack('n', 16) . "JFIF\0" . str_repeat("\0", 9);
		$jpeg = "\xFF\xD8" . $app0 . self::app1(8) . "\xFF\xDA\x00\x02";

		$this->assertSame(8, ExifOrientation::fromBytes($jpeg));
	}

	/**
	 * Anything that does not parse is "as stored" - which is what GD did with every JPEG
	 * before this existed, so a malformed header costs nothing it did not already cost.
	 *
	 * @dataProvider provideUnreadable
	 */
	public function testAnythingUnreadableIsAsStored(string $bytes): void {
		$this->assertSame(1, ExifOrientation::fromBytes($bytes));
	}

	/** @return array<string, array{0: string}> */
	public static function provideUnreadable(): array {
		return [
			'not a JPEG' => ["\x89PNG\r\n\x1A\n"],
			'no EXIF before the pixels' => ["\xFF\xD8\xFF\xDA\x00\x02"],
			'truncated TIFF' => ["\xFF\xD8\xFF\xE1\x00\x0AExif\0\0II"],
			'out of range value' => ["\xFF\xD8" . self::app1(9) . "\xFF\xDA\x00\x02"],
			'empty' => [''],
		];
	}

	/**
	 * Each of the eight orientations turns a 2x1 image into the right shape, with the marked
	 * pixel where the tag says it belongs.
	 *
	 * @dataProvider provideTransforms
	 */
	public function testEachOrientationPutsTheMarkedCornerWhereItBelongs(int $orientation, array $size, array $red): void {
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD is not available.');
		}

		// 2x1: red on the left, white on the right.
		$img = imagecreatetruecolor(2, 1);
		imagesetpixel($img, 0, 0, imagecolorallocate($img, 255, 0, 0));
		imagesetpixel($img, 1, 0, imagecolorallocate($img, 255, 255, 255));

		$out = ExifOrientation::apply($img, $orientation);

		$this->assertSame($size, [imagesx($out), imagesy($out)]);
		$pixel = imagecolorsforindex($out, imagecolorat($out, $red[0], $red[1]));
		$this->assertSame([255, 0, 0], [$pixel['red'], $pixel['green'], $pixel['blue']]);
	}

	/** @return array<string, array{0: int, 1: array{int, int}, 2: array{int, int}}> */
	public static function provideTransforms(): array {
		return [
			'1 as stored' => [1, [2, 1], [0, 0]],
			'2 mirrored' => [2, [2, 1], [1, 0]],
			'3 upside down' => [3, [2, 1], [1, 0]],
			'4 flipped' => [4, [2, 1], [0, 0]],
			'5 transposed' => [5, [1, 2], [0, 0]],
			'6 turned clockwise' => [6, [1, 2], [0, 0]],
			'7 transversed' => [7, [1, 2], [0, 1]],
			'8 turned anticlockwise' => [8, [1, 2], [0, 1]],
		];
	}
}
