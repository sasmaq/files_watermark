<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\ApplyLimits;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\FilesWatermark\Service\ApplyLimits
 * @covers \OCA\FilesWatermark\Service\ConfiguredLimits
 */
class ApplyLimitsTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function limits(): ApplyLimits {
		return new ApplyLimits($this->appConfig, $this->logger);
	}

	public function testUnsetKeyFallsBackToTheShippedDefault(): void {
		$this->appConfig->method('getValueInt')
			->willReturnCallback(static fn (string $app, string $key, int $default): int => $default);

		$this->assertSame(ApplyLimits::DEFAULT_MAX_BYTES, $this->limits()->maxBytes());
	}

	public function testAConfiguredValueIsUsed(): void {
		$this->appConfig->method('getValueInt')->willReturn(134217728);

		$this->assertSame(134217728, $this->limits()->maxBytes());
	}

	/** Read under the app's own id, which is what `occ config:app:set` writes against. */
	public function testTheKeyIsReadFromTheAppsOwnConfig(): void {
		$seen = [];
		$this->appConfig->method('getValueInt')
			->willReturnCallback(static function (string $app, string $key, int $default) use (&$seen): int {
				$seen[] = "$app:$key";
				return $default;
			});

		$this->limits()->maxBytes();

		$this->assertSame(['files_watermark:apply_max_bytes'], $seen);
	}

	/**
	 * There is no "unlimited" here either, and it matters more than it does for archives:
	 * removing this cap does not fill a disk, it lets one request exhaust a PHP worker's
	 * memory part-way through a destructive in-place write.
	 *
	 * @dataProvider unusableValueProvider
	 */
	public function testAnUnusableValueFallsBackToTheDefault(int $configured): void {
		$this->appConfig->method('getValueInt')->willReturn($configured);
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame(ApplyLimits::DEFAULT_MAX_BYTES, $this->limits()->maxBytes());
	}

	/** @return array<string, array{int}> */
	public static function unusableValueProvider(): array {
		return [
			'zero reads as "off", which this setting does not have' => [0],
			'negative' => [-1],
		];
	}

	/**
	 * `occ config:app:set --type=string` stores a value `getValueInt()` refuses to read.
	 * This is on the file-action path: a typo in an admin's command must not turn every
	 * apply into an HTTP 500.
	 */
	public function testAValueStoredWithTheWrongTypeFallsBackInsteadOfThrowing(): void {
		$this->appConfig->method('getValueInt')
			->willThrowException(new AppConfigTypeConflictException('conflict with value type from database'));
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame(ApplyLimits::DEFAULT_MAX_BYTES, $this->limits()->maxBytes());
	}

	/**
	 * The default is a memory bound, not a disk one, so it is deliberately far below the
	 * archive cap - a render holds several times the file's size at peak, against a
	 * `memory_limit` that is 512M on a stock Nextcloud. Pinned because the obvious
	 * "consistency" cleanup is to raise it to match {@see ArchiveLimits::DEFAULT_MAX_BYTES}.
	 */
	public function testTheDefaultIsSizedForMemoryRatherThanDisk(): void {
		$this->assertSame(64 * 1024 * 1024, ApplyLimits::DEFAULT_MAX_BYTES);
	}
}
