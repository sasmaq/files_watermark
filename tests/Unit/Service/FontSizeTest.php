<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\FontSize;
use PHPUnit\Framework\TestCase;

/**
 * The rule every renderer asks for its type size.
 *
 * Pinned on its own because the three callers - GD, Imagick and the PDF overlay - each draw
 * in a different unit and are tested against their output, where "the text got bigger" is
 * all a pixel count can say. The numbers live here.
 */
class FontSizeTest extends TestCase {

	private function config(bool $dynamic, int $fontSize = 40): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setFontSize($fontSize);
		$config->setDynamicFontSize($dynamic);
		return $config;
	}

	/** Off is the shipped default, and then the canvas does not matter at all. */
	public function testAFixedSizeIgnoresTheCanvas(): void {
		$config = $this->config(false, 40);

		$this->assertSame(40, FontSize::forCanvas($config, 595.0, 842.0));
		$this->assertSame(40, FontSize::forCanvas($config, 6000.0, 4000.0));
		$this->assertSame(40, FontSize::forCanvas($config, 64.0, 64.0));
	}

	/**
	 * The ratio was chosen so this holds: switching dynamic sizing on leaves the commonest
	 * document - A4, at the shipped 24pt - exactly as it was.
	 */
	public function testAnA4PageGetsTheShippedDefault(): void {
		$this->assertSame(24, FontSize::forCanvas($this->config(true), 595.276, 841.89));
	}

	/** And the configured size is not read, whatever it holds. */
	public function testADynamicSizeIgnoresTheConfiguredOne(): void {
		$this->assertSame(
			FontSize::forCanvas($this->config(true, 6), 1200.0, 800.0),
			FontSize::forCanvas($this->config(true, 120), 1200.0, 800.0),
		);
	}

	/** @dataProvider canvasProvider */
	public function testADynamicSizeFollowsTheShorterSide(float $width, float $height, int $expected): void {
		$this->assertSame($expected, FontSize::forCanvas($this->config(true), $width, $height));
	}

	/** @return array<string, array{float, float, int}> */
	public static function canvasProvider(): array {
		return [
			'A5 page' => [419.528, 595.276, 17],
			'A3 page' => [841.89, 1190.55, 34],
			'1080p frame' => [1920.0, 1080.0, 43],
			'12 MP photo' => [4000.0, 3000.0, 120],
			// Past the 120 a fixed size is clamped to: no ceiling, or the largest images -
			// the ones this exists for - would stop scaling.
			'24 MP photo' => [6000.0, 4000.0, 160],
		];
	}

	/** Orientation is not size: a landscape page carries the text of its portrait twin. */
	public function testOrientationDoesNotChangeTheSize(): void {
		$config = $this->config(true);

		$this->assertSame(
			FontSize::forCanvas($config, 595.276, 841.89),
			FontSize::forCanvas($config, 841.89, 595.276),
		);
	}

	/** A thumbnail-sized canvas bottoms out at the floor rather than at an unreadable size. */
	public function testATinyCanvasStopsAtTheFloor(): void {
		$this->assertSame(FontSize::MIN, FontSize::forCanvas($this->config(true), 64.0, 64.0));
	}
}
