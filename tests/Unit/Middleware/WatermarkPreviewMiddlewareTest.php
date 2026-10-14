<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Middleware;

use OCA\Files_Versions\Controller\PreviewController as VersionsPreviewController;
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
use OCP\IRequest;
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

	/** @var array<string, string> the preview request's query string */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();

		// The real context: it is a value holder, and mocking it would assert the calls
		// made rather than the state they leave behind.
		$this->context = new PreviewRequestContext();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->preview = $this->createMock(IPreview::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default,
		);

		$this->middleware = new WatermarkPreviewMiddleware(
			$this->context,
			$this->watermarkService,
			$this->preview,
			$this->createMock(LoggerInterface::class),
			$request,
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
	 * **The preview re-fetched is the one core was asked for.** Both sides used to be the
	 * shorter one, with neither crop nor mode: the Viewer's 1920x1080 came back as a 1080 box
	 * and the Files list's cropped squares came back uncropped.
	 */
	public function testThePreviewIsRefetchedWithTheRequestsOwnShape(): void {
		$file = $this->createMock(File::class);
		$this->context->record($file, 1920, 1080, true, true, IPreview::MODE_COVER, 'image/webp');

		$this->preview->expects($this->once())
			->method('getPreview')
			->with($file, 1920, 1080, true, IPreview::MODE_COVER, 'image/webp')
			->willReturn($this->cleanPreview($this->png()));
		$this->watermarkService->method('watermarkPreviewImage')->willReturnCallback(
			static fn ($f, string $src, string $dst) => file_put_contents($dst, 'STAMPED'),
		);

		$this->afterController(new Response());
	}

	/** A request that named no size passes "no constraint", not a size of its own invention. */
	public function testARequestWithNoSizeAsksForCoresDefault(): void {
		$file = $this->createMock(File::class);
		$this->context->record($file, 0, 0);

		$this->preview->expects($this->once())
			->method('getPreview')
			->with($file, -1, -1, false, IPreview::MODE_FILL, null)
			->willReturn($this->cleanPreview($this->png()));
		$this->watermarkService->method('watermarkPreviewImage')->willReturnCallback(
			static fn ($f, string $src, string $dst) => file_put_contents($dst, 'STAMPED'),
		);

		$this->afterController(new Response());
	}

	/**
	 * **A version preview is noted against the file it belongs to**, from the version
	 * controller's own `file` argument, before that controller asks for the preview. Nothing
	 * on the version's node says which file it is a version of.
	 */
	public function testAVersionPreviewNotesItsSourceBeforeTheControllerRuns(): void {
		$source = $this->createMock(File::class);
		$userFolder = $this->createMock(\OCP\Files\Folder::class);
		$userFolder->method('get')->with('Docs/report.pdf')->willReturn($source);
		$rootFolder = $this->createMock(\OCP\Files\IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(\OCP\IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->params = ['file' => 'Docs/report.pdf', 'version' => '1700000000'];

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default,
		);
		$middleware = new WatermarkPreviewMiddleware(
			$this->context,
			$this->watermarkService,
			$this->preview,
			$this->createMock(LoggerInterface::class),
			$request,
			$rootFolder,
			$session,
		);

		$middleware->beforeController($this->createMock(VersionsPreviewController::class), 'getPreview');

		$this->assertSame($source, $this->context->versionSource());
	}

	/** Every other controller in the server is left exactly as it was. */
	public function testOnlyTheVersionControllerHasItsSourceNoted(): void {
		$this->params = ['file' => 'Docs/report.pdf'];

		$this->middleware->beforeController($this->controller(), 'getPreview');

		$this->assertNull($this->context->versionSource());
	}

	/** The watermark of a version names the file it is a version of, not the version node. */
	public function testAVersionPreviewIsStampedForItsSourceFile(): void {
		$versionNode = $this->createMock(File::class);
		$source = $this->createMock(File::class);
		$this->context->record($versionNode, 256, 256, true, null, null, null, $source);
		$this->preview->method('getPreview')->with($versionNode)->willReturn($this->cleanPreview($this->png()));

		$this->watermarkService->expects($this->once())
			->method('watermarkPreviewImage')
			->with($source)
			->willReturnCallback(static fn ($f, string $src, string $dst) => file_put_contents($dst, 'STAMPED'));

		$this->afterController(new Response());
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

	/** What core and Photos send with a clean preview: keep it a day, never ask again. */
	private function coresCleanResponse(int $status = Http::STATUS_OK): Response {
		$response = new Response($status);
		$response->addHeader('Cache-Control', 'private, max-age=86400, immutable');
		$response->addHeader('Expires', 'Tue, 13 Oct 2026 21:00:00 GMT');
		return $response;
	}

	/**
	 * Photos opens the Viewer on `/apps/photos/api/v1/preview/{id}?x=2048&y=2048`. Nothing in
	 * that address changes when the file is marked, so an `immutable` clean preview from
	 * before the mark was shown for a day after it.
	 */
	public function testAnUnversionedCleanPreviewOfAWatermarkableFileMustBeRevalidated(): void {
		$this->context->record($this->createMock(File::class), 2048, 2048, false);
		$this->preview->expects($this->never())->method('getPreview');
		$this->params = ['x' => '2048', 'y' => '2048'];

		$original = $this->coresCleanResponse();
		$response = $this->afterController($original);

		// Core's response, body and all - only how long it may be kept has changed.
		$this->assertSame($original, $response);
		$headers = $response->getHeaders();
		$this->assertSame('private, no-cache', $headers['Cache-Control']);
		$this->assertArrayNotHasKey('Expires', $headers);
	}

	/** @return array<string, array{string}> */
	public static function versionParamProvider(): array {
		return [
			'the Viewer and the Photos grid' => ['etag'],
			'core and the older Photos views' => ['c'],
			'the Files list' => ['v'],
		];
	}

	/**
	 * A URL carrying the etag changes when the file is marked, so `immutable` is honest there
	 * and is kept - otherwise every thumbnail of a Photos timeline revalidates on every visit.
	 *
	 * @dataProvider versionParamProvider
	 */
	public function testAVersionedCleanPreviewKeepsCoresCaching(string $param): void {
		$this->context->record($this->createMock(File::class), 256, 256, false);
		$this->params = ['x' => '256', $param => '65f4e7'];

		$headers = $this->afterController($this->coresCleanResponse())->getHeaders();

		$this->assertSame('private, max-age=86400, immutable', $headers['Cache-Control']);
	}

	/** A 304 has no lifetime of its own to correct; the stored response keeps the one it was given here. */
	public function testANotModifiedCleanPreviewIsLeftAlone(): void {
		$this->context->record($this->createMock(File::class), 2048, 2048, false);

		$original = $this->coresCleanResponse(Http::STATUS_NOT_MODIFIED);
		$headers = $this->afterController($original)->getHeaders();

		$this->assertSame('private, max-age=86400, immutable', $headers['Cache-Control']);
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
