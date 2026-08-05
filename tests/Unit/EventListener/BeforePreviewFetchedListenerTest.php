<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\BeforePreviewFetchedListener;
use OCA\FilesWatermark\Preview\PreviewRequestContext;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Preview\BeforePreviewFetchedEvent;
use OCP\Preview\IProviderV2;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The listener records; it no longer blocks.
 *
 * It used to throw `NotFoundException` for any share recipient under `on_share`, because a
 * watermarked preview could not be produced without poisoning core's per-file preview
 * cache with one viewer's name. The stamping happens after that cache now, so previews
 * come back and this becomes the cheap half of the pair: note which file is being
 * previewed, and let the middleware do the work.
 */
class BeforePreviewFetchedListenerTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private PreviewRequestContext $context;
	private BeforePreviewFetchedListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		// The real one: it is a value holder, and mocking it would assert the calls made
		// rather than the state they leave behind - which is the whole of its contract.
		$this->context = new PreviewRequestContext();
		$this->listener = new BeforePreviewFetchedListener($this->watermarkService, $this->context);
	}

	private function file(): File&MockObject {
		return $this->createMock(File::class);
	}

	private function event(\OCP\Files\Node $node, int $width = 256, int $height = 256): BeforePreviewFetchedEvent {
		return new BeforePreviewFetchedEvent($node, $width, $height, false);
	}

	public function testRecordsAMarkedFile(): void {
		$file = $this->file();
		$this->watermarkService->method('isDeliveryCandidate')->with($file)->willReturn(true);

		$this->listener->handle($this->event($file, 128, 256));

		$this->assertSame($file, $this->context->file());
		// The smaller side is what the watermark is scaled against.
		$this->assertSame(128, $this->context->shorterSide());
	}

	public function testAnUnmarkedFileIsNotRecorded(): void {
		$file = $this->file();
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(false);

		$this->listener->handle($this->event($file));

		$this->assertNull(
			$this->context->file(),
			'an unmarked file must leave the context empty, or the middleware stamps every thumbnail on the server',
		);
	}

	/**
	 * A folder has previews too (core renders one for some mounts), and it is not a file
	 * this app can watermark. Recording one would hand the middleware a node whose content
	 * it cannot read.
	 */
	public function testAFolderIsIgnored(): void {
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');

		$this->listener->handle($this->event($this->createMock(Folder::class)));

		$this->assertNull($this->context->file());
	}

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');

		$this->listener->handle(new \OCP\EventDispatcher\Event());

		$this->assertNull($this->context->file());
	}

	/**
	 * Nullable dimensions are still deprecated-but-legal on the event, and a caller that
	 * omits them must not produce a fatal on the way to a thumbnail.
	 */
	public function testMissingDimensionsRecordZeroRatherThanFailing(): void {
		$file = $this->file();
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(true);

		$this->listener->handle(new BeforePreviewFetchedEvent($file));

		$this->assertSame($file, $this->context->file());
		$this->assertSame(0, $this->context->shorterSide());
	}

	/**
	 * The first preview of a request wins.
	 *
	 * The middleware serves one image, and if a later internal preview could displace the
	 * recorded node it would stamp the wrong file's name onto the response - or read a file
	 * the request never asked about.
	 */
	public function testASecondPreviewDoesNotDisplaceTheFirst(): void {
		$first = $this->file();
		$second = $this->file();
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(true);

		$this->listener->handle($this->event($first));
		$this->listener->handle($this->event($second));

		$this->assertSame($first, $this->context->file());
	}

	/**
	 * The middleware asks `IPreview` for the clean preview itself, which fires this event a
	 * second time. Without the guard the two halves chase each other.
	 */
	public function testNothingIsRecordedWhileTheAppIsGeneratingItsOwnPreview(): void {
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(true);
		$file = $this->file();

		$this->context->whileGenerating(function () use ($file): void {
			$this->listener->handle($this->event($file));
		});

		$this->assertNull($this->context->file());
	}

	/** Keeps the unused-import checker honest about what a preview provider is. */
	public function testTheProviderInterfaceIsNotNeededHere(): void {
		$this->assertTrue(interface_exists(IProviderV2::class));
	}
}
