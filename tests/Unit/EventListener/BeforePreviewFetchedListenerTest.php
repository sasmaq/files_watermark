<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\BeforePreviewFetchedListener;
use OCA\FilesWatermark\Preview\PreviewRequestContext;
use OCA\FilesWatermark\Service\ShareAccess;
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
	private ShareAccess&MockObject $shareAccess;
	private PreviewRequestContext $context;
	private BeforePreviewFetchedListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->shareAccess = $this->createMock(ShareAccess::class);
		// The real one: it is a value holder, and mocking it would assert the calls made
		// rather than the state they leave behind - which is the whole of its contract.
		$this->context = new PreviewRequestContext();
		$this->listener = new BeforePreviewFetchedListener($this->watermarkService, $this->context, $this->shareAccess);
	}

	private function file(): File&MockObject {
		return $this->createMock(File::class);
	}

	private function event(\OCP\Files\Node $node, int $width = 256, int $height = 256): BeforePreviewFetchedEvent {
		return new BeforePreviewFetchedEvent($node, $width, $height, false);
	}

	/**
	 * Everything core was asked for is kept, so the middleware can ask for the same preview:
	 * regenerating a square of the shorter side, uncropped, served the Viewer half the width
	 * it requested and served the Files list uncropped thumbnails.
	 */
	public function testTheWholeRequestIsRecordedNotJustItsShorterSide(): void {
		$file = $this->file();
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(true);

		$this->listener->handle(new BeforePreviewFetchedEvent($file, 1920, 1080, true, 'cover', 'image/webp'));

		$this->assertSame(1920, $this->context->width());
		$this->assertSame(1080, $this->context->height());
		$this->assertTrue($this->context->crop());
		$this->assertSame('cover', $this->context->mode());
		$this->assertSame('image/webp', $this->context->mimeType());
	}

	/**
	 * **A version is judged by the file it is a version of.** Its own node lives under
	 * `files_versions` with an id no mark names, so asking about it found nothing and every
	 * version thumbnail went out clean.
	 */
	public function testAVersionPreviewIsDecidedByItsSourceFile(): void {
		$source = $this->file();
		$versionNode = $this->file();
		$versionNode->method('getMimetype')->willReturn('application/pdf');
		$this->context->noteVersionSource($source);

		$this->watermarkService->method('isDeliveryCandidate')
			->willReturnCallback(static fn ($node): bool => $node === $source);

		$this->listener->handle($this->event($versionNode));

		// The version's own node is what gets re-rendered; the source is what the watermark
		// is about.
		$this->assertSame($versionNode, $this->context->file());
		$this->assertSame($source, $this->context->subject());
	}

	public function testRecordsAMarkedFile(): void {
		$file = $this->file();
		$this->watermarkService->method('isDeliveryCandidate')->with($file)->willReturn(true);

		$this->listener->handle($this->event($file, 128, 256));

		$this->assertSame($file, $this->context->file());
		// The smaller side is what the watermark is scaled against.
		$this->assertSame(128, $this->context->shorterSide());
	}

	/**
	 * An unmarked file of a type this app watermarks is recorded *unstamped*: it can be
	 * marked later, and the middleware has to keep its clean preview from being cached past
	 * that.
	 */
	public function testAnUnmarkedWatermarkableFileIsRecordedButNotStamped(): void {
		$file = $this->file();
		$file->method('getMimetype')->willReturn('image/jpeg');
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(false);
		$this->watermarkService->method('isSupported')->with('image/jpeg')->willReturn(true);

		$this->listener->handle($this->event($file));

		$this->assertNull(
			$this->context->file(),
			'an unmarked file must never be offered for stamping, or the middleware stamps every thumbnail on the server',
		);
		$this->assertTrue($this->context->previewsCleanCopy());
		$this->assertFalse($this->context->throughShare());
	}

	/**
	 * A clean preview read through a share is one a switch can claim next time without its
	 * etag moving, so the middleware has to know.
	 */
	public function testACleanPreviewThroughAShareIsRecordedAsSuch(): void {
		$file = $this->file();
		$file->method('getMimetype')->willReturn('image/jpeg');
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(false);
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->shareAccess->method('isShareAccess')->with($file)->willReturn(true);

		$this->listener->handle($this->event($file));

		$this->assertTrue($this->context->previewsCleanCopy());
		$this->assertTrue($this->context->throughShare());
	}

	/** A stamped preview is never cached, so it does not need the question asked. */
	public function testAStampedPreviewDoesNotAskAboutShares(): void {
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(true);
		$this->shareAccess->expects($this->never())->method('isShareAccess');

		$this->listener->handle($this->event($this->file()));

		$this->assertFalse($this->context->throughShare());
	}

	public function testAFileThisAppCannotWatermarkIsNotRecorded(): void {
		$file = $this->file();
		$file->method('getMimetype')->willReturn('text/plain');
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(false);
		$this->watermarkService->method('isSupported')->willReturn(false);

		$this->listener->handle($this->event($file));

		$this->assertNull($this->context->file());
		$this->assertFalse($this->context->previewsCleanCopy());
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
