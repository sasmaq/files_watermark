<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\ImageWatermarker;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for {@see ImageWatermarker}. They run against the real image
 * stack. When Imagick is not installed the watermarker uses its GD fallback, so
 * these tests cover whichever engine is active in the environment.
 *
 * Precision checks (opacity/rotation) use PNG only, since JPEG/WEBP are lossy
 * and would introduce noise unrelated to the watermark.
 */
class ImageWatermarkerTest extends TestCase {

	private ImageWatermarker $watermarker;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		if (!extension_loaded('gd') && !class_exists('Imagick')) {
			$this->markTestSkipped('Neither GD nor Imagick is available.');
		}
		$this->watermarker = new ImageWatermarker();
		$this->tmpDir = sys_get_temp_dir() . '/wm_img_test_' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0700, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->tmpDir);
		parent::tearDown();
	}

	/** @return array<string, array{string, string}> */
	public static function formatProvider(): array {
		return [
			'JPEG' => ['image/jpeg', 'jpg'],
			'PNG' => ['image/png', 'png'],
			'WEBP' => ['image/webp', 'webp'],
		];
	}

	/** @dataProvider formatProvider */
	public function testProducesValidImageOfSameDimensions(string $mime, string $ext): void {
		if ($mime === 'image/webp' && !$this->webpSupported()) {
			$this->markTestSkipped('WebP support not available in this build.');
		}

		$source = $this->createImage($mime, $ext, 400, 300);
		$dest = $this->tmpDir . '/out.' . $ext;

		$this->watermarker->apply($source, $dest, $this->makeConfig('text'), [
			'username' => 'Alice',
			'date' => '2026-06-27',
		]);

		$this->assertFileExists($dest);
		$info = getimagesize($dest);
		$this->assertNotFalse($info, 'Output is not a valid image');
		$this->assertSame($mime, $info['mime']);
		$this->assertSame(400, $info[0]);
		$this->assertSame(300, $info[1]);
	}

	public function testTextWatermarkChangesPixels(): void {
		$source = $this->createImage('image/png', 'png', 400, 300);
		$dest = $this->tmpDir . '/text.png';

		$this->watermarker->apply($source, $dest, $this->makeConfig('text'), ['username' => 'Alice']);

		$this->assertGreaterThan(0, $this->changedPixels($source, $dest), 'No watermark pixels were drawn');
	}

	public function testImageOverlayChangesPixels(): void {
		$source = $this->createImage('image/png', 'png', 400, 300);
		$logo = $this->createImage('image/png', 'png', 120, 90, [255, 0, 0], 'logo');
		$dest = $this->tmpDir . '/overlay.png';

		$config = $this->makeConfig('image');
		$config->setImagePath($logo);

		$this->watermarker->apply($source, $dest, $config, []);

		$this->assertGreaterThan(0, $this->changedPixels($source, $dest), 'Logo overlay produced no change');
	}

	public function testCombinedTextAndImageOverlay(): void {
		$source = $this->createImage('image/png', 'png', 400, 300);
		$logo = $this->createImage('image/png', 'png', 120, 90, [255, 0, 0], 'logo');
		$dest = $this->tmpDir . '/combined.png';

		$config = $this->makeConfig('combined');
		$config->setImagePath($logo);

		$this->watermarker->apply($source, $dest, $config, ['username' => 'Alice']);

		$info = getimagesize($dest);
		$this->assertNotFalse($info);
		$this->assertGreaterThan(0, $this->changedPixels($source, $dest));
	}

	public function testFontSizeIsConfigurable(): void {
		$base = $this->createImage('image/png', 'png', 600, 400);

		$small = $this->tmpDir . '/small.png';
		$large = $this->tmpDir . '/large.png';
		$this->watermarker->apply($base, $small, $this->makeConfig('text', 100, 0, 12), ['username' => 'WM']);
		$this->watermarker->apply($base, $large, $this->makeConfig('text', 100, 0, 48), ['username' => 'WM']);

		$this->assertGreaterThan(
			$this->totalInk($small),
			$this->totalInk($large),
			'A larger font size should add more ink',
		);
	}

	public function testColorIsConfigurable(): void {
		$base = $this->createImage('image/png', 'png', 400, 300);

		$black = $this->tmpDir . '/black.png';
		$red = $this->tmpDir . '/red.png';
		$this->watermarker->apply($base, $black, $this->makeConfig('text', 100, 0, 20, '#000000'), ['username' => 'WM']);
		$this->watermarker->apply($base, $red, $this->makeConfig('text', 100, 0, 20, '#ff0000'), ['username' => 'WM']);

		$this->assertNotSame(md5_file($black), md5_file($red), 'Different colors should produce different output');
	}

	public function testOpacityScalesWatermarkIntensity(): void {
		$base = $this->createImage('image/png', 'png', 400, 300);

		$ink = [];
		foreach ([0, 50, 100] as $opacity) {
			$dest = $this->tmpDir . "/op_$opacity.png";
			$this->watermarker->apply($base, $dest, $this->makeConfig('text', $opacity), ['username' => 'WATERMARK']);
			$ink[$opacity] = $this->totalInk($dest);
		}

		// Opacity 0 -> fully transparent text -> no ink added over a white image.
		$this->assertSame(0.0, $ink[0], 'Opacity 0 should leave the image untouched');
		// More opacity -> more ink.
		$this->assertGreaterThan($ink[50], $ink[100], 'Opacity 100 should be darker than 50');
		$this->assertGreaterThan(0.0, $ink[50], 'Opacity 50 should still draw something');
	}

	public function testRotationChangesOutput(): void {
		if ($this->findSystemFont() === null && !class_exists('Imagick')) {
			$this->markTestSkipped('No TrueType font available; GD rotation path cannot be exercised.');
		}

		$base = $this->createImage('image/png', 'png', 400, 300);

		$flat = $this->tmpDir . '/rot0.png';
		$tilt = $this->tmpDir . '/rot45.png';
		$this->watermarker->apply($base, $flat, $this->makeConfig('text', 100, 0), ['username' => 'WATERMARK']);
		$this->watermarker->apply($base, $tilt, $this->makeConfig('text', 100, 45), ['username' => 'WATERMARK']);

		$this->assertNotSame(
			md5_file($flat),
			md5_file($tilt),
			'Rotation 0 and 45 produced identical output',
		);
	}

	private function makeConfig(string $type, int $opacity = 80, int $rotation = 45, int $fontSize = 20, string $color = '#000000'): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setType($type);
		$config->setTextTemplate('{username}');
		$config->setPosition('diagonal');
		$config->setOpacity($opacity);
		$config->setFontSize($fontSize);
		$config->setColor($color);
		$config->setRotation($rotation);
		$config->setTrigger('on_demand');
		return $config;
	}

	/** @param array{int,int,int} $rgb */
	private function createImage(string $mime, string $ext, int $w, int $h, array $rgb = [255, 255, 255], string $name = 'source'): string {
		$img = imagecreatetruecolor($w, $h);
		$fill = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
		imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $fill);

		$path = $this->tmpDir . "/$name.$ext";
		match ($mime) {
			'image/jpeg' => imagejpeg($img, $path, 95),
			'image/png' => imagepng($img, $path),
			'image/webp' => imagewebp($img, $path, 95),
		};
		imagedestroy($img);
		return $path;
	}

	/** Counts pixels that differ between two PNG images of equal size. */
	private function changedPixels(string $a, string $b): int {
		$ia = imagecreatefrompng($a);
		$ib = imagecreatefrompng($b);
		$w = imagesx($ia);
		$h = imagesy($ia);
		$changed = 0;
		for ($x = 0; $x < $w; $x++) {
			for ($y = 0; $y < $h; $y++) {
				if (imagecolorat($ia, $x, $y) !== imagecolorat($ib, $x, $y)) {
					$changed++;
				}
			}
		}
		imagedestroy($ia);
		imagedestroy($ib);
		return $changed;
	}

	/** Sum of darkness (255 - gray) across a PNG; 0 for a pure-white image. */
	private function totalInk(string $path): float {
		$img = imagecreatefrompng($path);
		$w = imagesx($img);
		$h = imagesy($img);
		$ink = 0.0;
		for ($x = 0; $x < $w; $x++) {
			for ($y = 0; $y < $h; $y++) {
				$rgb = imagecolorat($img, $x, $y);
				$r = ($rgb >> 16) & 0xFF;
				$g = ($rgb >> 8) & 0xFF;
				$b = $rgb & 0xFF;
				$ink += 255 - (($r + $g + $b) / 3);
			}
		}
		imagedestroy($img);
		return $ink;
	}

	/** Mirrors ImageWatermarker::findSystemFont() for the rotation-test guard. */
	private function findSystemFont(): ?string {
		$candidates = [
			'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
			'/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
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

	private function webpSupported(): bool {
		if (class_exists('Imagick')) {
			return true;
		}
		return function_exists('imagecreatefromwebp') && function_exists('imagewebp');
	}
}
