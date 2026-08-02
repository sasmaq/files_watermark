<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\ShareGuardListener;
use OCA\FilesWatermark\Service\OriginalStore;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Refusing to share a preserved original.
 *
 * The DAV guard cannot reach this: a share is created from a path through the Files API,
 * and is then served from the public endpoint where the node is re-rooted so its path no
 * longer names the folder. Without this listener a public link hands out the clean
 * pre-watermark bytes of a file whose whole point was to be watermarked.
 */
class ShareGuardListenerTest extends TestCase {

	private OriginalStore&MockObject $originalStore;
	private ShareGuardListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->originalStore = $this->createMock(OriginalStore::class);
		$this->listener = new ShareGuardListener($this->originalStore);
	}

	public function testSharingAPreservedOriginalIsRefused(): void {
		$event = $this->event($this->createMock(File::class));
		$this->originalStore->method('isBackup')->willReturn(true);

		$this->listener->handle($event);

		$this->assertSame('This file cannot be shared.', $event->getError());
		// Core checks BOTH — `if ($event->isPropagationStopped() && $event->getError())`
		// in Share20\Manager. With the error alone the share is created regardless, so
		// this half of the assertion is the one that actually stops it.
		$this->assertTrue($event->isPropagationStopped());
	}

	public function testAnOrdinaryFileIsLeftAlone(): void {
		$event = $this->event($this->createMock(File::class));
		$this->originalStore->method('isBackup')->willReturn(false);

		$this->listener->handle($event);

		$this->assertNull($event->getError());
		$this->assertFalse($event->isPropagationStopped());
	}

	public function testAnUnresolvableNodeIsLeftToCore(): void {
		// Core is about to fail this share on its own terms, with a better message than
		// anything this listener could invent. Throwing from here would replace it.
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willThrowException(new NotFoundException());
		$event = new BeforeShareCreatedEvent($share);

		$this->listener->handle($event);

		$this->assertNull($event->getError());
		$this->assertFalse($event->isPropagationStopped());
	}

	public function testUnrelatedEventsAreIgnored(): void {
		$this->originalStore->expects($this->never())->method('isBackup');

		$this->listener->handle(new Event());
	}

	private function event(File $node): BeforeShareCreatedEvent {
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($node);
		return new BeforeShareCreatedEvent($share);
	}
}
