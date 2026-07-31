<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\ImageWatermarker;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for {@see ImageWatermarker}. They run against the real image stack.
 *
 * **GD is the default engine**, so that is what these exercise on any host with the
 * extension — which is every host, in practice. Imagick is not left untested for that
 * reason: {@see testBothEnginesProduceAWatermarkedImage} drives it explicitly wherever it is
 * installed, and the selection rules themselves are covered exhaustively and
 * host-independently through {@see FakeImageStack}.
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

	/**
	 * The regression test for tiles landing on top of each other.
	 *
	 * The image renderer used to step a fixed grid — `max(210, fontSize * 10)` across —
	 * that never measured the text, so the *default* `{username} — {date}` template
	 * already ran 30px into its neighbour at the default font size, and a realistic name
	 * with a timestamp overran by 329px, straight through the tile beyond it.
	 *
	 * Detected rather than eyeballed: at 50% opacity a pixel covered by one glyph lands on
	 * 126, and a pixel covered by two composites twice and lands on 62. So the darkest
	 * pixel in the output *is* the overlap measurement. Antialiasing only ever makes
	 * pixels lighter, so it cannot fake a pass.
	 *
	 * Mutation-tested: shortening the lattice's step to `textWidth * 0.6` drops the
	 * darkest pixel to 62 at every rotation and fails this.
	 *
	 * @dataProvider rotationProvider
	 */
	public function testTilesNeverOverlapInTheRenderedImage(int $rotation): void {
		if ($this->findSystemFont() === null) {
			$this->markTestSkipped('No TrueType font available; GD draws the unrotatable bitmap font.');
		}

		$base = $this->createImage('image/png', 'png', 700, 500);
		$dest = $this->tmpDir . "/overlap_$rotation.png";

		$this->watermarker->apply($base, $dest, $this->makeConfig('text', 50, $rotation, 20, '#000000'), [
			'username' => 'Mohammed Al-Amri',
			'date' => '2026-07-31',
		]);

		$this->assertGreaterThan(
			100,
			$this->darkestPixel($dest),
			"Watermark tiles overlap at {$rotation}°: some pixel is covered twice",
		);
	}

	/** @return array<string, array{int}> */
	public static function rotationProvider(): array {
		return ['0' => [0], '30' => [30], '45' => [45], '90' => [90], '-45' => [-45]];
	}

	/**
	 * Type size drives the lattice as well as the glyphs — the gap between repetitions
	 * scales with it — so a larger font means larger text in *fewer*, more widely spaced
	 * tiles. Total ink is therefore not monotonic in font size, and this used to assert
	 * that it was: under the old fixed grid the tile count barely moved (12 tiles at both
	 * 12pt and 20pt on a 600×400 image), so bigger type could only mean more ink. It now
	 * asserts what font size actually controls, which is how big the drawn text is.
	 */
	public function testFontSizeIsConfigurable(): void {
		$base = $this->createImage('image/png', 'png', 600, 400);

		$small = $this->tmpDir . '/small.png';
		$large = $this->tmpDir . '/large.png';
		$this->watermarker->apply($base, $small, $this->makeConfig('text', 100, 0, 12), ['username' => 'WM']);
		$this->watermarker->apply($base, $large, $this->makeConfig('text', 100, 0, 48), ['username' => 'WM']);

		$this->assertGreaterThan(
			$this->longestInkRun($small),
			$this->longestInkRun($large),
			'A larger font size should draw physically larger text',
		);
		$this->assertNotSame(md5_file($small), md5_file($large));
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
		// GD is the default engine, and its bitmap-font fallback cannot rotate at all — so a
		// host with no TrueType font cannot exercise rotation, whether or not Imagick is
		// installed. Before GD became the default, Imagick's presence was enough.
		if ($this->findSystemFont() === null) {
			$this->markTestSkipped('No TrueType font available; the GD rotation path cannot be exercised.');
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

	/*
	 * Engine selection. GD is the default and Imagick covers what GD cannot read; the whole
	 * matrix is asserted through FakeImageStack so it holds on hosts this suite never sees.
	 */

	/** @return array<string, array{bool, bool, bool, string, string}> */
	public static function engineSelectionProvider(): array {
		// gd, gdWebp, imagick, mime, expected engine
		return [
			'PNG prefers GD even with Imagick installed' => [true, true, true, 'image/png', ImageWatermarker::ENGINE_GD],
			'JPEG prefers GD even with Imagick installed' => [true, true, true, 'image/jpeg', ImageWatermarker::ENGINE_GD],
			'WebP uses GD when this build has libwebp' => [true, true, true, 'image/webp', ImageWatermarker::ENGINE_GD],
			'WebP falls to Imagick when GD lacks libwebp' => [true, false, true, 'image/webp', ImageWatermarker::ENGINE_IMAGICK],
			'PNG falls to Imagick when GD is absent' => [false, false, true, 'image/png', ImageWatermarker::ENGINE_IMAGICK],
		];
	}

	/** @dataProvider engineSelectionProvider */
	public function testEngineSelection(bool $gd, bool $gdWebp, bool $imagick, string $mime, string $expected): void {
		$watermarker = new FakeImageStack($gd, $gdWebp, $imagick);

		$this->assertSame($expected, $watermarker->engineForMime($mime));
	}

	public function testGdIsPreferredEvenWhenImagickIsAvailable(): void {
		// The headline of the change, stated on its own rather than as one provider row:
		// output should not depend on whether the host happens to carry Imagick.
		$withImagick = new FakeImageStack(true, true, true);
		$withoutImagick = new FakeImageStack(true, true, false);

		$this->assertSame(ImageWatermarker::ENGINE_GD, $withImagick->engineForMime('image/png'));
		$this->assertSame(
			$withoutImagick->engineForMime('image/png'),
			$withImagick->engineForMime('image/png'),
			'Installing Imagick must not change which engine renders a PNG',
		);
	}

	public function testWebpWithoutLibwebpOrImagickReportsTheBuildProblem(): void {
		$watermarker = new FakeImageStack(true, false, false);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('GD was compiled without WebP support');

		$watermarker->engineForMime('image/webp');
	}

	public function testNoEngineAtAllIsReportedAsSuch(): void {
		$watermarker = new FakeImageStack(false, false, false);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Neither GD nor Imagick');

		$watermarker->engineForMime('image/png');
	}

	public function testUnsupportedTypeIsNamedRatherThanBlamedOnTheEngine(): void {
		$watermarker = new FakeImageStack(true, true, false);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unsupported image type: image/gif');

		$watermarker->engineForMime('image/gif');
	}

	public function testForcingAnEngineThatIsNotInstalledFailsLoudly(): void {
		$watermarker = new FakeImageStack(true, true, false, ImageWatermarker::ENGINE_IMAGICK);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Imagick was requested');

		$watermarker->engineForMime('image/png');
	}

	/**
	 * Imagick is an alternative engine, not dead code: where it is installed it must still
	 * watermark, and its output must be interchangeable with GD's — same dimensions, same
	 * format, ink actually drawn. The two are not pixel-identical by design, so the assertion
	 * is on equivalence rather than on a checksum.
	 */
	public function testBothEnginesProduceAWatermarkedImage(): void {
		$source = $this->createImage('image/png', 'png', 400, 300);
		$engines = [ImageWatermarker::ENGINE_GD];
		if (class_exists('Imagick')) {
			$engines[] = ImageWatermarker::ENGINE_IMAGICK;
		}

		foreach ($engines as $engine) {
			$dest = $this->tmpDir . "/engine_$engine.png";
			(new ImageWatermarker($engine))->apply($source, $dest, $this->makeConfig('text'), ['username' => 'Alice']);

			$info = getimagesize($dest);
			$this->assertNotFalse($info, "$engine produced an invalid image");
			$this->assertSame([400, 300], [$info[0], $info[1]], "$engine changed the dimensions");
			$this->assertGreaterThan(0, $this->changedPixels($source, $dest), "$engine drew no watermark");
		}

		$this->assertNotEmpty($engines);
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

	/**
	 * The darkest channel value anywhere in a PNG.
	 *
	 * On a white page with black text at 50% opacity this reads 126 where one glyph was
	 * drawn and 62 where two were drawn on top of each other, which is what makes tile
	 * overlap measurable rather than a matter of opinion.
	 */
	private function darkestPixel(string $path): int {
		$img = imagecreatefrompng($path);
		$darkest = 255;
		for ($x = 0; $x < imagesx($img); $x++) {
			for ($y = 0; $y < imagesy($img); $y++) {
				$darkest = min($darkest, imagecolorat($img, $x, $y) & 0xFF);
			}
		}
		imagedestroy($img);
		return $darkest;
	}

	/**
	 * Longest unbroken horizontal run of inked pixels — a proxy for how physically large
	 * the drawn glyphs are, which total ink no longer is now that the tile count falls as
	 * the type size rises.
	 */
	private function longestInkRun(string $path): int {
		$img = imagecreatefrompng($path);
		$longest = 0;
		for ($y = 0; $y < imagesy($img); $y++) {
			$run = 0;
			for ($x = 0; $x < imagesx($img); $x++) {
				$run = (imagecolorat($img, $x, $y) & 0xFF) < 250 ? $run + 1 : 0;
				$longest = max($longest, $run);
			}
		}
		imagedestroy($img);
		return $longest;
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
