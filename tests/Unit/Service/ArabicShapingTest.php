<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\ArabicShaping;
use OCA\FilesWatermark\Service\ShapedText;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * {@see ArabicShaping} - reading `arabic_shaping` from `occ`.
 *
 * The rule is {@see \OCA\FilesWatermark\Service\ConfiguredLimits}': **nothing an admin can
 * type reaches the renderer as anything but a valid mode.** This is read on the delivery
 * path, so a typo that threw would take out every watermarked download on the instance.
 *
 * @covers \OCA\FilesWatermark\Service\ArabicShaping
 */
class ArabicShapingTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function shaping(): ArabicShaping {
		return new ArabicShaping($this->appConfig, $this->logger);
	}

	public function testUnsetDefaultsToAuto(): void {
		// getValueString() answers with the default it was handed, which is what an unset key
		// looks like from here.
		$this->appConfig->method('getValueString')->willReturnArgument(2);

		$this->assertSame(ShapedText::MODE_AUTO, $this->shaping()->mode());
	}

	/** @return list<array{string, string}> */
	public static function acceptedProvider(): array {
		return [
			['auto', ShapedText::MODE_AUTO],
			['always', ShapedText::MODE_ALWAYS],
			['never', ShapedText::MODE_NEVER],
			// An admin's `occ` line, as typed.
			['  never  ', ShapedText::MODE_NEVER],
			['Always', ShapedText::MODE_ALWAYS],
			['NEVER', ShapedText::MODE_NEVER],
		];
	}

	/** @dataProvider acceptedProvider */
	public function testAcceptedValues(string $stored, string $expected): void {
		$this->appConfig->method('getValueString')->willReturn($stored);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame($expected, $this->shaping()->mode());
	}

	/**
	 * An unrecognised value degrades to `auto` **and says so**. Silence here would leave an
	 * admin who typed `off` watching a setting that reports nothing and does nothing.
	 */
	public function testUnknownValueWarnsAndFallsBack(): void {
		$this->appConfig->method('getValueString')->willReturn('off');
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(ShapedText::MODE_AUTO, $this->shaping()->mode());
	}

	public function testEmptyValueIsTheDefaultRatherThanAnError(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(ShapedText::MODE_AUTO, $this->shaping()->mode());
	}

	/** Stored with `--type=integer`, which `getValueString()` refuses rather than coerces. */
	public function testTypeConflictDegradesToTheDefault(): void {
		$this->appConfig->method('getValueString')->willThrowException(
			new AppConfigTypeConflictException('stored as an integer'),
		);
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(ShapedText::MODE_AUTO, $this->shaping()->mode());
	}

	/** The key an admin types is part of the contract; a rename would make the setting inert. */
	public function testTheConfigKeyIsPinned(): void {
		$this->assertSame('arabic_shaping', ArabicShaping::KEY_MODE);
	}
}
