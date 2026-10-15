<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit;

use OCA\FilesWatermark\Capabilities;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\ApplyLimits;
use OCA\FilesWatermark\Service\ImageLimits;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\App\IAppManager;
use OCP\Capabilities\IInitialStateExcludedCapability;
use OCP\Capabilities\IPublicCapability;
use OCP\DB\Exception as DbException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The `files_watermark` entry in `/cloud/capabilities`, which native clients read the
 * trigger, the types and the limits from. See {@see Capabilities}.
 *
 * @covers \OCA\FilesWatermark\Capabilities
 */
class CapabilitiesTest extends TestCase {

	private IAppManager&MockObject $appManager;
	private WatermarkService&MockObject $watermarkService;
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppVersion')->with('files_watermark')->willReturn('2.1.0');
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')
			->willReturnCallback(static fn (string $app, string $key, int $default): int => $default);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function capabilities(): Capabilities {
		// The real limit classes over a mocked app config, so what is published is what
		// ConfiguredLimits resolves - fallback included - rather than whatever a mock says.
		return new Capabilities(
			$this->appManager,
			$this->watermarkService,
			new ApplyLimits($this->appConfig, $this->logger),
			new ImageLimits($this->appConfig, $this->logger),
			$this->logger,
		);
	}

	private function policy(string $trigger): void {
		$config = new WatermarkConfig();
		$config->setTrigger($trigger);
		$this->watermarkService->method('resolveConfig')->willReturn($config);
	}

	public function testPublishesTheClientContract(): void {
		$this->policy(WatermarkService::TRIGGER_ON_DEMAND);

		$this->assertSame([
			'files_watermark' => [
				'version' => '2.1.0',
				'api_version' => Capabilities::API_VERSION,
				'mimetypes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
				'apply_max_bytes' => ApplyLimits::DEFAULT_MAX_BYTES,
				'image_max_pixels' => ImageLimits::DEFAULT_MAX_PIXELS,
				'trigger' => 'on_demand',
			],
		], $this->capabilities()->getCapabilities());
	}

	/**
	 * Raw, as the web client gets it: a stale trigger from an older version goes out as it
	 * is stored, so a client that offers the actions only on `on_demand` hides them, as the
	 * web menu does. And without the warning `effectiveTrigger()` would log on every fetch.
	 *
	 * @dataProvider storedTriggerProvider
	 */
	public function testTheTriggerIsPublishedAsStored(string $stored): void {
		$this->policy($stored);
		$this->watermarkService->expects($this->never())->method('effectiveTrigger');

		$this->assertSame($stored, $this->capabilities()->getCapabilities()['files_watermark']['trigger']);
	}

	/** @return array<string, array{string}> */
	public static function storedTriggerProvider(): array {
		return [
			'on demand' => ['on_demand'],
			'on upload' => ['on_upload'],
			'a trigger this version no longer has' => ['on_download'],
		];
	}

	public function testTheLimitsInForceArePublished(): void {
		$this->policy(WatermarkService::TRIGGER_ON_DEMAND);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturnMap([
			['files_watermark', 'apply_max_bytes', ApplyLimits::DEFAULT_MAX_BYTES, false, 134217728],
			// Refused by ConfiguredLimits, so the default is what the server enforces.
			['files_watermark', 'image_max_pixels', ImageLimits::DEFAULT_MAX_PIXELS, false, 0],
		]);

		$published = $this->capabilities()->getCapabilities()['files_watermark'];

		$this->assertSame(134217728, $published['apply_max_bytes']);
		$this->assertSame(ImageLimits::DEFAULT_MAX_PIXELS, $published['image_max_pixels']);
	}

	/**
	 * Core does not catch what this throws, and the capabilities document is what every
	 * client logs in with. A policy that cannot be read costs the trigger, not the server.
	 */
	public function testAnUnreadablePolicyLeavesOnlyTheTriggerOut(): void {
		$this->watermarkService->method('resolveConfig')->willThrowException(new DbException('table is gone'));
		$this->logger->expects($this->once())->method('error');

		$published = $this->capabilities()->getCapabilities()['files_watermark'];

		$this->assertArrayNotHasKey('trigger', $published);
		$this->assertSame('2.1.0', $published['version']);
		$this->assertSame(Capabilities::API_VERSION, $published['api_version']);
	}

	public function testItIsForAccountsOnlyAndKeptOutOfThePageState(): void {
		$capabilities = $this->capabilities();

		$this->assertNotInstanceOf(IPublicCapability::class, $capabilities);
		$this->assertInstanceOf(IInitialStateExcludedCapability::class, $capabilities);
	}
}
