<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\ImageWatermarker;
use OCA\FilesWatermark\Service\ShapedText;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for {@see ImageWatermarker}. They run against the real image stack.
 *
 * **GD is the default engine**, so that is what these exercise on any host with the
 * extension - which is every host, in practice. Imagick is not left untested for that
 * reason: {@see testBothEnginesProduceAWatermarkedImage} drives it explicitly wherever it is
 * installed, and the selection rules themselves are covered exhaustively and
 * host-independently through {@see FakeImageStack}.
 *
 * Precision checks (opacity/rotation) use PNG only, since JPEG/WEBP are lossy
 * and would introduce noise unrelated to the watermark.
 */
class ImageWatermarkerTest extends TestCase {

	/** Reordering, medial forms and a lam-alef ligature in one word. See {@see ShapedText}. */
	private const ARABIC_PROBE = 'الاختبار';

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
	 * A truncated upload reaches the renderer: the MIME type is read from the first bytes, so
	 * `engineForMime()` sees a PNG and hands it to GD, which cannot decode it and answers
	 * false. That handle used to be passed straight to `imagesx()`, `imagettftext()` and
	 * `imagepng()` - a run of warnings ending in an output file that is not an image. The
	 * failure has to be named, because this is the path an interrupted upload takes.
	 */
	public function testTruncatedSourceImageIsRefusedByName(): void {
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('This pins the GD path specifically.');
		}

		$source = $this->createImage('image/png', 'png', 200, 150);
		// Header kept, image data gone: still detected as a PNG, no longer decodable.
		file_put_contents($source, substr((string)file_get_contents($source), 0, 40));
		$this->assertSame('image/png', mime_content_type($source));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/could not decode/i');

		$this->withImageWarningsSilenced(fn () => $this->watermarker->apply(
			$source,
			$this->tmpDir . '/out.png',
			$this->makeConfig('text'),
			['username' => 'Alice'],
		));
	}

	/**
	 * The logo is the half that can be dropped: an unreadable one has always been skipped
	 * when its *type* was unsupported, and a corrupt file of a supported type now takes the
	 * same route rather than compositing a false handle. The text still has to arrive - a
	 * `combined` policy that silently produced an unwatermarked file would be the worst of
	 * the three outcomes.
	 */
	public function testUndecodableLogoIsDroppedAndTheTextStillRenders(): void {
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('This pins the GD path specifically.');
		}

		$source = $this->createImage('image/png', 'png', 400, 300);
		$logo = $this->createImage('image/png', 'png', 120, 90, [255, 0, 0], 'logo');
		file_put_contents($logo, substr((string)file_get_contents($logo), 0, 40));

		$dest = $this->tmpDir . '/corrupt_logo.png';
		$config = $this->makeConfig('combined');
		$config->setImagePath($logo);

		$this->withImageWarningsSilenced(fn () => $this->watermarker->apply($source, $dest, $config, ['username' => 'Alice']));

		$this->assertGreaterThan(
			0,
			$this->changedPixels($source, $dest),
			'the text half should still have been drawn without the logo',
		);
	}

	/**
	 * The regression test for tiles landing on top of each other.
	 *
	 * The image renderer used to step a fixed grid - `max(210, fontSize * 10)` across -
	 * that never measured the text, so the *default* `{username} - {date}` template
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
	 * Type size drives the lattice as well as the glyphs - the gap between repetitions
	 * scales with it - so a larger font means larger text in *fewer*, more widely spaced
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
		// GD is the default engine, and its bitmap-font fallback cannot rotate at all - so a
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

	/**
	 * The renderer transforms Arabic before drawing it, rather than passing code points
	 * straight to a backend that joins nothing.
	 *
	 * An image carries no text, so there is no glyph list to read back the way
	 * {@see PdfWatermarkerTest::testArabicIsDrawnAsShapedGlyphs} reads one. The assertion
	 * has to come from pixels, and it has to be one that *only* a shaping renderer can
	 * satisfy - "the output changed" is not, because unshaped Arabic renders differently
	 * too, just wrongly.
	 *
	 * The discriminator uses shaping's own non-idempotency. A second pass reads visual
	 * order as logical and reverses it, and a third puts it back:
	 *
	 *     shape(x)  =  shape(shape(shape(x)))       but  x  ≠  shape(shape(x))
	 *
	 * So the raw text and its twice-shaped form are two **different** strings that shape to
	 * the **same** glyphs. A renderer that shapes draws them identically; one that draws
	 * what it is handed cannot. Verified by mutation: deleting the `ShapedText::shape()`
	 * call from either engine path fails this.
	 */
	public function testArabicIsShapedBeforeItIsDrawn(): void {
		if ($this->findSystemFont() === null && !class_exists('Imagick')) {
			$this->markTestSkipped('No TrueType font available.');
		}

		$twiceShaped = ShapedText::shape(ShapedText::shape(self::ARABIC_PROBE));
		$this->assertNotSame(self::ARABIC_PROBE, $twiceShaped, 'the two inputs must differ for this to prove anything');
		$this->assertSame(ShapedText::shape(self::ARABIC_PROBE), ShapedText::shape($twiceShaped));

		$base = $this->createImage('image/png', 'png', 500, 360);

		$fromRaw = $this->tmpDir . '/ar_raw.png';
		$fromTwiceShaped = $this->tmpDir . '/ar_twice.png';
		$this->watermarker->apply($base, $fromRaw, $this->arabicConfig(self::ARABIC_PROBE), []);
		$this->watermarker->apply($base, $fromTwiceShaped, $this->arabicConfig($twiceShaped), []);

		$this->assertGreaterThan(0, $this->changedPixels($base, $fromRaw), 'nothing was drawn at all');
		$this->assertSame(
			md5_file($fromTwiceShaped),
			md5_file($fromRaw),
			'two inputs that shape identically rendered differently, so the shaping pass is not running',
		);
	}

	/**
	 * A placeholder value carrying one byte that is not UTF-8 must not cost the watermark
	 * its shaping.
	 *
	 * This is the production failure, at the level it was reported: Arabic drawn in
	 * isolated forms, left to right, in a valid PNG. The bad byte came from a display name
	 * - the kind of thing LDAP hands over after a latin-1 round trip - and because
	 * `preg_match('/…/u')` cannot scan a malformed subject, the guard that decides whether
	 * to shape read its `false` as "no". Nothing threw, nothing was logged.
	 *
	 * Asserted as an image, not as a string, because the string-level guard is one line
	 * away from being reintroduced somewhere else in the chain: what matters is that the
	 * pixels are the ones the clean name produces.
	 */
	public function testADirtyPlaceholderStillRendersAShapedWatermark(): void {
		$base = $this->createImage('image/png', 'png', 500, 360);
		$config = $this->arabicConfig(self::ARABIC_PROBE . ' {displayname}');

		$dirty = $this->tmpDir . '/ar_dirty.png';
		$clean = $this->tmpDir . '/ar_clean.png';
		$this->watermarker->apply($base, $dirty, $config, ['displayname' => "Ahmed\xD8"]);
		$this->watermarker->apply($base, $clean, $config, ['displayname' => 'Ahmed']);

		$this->assertGreaterThan(0, $this->changedPixels($base, $dirty), 'nothing was drawn at all');
		$this->assertSame(
			md5_file($clean),
			md5_file($dirty),
			'one byte that is not part of the text changed what was drawn, so the shaping pass was skipped',
		);
	}

	/**
	 * The same, measured rather than compared: unshaped Arabic is materially wider than
	 * shaped Arabic, because isolated letters do not overlap and lam-alef stays two glyphs.
	 * A render that came out at the unshaped width is the reported bug, whatever else about
	 * the image looks plausible.
	 */
	public function testTheDirtyRenderIsNotTheUnshapedWidth(): void {
		$font = ShapedText::bundledFontPath();
		$this->assertNotNull($font);

		$resolved = self::ARABIC_PROBE . ' ' . "Ahmed\xD8";
		$shapedBox = imagettfbbox(24, 0, $font, ShapedText::shape($resolved));
		$rawBox = imagettfbbox(24, 0, $font, $resolved);
		$this->assertNotFalse($shapedBox);
		$this->assertNotFalse($rawBox);

		$shapedWidth = abs($shapedBox[2] - $shapedBox[0]);
		$rawWidth = abs($rawBox[2] - $rawBox[0]);
		$this->assertLessThan(
			$rawWidth,
			$shapedWidth,
			'shaping a string containing a bad byte left it the width of unshaped text',
		);
	}

	/**
	 * Arabic must not quietly fall back to a Latin font or the bitmap font, both of which
	 * produce a valid image that no one can read. The bundled face is what gets used.
	 */
	public function testArabicDrawsWithTheBundledFont(): void {
		$this->assertNotNull(ShapedText::bundledFontPath());

		$base = $this->createImage('image/png', 'png', 500, 360);
		$dest = $this->tmpDir . '/ar_font.png';
		$this->watermarker->apply($base, $dest, $this->arabicConfig(self::ARABIC_PROBE), []);

		$latin = $this->tmpDir . '/latin_font.png';
		$this->watermarker->apply($base, $latin, $this->arabicConfig('Alice'), []);

		$this->assertGreaterThan(0, $this->changedPixels($base, $dest));
		$this->assertNotSame(md5_file($latin), md5_file($dest));
	}

	/**
	 * The shaped form is narrower than the raw one - lam-alef fuses two letters into one
	 * glyph and joined letters overlap where isolated ones do not. That difference is what
	 * the tile lattice is spaced from, so measuring the unshaped string would leave gaps.
	 */
	public function testShapingChangesTheMeasuredWidth(): void {
		$font = $this->findSystemFont();
		if ($font === null) {
			$this->markTestSkipped('No TrueType font available.');
		}
		$bundled = ShapedText::bundledFontPath();
		$this->assertNotNull($bundled);

		$rawBox = imagettfbbox(24, 0, $bundled, self::ARABIC_PROBE);
		$shapedBox = imagettfbbox(24, 0, $bundled, ShapedText::shape(self::ARABIC_PROBE));
		$this->assertNotFalse($rawBox);
		$this->assertNotFalse($shapedBox);

		$this->assertLessThan(
			$rawBox[2] - $rawBox[0],
			$shapedBox[2] - $shapedBox[0],
			'shaped Arabic should be narrower than the same letters drawn in isolation',
		);
	}

	private function arabicConfig(string $template): WatermarkConfig {
		$config = $this->makeConfig('text', 80, 45, 24, '#000000');
		$config->setTextTemplate($template);
		return $config;
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
	 * watermark, and its output must be interchangeable with GD's - same dimensions, same
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

	/**
	 * GD announces a file it cannot decode twice over: an E_WARNING as well as a false
	 * return. The warning is GD's and is not what the two tests above are about - they are
	 * about what the app does with the return value - so it is swallowed here rather than
	 * left to litter every run.
	 */
	private function withImageWarningsSilenced(callable $fn): void {
		set_error_handler(static fn (): bool => true);
		try {
			$fn();
		} finally {
			restore_error_handler();
		}
	}

	private function makeConfig(string $type, int $opacity = 80, int $rotation = 45, int $fontSize = 20, string $color = '#000000'): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setType($type);
		$config->setTextTemplate('{username}');
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
	 * Longest unbroken horizontal run of inked pixels - a proxy for how physically large
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
