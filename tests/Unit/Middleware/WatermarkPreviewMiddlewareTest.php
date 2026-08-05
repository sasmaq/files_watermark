<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Middleware;

use OCA\FilesWatermark\Middleware\WatermarkPreviewMiddleware;
use OCA\FilesWatermark\Preview\PreviewRequestContext;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IPreview;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The half of the preview path that produces the image.
 *
 * The behaviour worth pinning here is not "a watermark is drawn" - the service is tested
 * for that - it is **that nothing watermarked can be cached and nothing clean can escape**.
 * Core's preview cache is keyed by file id and dimensions and never by viewer, so a
 * per-viewer image reaching it is served to the next person to open the folder with the
 * first person's name on it; and a failure that fell back to core's clean preview would
 * publish a readable copy of a protected file's first page to anyone who can list the
 * folder. Both are silent, and both are one edit away.
 */
class WatermarkPreviewMiddlewareTest extends TestCase {

	private PreviewRequestContext $context;
	private WatermarkService&MockObject $watermarkService;
	private IPreview&MockObject $preview;
	private WatermarkPreviewMiddleware $middleware;

	protected function setUp(): void {
		parent::setUp();

		// The real context: it is a value holder, and mocking it would assert the calls
		// made rather than the state they leave behind.
		$this->context = new PreviewRequestContext();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->preview = $this->createMock(IPreview::class);

		$this->middleware = new WatermarkPreviewMiddleware(
			$this->context,
			$this->watermarkService,
			$this->preview,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function controller(): Controller {
		return $this->createMock(Controller::class);
	}

	private function afterController(Response $response): Response {
		return $this->middleware->afterController($this->controller(), 'getPreview', $response);
	}

	/** A 1×1 PNG - small, real, and something GD would accept. */
	private function png(): string {
		return (string)base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		);
	}

	private function recordAPreviewOf(File $file, int $width = 256, int $height = 256): void {
		$this->context->record($file, $width, $height);
	}

	private function cleanPreview(string $bytes, string $mime = 'image/png'): ISimpleFile&MockObject {
		$simple = $this->createMock(ISimpleFile::class);
		$simple->method('getContent')->willReturn($bytes);
		$simple->method('getMimeType')->willReturn($mime);
		return $simple;
	}

	public function testARequestThatIsNotAPreviewPassesStraightThrough(): void {
		$original = new Response();
		$this->preview->expects($this->never())->method('getPreview');

		$this->assertSame($original, $this->afterController($original));
	}

	public function testAMarkedFilesPreviewIsReplacedWithAStampedOne(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/report.pdf');
		$this->recordAPreviewOf($file);

		$this->preview->method('getPreview')->willReturn($this->cleanPreview($this->png()));
		$this->watermarkService->expects($this->once())
			->method('watermarkPreviewImage')
			->willReturnCallback(static function ($file, string $src, string $dst): void {
				file_put_contents($dst, 'STAMPED');
			});

		$response = $this->afterController(new Response());

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame('STAMPED', $response->render());
		$this->assertSame('image/png', $response->getHeaders()['Content-Type']);
	}

	/**
	 * **The response must be uncacheable, everywhere.**
	 *
	 * It names the person looking at it, so a shared proxy or a second account on the same
	 * browser profile must not be able to produce it again. Re-rendering on every scroll is
	 * the cheaper mistake by a wide margin.
	 */
	public function testTheStampedPreviewIsNotCacheableByAnybody(): void {
		$file = $this->createMock(File::class);
		$this->recordAPreviewOf($file);
		$this->preview->method('getPreview')->willReturn($this->cleanPreview($this->png()));
		$this->watermarkService->method('watermarkPreviewImage')->willReturnCallback(
			static fn ($f, string $src, string $dst) => file_put_contents($dst, 'STAMPED'),
		);

		$headers = $this->afterController(new Response())->getHeaders();

		$this->assertStringContainsString('no-store', $headers['Cache-Control']);
		$this->assertStringContainsString('private', $headers['Cache-Control']);
	}

	/**
	 * **A failure serves nothing at all.**
	 *
	 * The tempting fallback - hand back the response core produced - publishes the clean
	 * preview of a file whose whole point is that nobody reads it unnamed. A 404 shows the
	 * generic file-type icon, which is a visible, harmless failure.
	 */
	public function testAFailedStampServesNothingRatherThanTheCleanPreview(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/report.pdf');
		$this->recordAPreviewOf($file);
		$this->preview->method('getPreview')->willReturn($this->cleanPreview($this->png()));
		$this->watermarkService->method('watermarkPreviewImage')
			->willThrowException(new \RuntimeException('GD said no'));

		$response = $this->afterController(new Response());

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('', $response->render());
	}

	/** A preview core could not produce at all is the same story by a different route. */
	public function testAFailureToFetchTheCleanPreviewAlsoServesNothing(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/report.pdf');
		$this->recordAPreviewOf($file);
		$this->preview->method('getPreview')->willThrowException(new \RuntimeException('no provider'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->afterController(new Response())->getStatus());
	}

	/**
	 * A 304 says "reuse what you have", and what the client has came from here already.
	 * Replacing it with a fresh body would defeat the conditional request and re-render on
	 * every scroll of a folder the user has already seen.
	 */
	public function testANotModifiedResponseIsLeftAlone(): void {
		$this->recordAPreviewOf($this->createMock(File::class));
		$this->preview->expects($this->never())->method('getPreview');

		$original = new Response(Http::STATUS_NOT_MODIFIED);

		$this->assertSame($original, $this->afterController($original));
	}

	public function testANonOkResponseIsLeftAlone(): void {
		$this->recordAPreviewOf($this->createMock(File::class));
		$this->preview->expects($this->never())->method('getPreview');

		$original = new Response(Http::STATUS_NOT_FOUND);

		$this->assertSame($original, $this->afterController($original));
	}

	/**
	 * The watermark is scaled against the image core actually produced, not the size the
	 * client asked for.
	 *
	 * The request is a hint that core clamps against its own maxima and against the
	 * source's real size, so an unscaled request for 4096px can arrive as a 1024px image -
	 * and a watermark scaled to the request would be four times too large on it.
	 */
	public function testTheWatermarkIsScaledToTheImageThatWasActuallyProduced(): void {
		$file = $this->createMock(File::class);
		// Asked for 4096; core will hand back the 1×1 png below.
		$this->recordAPreviewOf($file, 4096, 4096);
		$this->preview->method('getPreview')->willReturn($this->cleanPreview($this->png()));

		$measured = null;
		$this->watermarkService->method('watermarkPreviewImage')->willReturnCallback(
			static function ($f, string $src, string $dst, int $shorterSide) use (&$measured): void {
				$measured = $shorterSide;
				file_put_contents($dst, 'STAMPED');
			},
		);

		$this->afterController(new Response());

		$this->assertSame(1, $measured, 'the watermark was scaled to the request rather than the image');
	}

	/** Whatever happens, the working copies do not outlive the request. */
	public function testTheWorkingCopiesAreSweptEvenWhenTheStampFails(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/alice/files/report.pdf');
		$this->recordAPreviewOf($file);
		$this->preview->method('getPreview')->willReturn($this->cleanPreview($this->png()));

		$seen = null;
		$this->watermarkService->method('watermarkPreviewImage')->willReturnCallback(
			static function ($f, string $src) use (&$seen): void {
				$seen = $src;
				throw new \RuntimeException('GD said no');
			},
		);

		$this->afterController(new Response());

		$this->assertNotNull($seen);
		$this->assertFileDoesNotExist($seen, 'a plaintext preview copy outlived the failed render');
		$this->assertDirectoryDoesNotExist(dirname($seen));
	}
}
