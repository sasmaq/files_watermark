<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\ApplyLimits;
use OCA\FilesWatermark\Service\ImageLimits;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\FilesWatermark\Service\ImageLimits
 */
class ImageLimitsTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function limits(): ImageLimits {
		return new ImageLimits($this->appConfig, $this->logger);
	}

	public function testUnsetKeyFallsBackToTheShippedDefault(): void {
		$this->appConfig->method('getValueInt')
			->willReturnCallback(static fn (string $app, string $key, int $default): int => $default);

		$this->assertSame(ImageLimits::DEFAULT_MAX_PIXELS, $this->limits()->maxPixels());
	}

	public function testAConfiguredValueIsUsed(): void {
		$this->appConfig->method('getValueInt')->willReturn(80000000);

		$this->assertSame(80000000, $this->limits()->maxPixels());
	}

	public function testTheKeyIsReadFromTheAppsOwnConfig(): void {
		$seen = [];
		$this->appConfig->method('getValueInt')
			->willReturnCallback(static function (string $app, string $key, int $default) use (&$seen): int {
				$seen[] = "$app:$key";
				return $default;
			});

		$this->limits()->maxPixels();

		$this->assertSame(['files_watermark:image_max_pixels'], $seen);
	}

	/**
	 * @dataProvider unusableValueProvider
	 */
	public function testAnUnusableValueFallsBackToTheDefault(int $configured): void {
		$this->appConfig->method('getValueInt')->willReturn($configured);
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame(ImageLimits::DEFAULT_MAX_PIXELS, $this->limits()->maxPixels());
	}

	/** @return array<string, array{int}> */
	public static function unusableValueProvider(): array {
		return [
			'zero reads as "off", which this setting does not have' => [0],
			'negative' => [-1],
		];
	}

	public function testAValueStoredWithTheWrongTypeFallsBackInsteadOfThrowing(): void {
		$this->appConfig->method('getValueInt')
			->willThrowException(new AppConfigTypeConflictException('conflict with value type from database'));
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame(ImageLimits::DEFAULT_MAX_PIXELS, $this->limits()->maxPixels());
	}

	/**
	 * The default has to clear ordinary photography, or the guard becomes a bug report
	 * rather than a defence. A 24 MP camera frame and a full 8K image both pass; the
	 * 50 MP-and-up end does not, which is the line this number is chosen to draw.
	 *
	 * @dataProvider realWorldImageProvider
	 */
	public function testTheDefaultClearsOrdinaryPhotography(int $width, int $height, bool $allowed): void {
		$this->assertSame($allowed, $width * $height <= ImageLimits::DEFAULT_MAX_PIXELS);
	}

	/** @return array<string, array{int, int, bool}> */
	public static function realWorldImageProvider(): array {
		return [
			'24 MP camera frame' => [6000, 4000, true],
			'full 8K' => [7680, 4320, true],
			'A4 scan at 600 dpi' => [4960, 7016, true],
			'50 MP phone sensor' => [8160, 6120, false],
			'a real bomb' => [64000, 64000, false],
		];
	}

	/**
	 * Two settings, bounding two different things, and neither implies the other: a large
	 * PDF has no pixels, and a three-kilobyte PNG can declare four billion of them. Pinned
	 * because collapsing them into one number is the obvious-looking simplification.
	 */
	public function testThePixelCeilingIsSeparateFromTheByteCap(): void {
		$this->assertNotSame(ApplyLimits::KEY_MAX_BYTES, ImageLimits::KEY_MAX_PIXELS);
	}
}
