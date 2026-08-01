<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\ArchiveLimits;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\FilesWatermark\Service\ArchiveLimits
 */
class ArchiveLimitsTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function limits(): ArchiveLimits {
		return new ArchiveLimits($this->appConfig, $this->logger);
	}

	/** Nothing configured: the defaults the app shipped with. */
	public function testUnsetKeysFallBackToTheShippedDefaults(): void {
		$this->appConfig->method('getValueInt')
			->willReturnCallback(static fn (string $app, string $key, int $default): int => $default);

		$this->assertSame(ArchiveLimits::DEFAULT_MAX_MEMBERS, $this->limits()->maxMembers());
		$this->assertSame(ArchiveLimits::DEFAULT_MAX_BYTES, $this->limits()->maxBytes());
	}

	public function testConfiguredValuesAreUsed(): void {
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key): int => match ($key) {
				ArchiveLimits::KEY_MAX_MEMBERS => 500,
				ArchiveLimits::KEY_MAX_BYTES => 1073741824,
			},
		);

		$this->assertSame(500, $this->limits()->maxMembers());
		$this->assertSame(1073741824, $this->limits()->maxBytes());
	}

	/** Both keys are read under the app's own id, which is what `occ` writes against. */
	public function testTheKeysAreReadFromTheAppsOwnConfig(): void {
		$seen = [];
		$this->appConfig->method('getValueInt')
			->willReturnCallback(static function (string $app, string $key, int $default) use (&$seen): int {
				$seen[] = "$app:$key";
				return $default;
			});

		$this->limits()->maxMembers();
		$this->limits()->maxBytes();

		$this->assertSame([
			'files_watermark:archive_max_members',
			'files_watermark:archive_max_bytes',
		], $seen);
	}

	/**
	 * There is no "unlimited". The cap is the bound that keeps a fail-closed render from
	 * filling the temp filesystem, so a value that would remove it is refused and logged
	 * rather than honoured.
	 *
	 * @dataProvider unusableValueProvider
	 */
	public function testAnUnusableValueFallsBackToTheDefault(int $configured): void {
		$this->appConfig->method('getValueInt')->willReturn($configured);
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame(ArchiveLimits::DEFAULT_MAX_MEMBERS, $this->limits()->maxMembers());
	}

	/** @return array<string, array{int}> */
	public static function unusableValueProvider(): array {
		return [
			'zero reads as "off", which this setting does not have' => [0],
			'negative' => [-1],
		];
	}

	/**
	 * `occ config:app:set` stores untyped values as mixed, which reads back as an int
	 * fine — but `--type=string` does not, and this runs on the delivery path. A typo in
	 * an admin's command must not become an HTTP 500 on every folder download; the app
	 * has shipped that exact shape of bug once, from a mistyped system tag.
	 */
	public function testAValueStoredWithTheWrongTypeFallsBackInsteadOfThrowing(): void {
		$this->appConfig->method('getValueInt')
			->willThrowException(new AppConfigTypeConflictException('conflict with value type from database'));
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame(ArchiveLimits::DEFAULT_MAX_BYTES, $this->limits()->maxBytes());
	}
}
