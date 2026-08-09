<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\InstanceTimeZone;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * {@see InstanceTimeZone} - reading `default_timezone` out of `config.php`.
 *
 * The rule is the one every read on the delivery path follows: **nothing an admin can type
 * into `config.php` reaches the renderer as an exception.** A watermark stamped an hour out
 * is a nuisance; a download that answers 500 because of a typo is an outage.
 *
 * @covers \OCA\FilesWatermark\Service\InstanceTimeZone
 */
class InstanceTimeZoneTest extends TestCase {

	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function timeZone(): InstanceTimeZone {
		return new InstanceTimeZone($this->config, $this->logger);
	}

	public function testTheConfiguredZoneIsUsed(): void {
		$this->config->method('getSystemValueString')->willReturn('Asia/Aden');
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame('Asia/Aden', $this->timeZone()->get()->getName());
	}

	/** An admin's `config.php` line, as typed. */
	public function testSurroundingWhitespaceIsIgnored(): void {
		$this->config->method('getSystemValueString')->willReturn('  Europe/Berlin  ');

		$this->assertSame('Europe/Berlin', $this->timeZone()->get()->getName());
	}

	/**
	 * No `default_timezone` is the common case, and it must be a **no-op**: an instance that
	 * never configured one keeps rendering exactly what it rendered before this existed.
	 */
	public function testAnUnsetZoneFallsBackToPhpsDefault(): void {
		$this->config->method('getSystemValueString')->willReturnArgument(1);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(date_default_timezone_get(), $this->timeZone()->get()->getName());
	}

	public function testAnEmptyZoneIsTreatedAsUnset(): void {
		$this->config->method('getSystemValueString')->willReturn('   ');
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(date_default_timezone_get(), $this->timeZone()->get()->getName());
	}

	/**
	 * `new \DateTimeZone()` throws on an identifier PHP does not know - `Asia/Sanaa` is a
	 * plausible thing to write and is not one of them. It degrades, and it says so: a
	 * silently ignored timezone is a watermark that is quietly hours out.
	 */
	public function testAnUnknownZoneWarnsAndFallsBack(): void {
		$this->config->method('getSystemValueString')->willReturn('Asia/Sanaa');
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(date_default_timezone_get(), $this->timeZone()->get()->getName());
	}

	/** The key is part of the contract with `config.php`; a rename would make it inert. */
	public function testTheConfigKeyIsNextcloudsOwn(): void {
		$this->assertSame('default_timezone', InstanceTimeZone::KEY);
	}
}
