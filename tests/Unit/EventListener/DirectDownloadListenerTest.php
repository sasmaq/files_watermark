<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\DAV\Events\BeforeFileDirectDownloadedEvent;
use OCA\FilesWatermark\EventListener\DirectDownloadListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;

/**
 * `/remote.php/direct/{token}` opens the stored file itself, so a protected file can only be
 * kept off it, not watermarked on it. These pin which files are kept off.
 *
 * @covers \OCA\FilesWatermark\EventListener\DirectDownloadListener
 */
class DirectDownloadListenerTest extends TestCase {

	private WatermarkService&MockObject $service;
	private DirectDownloadListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(WatermarkService::class);
		$this->listener = new DirectDownloadListener($this->service);
	}

	public function testAFileThatWouldBeWatermarkedIsRefused(): void {
		$file = $this->createMock(File::class);
		$this->service->method('isDeliveryCandidate')->with($file)->willReturn(true);

		$this->expectException(Forbidden::class);

		$this->listener->handle(new BeforeFileDirectDownloadedEvent($file));
	}

	/** Everything else streams exactly as core would: the listener has nothing to say. */
	public function testAnUnprotectedFileIsLeftAlone(): void {
		$file = $this->createMock(File::class);
		$this->service->method('isDeliveryCandidate')->willReturn(false);

		$this->listener->handle(new BeforeFileDirectDownloadedEvent($file));

		$this->addToAssertionCount(1);
	}

	public function testOtherEventsAreIgnored(): void {
		$this->service->expects($this->never())->method('isDeliveryCandidate');

		$this->listener->handle(new Event());
	}
}
