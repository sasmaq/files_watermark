<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\Files_Trashbin\Sabre\ITrash;
use OCA\FilesWatermark\Dav\DownloadInterceptorPlugin;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\IFile;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * @covers \OCA\FilesWatermark\Dav\DownloadInterceptorPlugin
 */
class DownloadInterceptorPluginTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;
	private Tree&MockObject $tree;
	private Server $server;

	/** @var string[] temp files to clean up (the plugin defers its own to shutdown) */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
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

	private function plugin(): DownloadInterceptorPlugin {
		$plugin = new DownloadInterceptorPlugin($this->watermarkService, $this->rootFolder);
		$plugin->initialize($this->server);
		return $plugin;
	}

	/**
	 * A trashbin DAV node, and the file the root folder resolves its id to.
	 *
	 * The two are separate objects on purpose, which is the whole shape of the trash case:
	 * the node the request addresses is not a file node at all and cannot hand over its
	 * content, so the file id is the only thing carrying across.
	 */
	private function trashNode(int $fileId = 42, string $mime = 'application/pdf'): ITrash&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn('report.pdf.d1700000000');
		$this->rootFolder->method('getById')->with($fileId)->willReturn([$file]);

		$node = $this->trashMock();
		$node->method('getFileId')->willReturn($fileId);
		return $node;
	}

	/**
	 * `ITrash` does not extend `INode` - core's trash nodes get there by implementing both
	 * (`AbstractTrashFile extends AbstractTrash implements IFile, ITrash`). The mock has to
	 * do the same, or it is not a node the DAV tree could ever have returned.
	 *
	 * @return ITrash&MockObject
	 */
	private function trashMock(): ITrash&MockObject {
		return $this->createMockForIntersectionOfInterfaces([ITrash::class, IFile::class]);
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
			->with($davFile->getNode())
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

	/**
	 * A failed render on a marked file denies the download - it does not fall back.
	 *
	 * This used to depend on the trigger: `on_share` denied and `on_download` served the
	 * clean original as a best-effort. The fallback is gone, and this is the test that says
	 * so. Serving the stored bytes when the watermark could not be drawn hands the clean
	 * file to exactly the reader the mark exists to name, and it does it *silently*, at the
	 * moment the app is least able to explain itself.
	 */
	public function testAFailedRenderDeniesRatherThanServingTheOriginal(): void {
		$davFile = $this->davFile();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')
			->willThrowException(new WatermarkRequiredException('/report.pdf'));

		$this->expectException(Forbidden::class);
		$this->plugin()->httpGet($this->request(), new Response());
	}

	public function testAnUnmarkedFileIsHandedBackToCore(): void {
		$davFile = $this->davFile();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		// Null means "not marked", which is now the *only* thing it means.
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);

		$response = new Response();
		$this->assertTrue($this->plugin()->httpGet($this->request(), $response));
		$this->assertNull($response->getHeader('Content-Disposition'));
	}

	/**
	 * The owner gets the same treatment as everybody else.
	 *
	 * There is no exemption left to test *for*, which is the point: the watermark names
	 * whoever is reading, and an owner reading their own file is a reader. The plugin has
	 * no way to ask who is fetching, and that is the design rather than an omission.
	 */
	public function testTheOwnerIsWatermarkedLikeEveryOtherReader(): void {
		$davFile = $this->davFile();
		$tmpPath = $this->renderedCopy();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->willReturn($tmpPath);

		$this->assertFalse($this->plugin()->httpGet($this->request(), new Response()));
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
		// is pure waste - and it is the expensive half.
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

	/**
	 * Deleting a marked file used to be a way to download it clean.
	 *
	 * The trash is served by this same DAV server, so this hook always ran for it - and
	 * always returned early, because a trashed node is an `ITrash` and never an
	 * `OCA\DAV\Connector\Sabre\File`. The mark survives the delete (the trash moves a file,
	 * it does not copy it, so the file id is the same row's), and the trash view's *preview*
	 * was watermarked the whole time, which is what made the two disagree in public.
	 */
	public function testATrashedMarkedFileIsStillWatermarked(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->trashNode());
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->willReturn($this->renderedCopy());

		$response = new Response();
		$this->assertFalse(
			$this->plugin()->httpGet($this->request('trashbin/alice/trash/report.pdf.d1700000000'), $response),
		);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('WATERMARKED-BYTES', stream_get_contents($response->getBody()));
	}

	/**
	 * `TrashbinPlugin` adds its own Content-Disposition on `afterMethod:GET`, carrying the
	 * file's original name rather than the `.d<timestamp>` one storage gives it. Sabre's
	 * `addHeader` appends, so ours would not replace that header - it would be a second one.
	 */
	public function testTheTrashbinIsLeftToNameItsOwnDownload(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->trashNode());
		$this->watermarkService->method('watermarkForDownload')->willReturn($this->renderedCopy());

		$response = new Response();
		$this->plugin()->httpGet($this->request('trashbin/alice/trash/report.pdf.d1700000000'), $response);

		$this->assertNull($response->getHeader('Content-Disposition'));
		// The rest of the response is still ours.
		$this->assertSame('application/pdf', $response->getHeader('Content-Type'));
	}

	/** An unmarked file in the trash is served as stored, exactly as anywhere else. */
	public function testAnUnmarkedTrashedFileIsLeftToCore(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->trashNode());
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);

		$this->assertTrue(
			$this->plugin()->httpGet($this->request('trashbin/alice/trash/report.pdf.d1700000000'), new Response()),
		);
	}

	/**
	 * A trashed *folder* is an `ITrash` too, and resolves to a Folder rather than a File.
	 * Archive downloads are `ZipInterceptorPlugin`'s business; this one must not claim them.
	 */
	public function testATrashedFolderIsLeftToCore(): void {
		$node = $this->trashMock();
		$node->method('getFileId')->willReturn(99);
		$this->rootFolder->method('getById')->with(99)->willReturn([$this->createMock(Folder::class)]);
		$this->tree->method('getNodeForPath')->willReturn($node);

		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$this->assertTrue(
			$this->plugin()->httpGet($this->request('trashbin/alice/trash/folder.d1700000000'), new Response()),
		);
	}

	/** A trashed node whose id resolves to nothing at all - deleted from under us. */
	public function testATrashedNodeThatResolvesToNothingIsLeftToCore(): void {
		$node = $this->trashMock();
		$node->method('getFileId')->willReturn(99);
		$this->rootFolder->method('getById')->with(99)->willReturn([]);
		$this->tree->method('getNodeForPath')->willReturn($node);

		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$this->assertTrue(
			$this->plugin()->httpGet($this->request('trashbin/alice/trash/gone.d1700000000'), new Response()),
		);
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
		// Nothing may be registered on beforeMethod:GET - returning false there returns
		// before sendResponse and would ship a 0-byte download.
		$this->assertSame([], $this->server->listeners('beforeMethod:GET'));
	}
}
