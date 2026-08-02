<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\Dav\DownloadInterceptorPlugin;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * @covers \OCA\FilesWatermark\Dav\DownloadInterceptorPlugin
 */
class DownloadInterceptorPluginTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private Tree&MockObject $tree;
	private Server $server;

	/** @var string[] temp files to clean up (the plugin defers its own to shutdown) */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->tree = $this->createMock(Tree::class);
		$this->server = new Server();
		$this->server->tree = $this->tree;
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $path) {
			if (file_exists($path)) {
				@unlink($path);
				@rmdir(dirname($path));
			}
		}
		parent::tearDown();
	}

	private function plugin(bool $publicContext = false): DownloadInterceptorPlugin {
		$plugin = new DownloadInterceptorPlugin($this->watermarkService, $publicContext);
		$plugin->initialize($this->server);
		return $plugin;
	}

	/** A DAV file node wrapping an OCP file with the given mime/name. */
	private function davFile(string $mime = 'application/pdf', string $name = 'report.pdf'): DavFile {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn($name);

		$davFile = $this->createMock(DavFile::class);
		$davFile->method('getNode')->willReturn($file);
		return $davFile;
	}

	/** A real temp file standing in for the rendered watermarked copy. */
	private function renderedCopy(string $contents = 'WATERMARKED-BYTES'): string {
		$dir = sys_get_temp_dir() . '/nc_watermark_test_' . uniqid('', true);
		mkdir($dir);
		$path = $dir . '/copy.pdf';
		file_put_contents($path, $contents);
		$this->tmpFiles[] = $path;
		return $path;
	}

	private function request(string $path = 'files/alice/report.pdf'): Request {
		return new Request('GET', '/' . $path);
	}

	public function testStreamsWatermarkedCopyAndStopsCorePlugin(): void {
		$davFile = $this->davFile();
		$tmpPath = $this->renderedCopy();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($davFile->getNode(), false)
			->willReturn($tmpPath);

		$response = new Response();
		$handled = $this->plugin()->httpGet($this->request(), $response);

		// false == "we served it", which is what keeps CorePlugin from sending the original.
		$this->assertFalse($handled);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('application/pdf', $response->getHeader('Content-Type'));
		$this->assertSame((string)strlen('WATERMARKED-BYTES'), $response->getHeader('Content-Length'));
		$this->assertSame(
			'attachment; filename="report.pdf"',
			$response->getHeader('Content-Disposition'),
		);

		// The body must be the watermarked bytes, not the original.
		$body = $response->getBody();
		$this->assertIsResource($body);
		$this->assertSame('WATERMARKED-BYTES', stream_get_contents($body));
	}

	public function testOnShareDeniesRatherThanServingTheOriginalWhenRenderFails(): void {
		$davFile = $this->davFile();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);
		$this->watermarkService->method('deliveryTrigger')->willReturn('on_share');

		$this->expectException(Forbidden::class);
		$this->plugin()->httpGet($this->request(), new Response());
	}

	public function testOnDownloadFallsBackToTheOriginalWhenRenderFails(): void {
		$davFile = $this->davFile();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);
		$this->watermarkService->method('deliveryTrigger')->willReturn('on_download');

		// Best-effort: true hands the request back to core, which serves the original.
		$this->assertTrue($this->plugin()->httpGet($this->request(), new Response()));
	}

	public function testOwnerFetchIsUntouched(): void {
		$davFile = $this->davFile();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		// No trigger applies for the owner, so nothing is rendered and nothing is denied.
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);
		$this->watermarkService->method('deliveryTrigger')->willReturn(null);

		$response = new Response();
		$this->assertTrue($this->plugin()->httpGet($this->request(), $response));
		$this->assertNull($response->getHeader('Content-Disposition'));
	}

	public function testPublicContextForcesShareTreatment(): void {
		$davFile = $this->davFile();
		$tmpPath = $this->renderedCopy();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		// The public-link plugin instance must pass $publicContext through, otherwise the
		// service cannot tell a link download from the owner's own fetch.
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($this->anything(), true)
			->willReturn($tmpPath);

		$this->assertFalse($this->plugin(publicContext: true)->httpGet($this->request(), new Response()));
	}

	/**
	 * The bug this guard exists for: the Files app's download sends **HEAD then GET**, and
	 * Sabre serves a HEAD by cloning the request as a GET and re-dispatching it. Both
	 * arrived here as downloads, so one click rendered the whole watermarked file twice
	 * and wrote two audit rows.
	 *
	 * `X-Sabre-Original-Method` is the marker Sabre leaves on the clone, and the only
	 * thing that distinguishes the two.
	 */
	public function testASabreHeadSubRequestIsLeftToCore(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		// Not merely "no row": no render either. A HEAD has no body, so rendering for one
		// is pure waste — and it is the expensive half.
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$request = $this->request();
		$request->setHeader('X-Sabre-Original-Method', 'HEAD');

		$this->assertTrue($this->plugin()->httpGet($request, new Response()));
	}

	public function testARealGetIsStillHandled(): void {
		// The control for the guard above: without the marker, nothing changes.
		$davFile = $this->davFile();
		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->willReturn($this->renderedCopy());

		$this->assertFalse($this->plugin()->httpGet($this->request(), new Response()));
	}

	public function testMissingNodeIsLeftToCore(): void {
		$this->tree->method('getNodeForPath')->willThrowException(new NotFound());
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$this->assertTrue($this->plugin()->httpGet($this->request(), new Response()));
	}

	public function testDirectoryRequestIsLeftToCore(): void {
		// Folder downloads belong to ZipInterceptorPlugin, not this one.
		$this->tree->method('getNodeForPath')->willReturn($this->createMock(DavDirectory::class));
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$this->assertTrue($this->plugin()->httpGet($this->request('files/alice/folder'), new Response()));
	}

	public function testUnreadableTempCopyFallsBackToCore(): void {
		$davFile = $this->davFile();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')
			->willReturn('/nonexistent/nc_watermark_gone/copy.pdf');

		$this->assertTrue($this->plugin()->httpGet($this->request(), new Response()));
	}

	public function testRegistersOnMethodGetAheadOfCorePlugin(): void {
		$this->plugin();

		// CorePlugin streams file bodies at priority 100; this must run before it, and on
		// `method:GET` rather than `beforeMethod:GET` so afterMethod still flushes the body.
		$this->assertNotEmpty($this->server->listeners('method:GET'));
		// Nothing may be registered on beforeMethod:GET — returning false there returns
		// before sendResponse and would ship a 0-byte download.
		$this->assertSame([], $this->server->listeners('beforeMethod:GET'));
	}
}
