<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\Files_Trashbin\Sabre\ITrash;
use OCA\Files_Versions\Sabre\VersionFile;
use OCA\Files_Versions\Versions\IVersion;
use OCA\FilesWatermark\Dav\DownloadInterceptorPlugin;
use OCA\FilesWatermark\Dav\TooManyRendersException;
use OCA\FilesWatermark\Db\WatermarkRendition;
use OCA\FilesWatermark\Service\DeliveryPadder;
use OCA\FilesWatermark\Service\DeliveryReservation;
use OCA\FilesWatermark\Service\RenderRateLimitedException;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Photos\Sabre\Album\AlbumPhoto;
use OCA\Photos\Sabre\Album\PublicAlbumPhoto;
use OCA\Photos\Sabre\Place\PlacePhoto;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
	private DeliveryReservation&MockObject $reservation;
	private IUserSession&MockObject $userSession;
	private Server $server;

	/** @var string[] temp files to clean up (the plugin defers its own to shutdown) */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->tree = $this->createMock(Tree::class);
		$this->reservation = $this->createMock(DeliveryReservation::class);

		// A signed-in reader by default: the reservation path only runs for one, and the
		// public-link server (which has none) is its own case below.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

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

	private function plugin(?ShareAccess $shareAccess = null): DownloadInterceptorPlugin {
		$plugin = new DownloadInterceptorPlugin(
			$this->watermarkService,
			$this->rootFolder,
			$this->reservation,
			// The real padder: what it does to a file is the point of these tests, and it
			// has no collaborators of its own worth standing in for.
			new DeliveryPadder(new NullLogger()),
			$this->userSession,
			new NullLogger(),
			$shareAccess,
		);
		$plugin->initialize($this->server);
		return $plugin;
	}

	/** A standing reservation of $size bytes under $etag. */
	private function reservationOf(int $size, string $etag = 'wm-etag'): WatermarkRendition {
		$rendition = new WatermarkRendition();
		$rendition->setReservedSize($size);
		$rendition->setEtag($etag);
		return $rendition;
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
	private function davFile(
		string $mime = 'application/pdf',
		string $name = 'report.pdf',
		string $etag = 'abc123',
	): DavFile {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn($name);
		$file->method('getEtag')->willReturn($etag);

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
			"attachment; filename*=UTF-8''report.pdf; filename=\"report.pdf\"",
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
	 * `X-Sabre-Original-Method` is the marker Sabre leaves on the clone; the request that
	 * actually came in - `$server->httpRequest` - is the HEAD itself.
	 */
	public function testASabreHeadSubRequestIsLeftToCore(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		// Not merely "no row": no render either. A HEAD has no body, so rendering for one
		// is pure waste - and it is the expensive half.
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$this->server->httpRequest = new Request('HEAD', '/files/alice/report.pdf');
		$request = $this->request();
		$request->setHeader('X-Sabre-Original-Method', 'HEAD');

		$this->assertTrue($this->plugin()->httpGet($request, new Response()));
	}

	/**
	 * The marker is an ordinary header, and Sabre does not strip it from what a client
	 * sends. A plain GET that carries it used to be taken for the HEAD clone and handed to
	 * core unwatermarked and unlogged.
	 */
	public function testAGetForgingTheHeadMarkerIsStillWatermarked(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->willReturn($this->renderedCopy());

		$request = $this->request();
		$request->setHeader('X-Sabre-Original-Method', 'HEAD');
		$this->server->httpRequest = $request;

		$this->assertFalse($this->plugin()->httpGet($request, new Response()));
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

	/**
	 * A render that cannot be read back is a render that failed, and is refused like one.
	 *
	 * This used to return true - "leave it to core" - which for a file that is watermarked on
	 * download means core streaming the stored, clean original. Every other way a render can
	 * fail answers 403; this one quietly handed out exactly what the mark exists to withhold.
	 */
	public function testAnUnreadableRenderIsRefusedRatherThanLeftToCore(): void {
		$davFile = $this->davFile();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')
			->willReturn('/nonexistent/nc_watermark_gone/copy.pdf');

		$this->expectException(Forbidden::class);

		$this->plugin()->httpGet($this->request(), new Response());
	}

	/**
	 * A name that is not ASCII travels percent-encoded, in the RFC 5987 form browsers read,
	 * rather than as raw UTF-8 bytes in a header - which arrived as mojibake, or as
	 * `download`, depending on the browser.
	 */
	public function testANonAsciiNameIsOfferedInTheEncodedForm(): void {
		$this->assertSame(
			"attachment; filename*=UTF-8''%D8%AA%D9%82%D8%B1%D9%8A%D8%B1%20%22q3%22.pdf; filename=\"%D8%AA%D9%82%D8%B1%D9%8A%D8%B1%20%22q3%22.pdf\"",
			DownloadInterceptorPlugin::contentDisposition('تقرير "q3".pdf'),
		);
	}

	/**
	 * **Core's view-only check runs first.** `ViewOnlyPlugin` refuses a GET on a share whose
	 * download permission is off, at priority 90, and Sabre does not order equal priorities.
	 * At 90 this plugin could run first, return false, and serve the watermarked copy past
	 * the refusal. Registered strictly between it and the `CorePlugin` that serves the body.
	 */
	public function testTheGetHookRunsAfterCoresViewOnlyCheckAndBeforeCoreServes(): void {
		// A real Sabre server: its CorePlugin is what streams the stored body, at 100.
		$server = new Server(new \Sabre\DAV\SimpleCollection('root'));
		$plugin = $this->plugin();
		$server->addPlugin($plugin);

		// Core adds ViewOnlyPlugin on `beforeMethod:*`, i.e. *after* this plugin - the
		// registration order under which a tie used to be decided against it.
		$viewOnly = static fn (): bool => true;
		$server->on('method:GET', $viewOnly, 90);

		$order = [];
		foreach ($server->listeners('method:GET') as $index => $listener) {
			if ($listener === $viewOnly) {
				$order['viewOnly'] = $index;
			} elseif (is_array($listener) && $listener[0] === $plugin) {
				$order['watermark'] = $index;
			} elseif (is_array($listener) && $listener[0] instanceof \Sabre\DAV\CorePlugin) {
				$order['core'] = $index;
			}
		}

		asort($order);
		$this->assertSame(['viewOnly', 'watermark', 'core'], array_keys($order));
	}

	/**
	 * Core stamps the *stored* file's checksum on `afterMethod:GET`, over a body that is no
	 * longer the stored file. A sync client hashes what arrived, compares, and calls the
	 * download corrupt - so the header has to end up describing the bytes that went out.
	 */
	public function testChecksumDescribesTheDeliveredBytesNotTheStoredFile(): void {
		$davFile = $this->davFile();
		$tmpPath = $this->renderedCopy();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn($tmpPath);

		$plugin = $this->plugin();
		$request = $this->request();
		$response = new Response();

		$plugin->httpGet($request, $response);
		// What core's FilesPlugin does at priority 100, before we get our turn at 200.
		$response->addHeader('OC-Checksum', 'SHA1:' . sha1('THE-STORED-ORIGINAL'));
		$plugin->afterGet($request, $response);

		$this->assertSame(
			'SHA1:' . sha1('WATERMARKED-BYTES'),
			$response->getHeader('OC-Checksum'),
		);
		// `addHeader` appends rather than replaces, which is the whole reason this cannot be
		// set up front in httpGet: the stored file's value must be gone, not merely joined.
		$this->assertCount(1, $response->getHeaderAsArray('OC-Checksum'));
	}

	/**
	 * The etag names the *version of the file*, and that is the version PROPFIND advertised.
	 * Hashing the delivered bytes instead would change it on every fetch - `{datetime}` makes
	 * no two renders alike - and the client would read each one as a fresh server-side change
	 * and download forever.
	 */
	public function testEtagReportsTheStoredVersionRatherThanTheRender(): void {
		$davFile = $this->davFile(etag: 'deadbeef');
		$tmpPath = $this->renderedCopy();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn($tmpPath);

		$plugin = $this->plugin();
		$request = $this->request();
		$response = new Response();

		$plugin->httpGet($request, $response);
		$plugin->afterGet($request, $response);

		// Quoted, because that is the form `{DAV:}getetag` reports and the two are compared
		// against each other.
		$this->assertSame('"deadbeef"', $response->getHeader('ETag'));
	}

	/** A download nobody substituted must be described by core alone. */
	public function testAfterGetLeavesAnUnwatermarkedDownloadAlone(): void {
		$davFile = $this->davFile();
		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);

		$plugin = $this->plugin();
		$request = $this->request();
		$response = new Response();

		$this->assertTrue($plugin->httpGet($request, $response));

		$response->addHeader('OC-Checksum', 'SHA1:' . sha1('THE-STORED-ORIGINAL'));
		$plugin->afterGet($request, $response);

		$this->assertSame('SHA1:' . sha1('THE-STORED-ORIGINAL'), $response->getHeader('OC-Checksum'));
		$this->assertNull($response->getHeader('ETag'));
	}

	/**
	 * Registration order, asserted by letting a stand-in for core run at its real priority
	 * and checking who got the last word - the priority number itself is not readable back
	 * off the emitter, and the ordering is the thing that actually matters.
	 */
	public function testAfterGetOverrulesCoreRatherThanRacingIt(): void {
		$davFile = $this->davFile();
		$tmpPath = $this->renderedCopy();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn($tmpPath);

		$plugin = $this->plugin();
		$request = $this->request();
		$response = new Response();
		$plugin->httpGet($request, $response);

		// FilesPlugin::httpGet listens here at the default priority of 100.
		$this->server->on('afterMethod:GET', static function () use ($response): void {
			$response->addHeader('OC-Checksum', 'SHA1:' . sha1('THE-STORED-ORIGINAL'));
		}, 100);
		$this->server->emit('afterMethod:GET', [$request, $response]);

		$this->assertSame('SHA1:' . sha1('WATERMARKED-BYTES'), $response->getHeader('OC-Checksum'));
	}

	/**
	 * The clean copy of a file that could be marked must not be reused without asking.
	 *
	 * Core sends it with `Last-Modified` and no `Cache-Control`, which lets a browser reuse it
	 * for a tenth of the file's age - and the PDF viewer, loading the bare DAV URL, went on
	 * showing it after the file was marked.
	 */
	public function testACleanDownloadOfAWatermarkableFileMustBeRevalidated(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->method('isSupported')->with('application/pdf')->willReturn(true);
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);

		$plugin = $this->plugin();
		$request = $this->request();
		$response = new Response();

		$this->assertTrue($plugin->httpGet($request, $response));
		$plugin->afterGet($request, $response);

		$this->assertSame('private, no-cache', $response->getHeader('Cache-Control'));
		// Still core's download in every other respect.
		$this->assertNull($response->getHeader('ETag'));
	}

	public function testAWatermarkedDownloadMustBeRevalidatedToo(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('watermarkForDownload')->willReturn($this->renderedCopy());

		$plugin = $this->plugin();
		$request = $this->request();
		$response = new Response();

		$plugin->httpGet($request, $response);
		$plugin->afterGet($request, $response);

		$this->assertSame('private, no-cache', $response->getHeader('Cache-Control'));
	}

	/** A file this app never watermarks keeps whatever caching core gives it. */
	public function testAFileThatCannotBeMarkedIsLeftToCoresCaching(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile('text/plain', 'notes.txt'));
		$this->watermarkService->method('isSupported')->with('text/plain')->willReturn(false);
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);

		$plugin = $this->plugin();
		$request = $this->request('files/alice/notes.txt');
		$response = new Response();

		$plugin->httpGet($request, $response);
		$plugin->afterGet($request, $response);

		$this->assertNull($response->getHeader('Cache-Control'));
	}

	/** A structurally real PDF standing in for a render, so padding has somewhere to go. */
	private function renderedPdf(): string {
		$body = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
		$body .= "trailer\n<< /Size 2 >>\nstartxref\n9\n%%EOF\n";

		$dir = sys_get_temp_dir() . '/nc_watermark_test_' . uniqid('', true);
		mkdir($dir);
		$path = $dir . '/copy.pdf';
		file_put_contents($path, $body);
		$this->tmpFiles[] = $path;
		return $path;
	}

	/**
	 * The case the whole reservation mechanism exists for: a client that was told a length
	 * by PROPFIND gets a body of exactly that length, whatever this particular render weighed.
	 */
	public function testAStandingReservationIsPaddedUpToAndAnnouncedAsThePromisedLength(): void {
		$davFile = $this->davFile();
		$tmpPath = $this->renderedPdf();
		$promised = filesize($tmpPath) + 5000;

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn($tmpPath);
		$this->watermarkService->method('deliveryMime')->willReturn('application/pdf');
		$this->reservation->method('current')->willReturn($this->reservationOf($promised));
		// Nothing is re-measured when the promise still fits.
		$this->reservation->expects($this->never())->method('record');

		$response = new Response();
		$this->plugin()->httpGet($this->request(), $response);

		$this->assertSame((string)$promised, $response->getHeader('Content-Length'));
		$this->assertSame($promised, filesize($tmpPath), 'the body itself must be the promised length');
	}

	public function testAPaddedDownloadIsLabelledWithTheReservationsEtag(): void {
		$davFile = $this->davFile(etag: 'stored-etag');
		$tmpPath = $this->renderedPdf();

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn($tmpPath);
		$this->watermarkService->method('deliveryMime')->willReturn('application/pdf');
		$this->reservation->method('current')
			->willReturn($this->reservationOf(filesize($tmpPath) + 5000, 'reserved-etag'));

		$plugin = $this->plugin();
		$request = $this->request();
		$response = new Response();
		$plugin->httpGet($request, $response);
		$plugin->afterGet($request, $response);

		// The etag PROPFIND advertises for a reserved file is the reservation's, so the
		// download has to answer under the same one or the client sees a mismatch.
		$this->assertSame('"reserved-etag"', $response->getHeader('ETag'));
	}

	/**
	 * Nothing has measured this file yet, so there is no length to live up to. The body goes
	 * out as rendered - a browser is perfectly happy - and the measurement is taken so the
	 * *next* fetch has a promise to keep.
	 */
	public function testAColdReservationServesTheRenderAndMeasuresItForNextTime(): void {
		$davFile = $this->davFile(etag: 'stored-etag');
		$tmpPath = $this->renderedPdf();
		$rendered = filesize($tmpPath);

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn($tmpPath);
		$this->watermarkService->method('deliveryMime')->willReturn('application/pdf');
		$this->reservation->method('current')->willReturn(null);
		$this->reservation->expects($this->once())
			->method('record')
			->with($davFile->getNode(), 'alice', $this->anything(), $rendered, 'application/pdf')
			->willReturn($rendered + 4096);

		$plugin = $this->plugin();
		$request = $this->request();
		$response = new Response();
		$plugin->httpGet($request, $response);
		$plugin->afterGet($request, $response);

		$this->assertSame((string)$rendered, $response->getHeader('Content-Length'));
		$this->assertSame($rendered, filesize($tmpPath), 'a cold download must not be padded');
		// And crucially *not* the etag just reserved: this body is not the reserved length,
		// so labelling it with that etag would tell the client a lie it would act on.
		$this->assertSame('"stored-etag"', $response->getHeader('ETag'));
	}

	/**
	 * A render can outgrow the promise made from an earlier one. Serving it padded is
	 * impossible and serving it as though it fit would be a lie, so the promise is revised.
	 */
	public function testARenderOverItsReservationIsRemeasuredRatherThanForced(): void {
		$davFile = $this->davFile(etag: 'stored-etag');
		$tmpPath = $this->renderedPdf();
		$rendered = filesize($tmpPath);

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn($tmpPath);
		$this->watermarkService->method('deliveryMime')->willReturn('application/pdf');
		// A promise from back when this file rendered smaller.
		$this->reservation->method('current')->willReturn($this->reservationOf($rendered - 20));
		$this->reservation->expects($this->once())
			->method('record')
			->willReturn($rendered + 4096);

		$response = new Response();
		$this->plugin()->httpGet($this->request(), $response);

		$this->assertSame((string)$rendered, $response->getHeader('Content-Length'));
		$this->assertSame($rendered, filesize($tmpPath), 'the file must be left as rendered');
	}

	/**
	 * The public-link server has no reader to measure for, and virtual files never reach it.
	 * Asking the reservation table anything there would be a query with nothing to do.
	 */
	public function testAPublicDownloadWithNoReaderSkipsReservationsEntirely(): void {
		$davFile = $this->davFile(etag: 'stored-etag');
		$tmpPath = $this->renderedPdf();

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->tree->method('getNodeForPath')->willReturn($davFile);
		$this->watermarkService->method('watermarkForDownload')->willReturn($tmpPath);
		$this->reservation->expects($this->never())->method('current');
		$this->reservation->expects($this->never())->method('record');

		$plugin = new DownloadInterceptorPlugin(
			$this->watermarkService,
			$this->rootFolder,
			$this->reservation,
			new DeliveryPadder(new NullLogger()),
			$userSession,
		);
		$plugin->initialize($this->server);

		$response = new Response();
		$plugin->httpGet($this->request(), $response);

		$this->assertSame((string)filesize($tmpPath), $response->getHeader('Content-Length'));
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

	// -----------------------------------------------------------------------
	// Old versions
	// -----------------------------------------------------------------------
	//
	// `/remote.php/dav/versions/...` is served by this same server, and every version of a
	// marked file is a clean copy of it. The plugin used to recognise only file and trash
	// nodes, so a version download - offered in the Versions tab to the owner and to every
	// share recipient - went out as stored.

	/**
	 * A version node, the source file it belongs to, and that source as the reader sees it.
	 *
	 * @return array{0: VersionFile&MockObject, 1: File&MockObject}
	 */
	private function versionNode(string $versionBytes = 'OLD-VERSION-BYTES'): array {
		$ownersView = $this->createMock(File::class);
		$ownersView->method('getId')->willReturn(42);

		$version = $this->createMock(IVersion::class);
		$version->method('getSourceFile')->willReturn($ownersView);

		$node = $this->createMock(VersionFile::class);
		$node->method('getVersion')->willReturn($version);
		$node->method('getContentType')->willReturn('application/pdf');
		$node->method('getSize')->willReturn(strlen($versionBytes));
		$node->method('getETag')->willReturn('1700000000');
		$node->method('get')->willReturnCallback(static function () use ($versionBytes) {
			$handle = fopen('php://memory', 'r+');
			fwrite($handle, $versionBytes);
			rewind($handle);
			return $handle;
		});

		// The recipient's own view of the same file id - a shared mount, which is what the
		// share switches ask about.
		$readersView = $this->createMock(File::class);
		$readersView->method('getId')->willReturn(42);
		$readersView->method('getPath')->willReturn('/alice/files/Shared/report.pdf');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(42)->willReturn([$readersView]);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$this->tree->method('getNodeForPath')->willReturn($node);

		return [$node, $readersView];
	}

	public function testAVersionOfAProtectedFileIsServedWatermarked(): void {
		[$node, $readersView] = $this->versionNode();
		$tmpPath = $this->renderedCopy('WATERMARKED-VERSION');

		$this->watermarkService->expects($this->once())
			->method('watermarkVersionForDownload')
			->with(
				// Decided against the reader's view of the file, not the owner's.
				$readersView,
				$this->callback(static function (callable $open): bool {
					return stream_get_contents($open()) === 'OLD-VERSION-BYTES';
				}),
				'application/pdf',
				strlen('OLD-VERSION-BYTES'),
			)
			->willReturn($tmpPath);

		$response = new Response();
		$this->assertFalse($this->plugin()->httpGet($this->request('versions/alice/versions/42/1700000000'), $response));

		$this->assertSame('WATERMARKED-VERSION', stream_get_contents($response->getBody()));
		$this->assertSame((string)strlen('WATERMARKED-VERSION'), $response->getHeader('Content-Length'));
		// `files_versions` names the download itself on afterMethod:GET; a second header here
		// would be appended next to it rather than replace it.
		$this->assertNull($response->getHeader('Content-Disposition'));
	}

	public function testAVersionOfAnUnprotectedFileIsLeftToCore(): void {
		$this->versionNode();
		$this->watermarkService->method('watermarkVersionForDownload')->willReturn(null);

		$this->assertTrue($this->plugin()->httpGet($this->request('versions/alice/versions/42/1700000000'), new Response()));
	}

	public function testAVersionThatCannotBeWatermarkedIsRefused(): void {
		$this->versionNode();
		$this->watermarkService->method('watermarkVersionForDownload')
			->willThrowException(new WatermarkRequiredException('/alice/files/report.pdf', 'no'));

		$this->expectException(Forbidden::class);

		$this->plugin()->httpGet($this->request('versions/alice/versions/42/1700000000'), new Response());
	}

	/**
	 * A source this reader cannot resolve still has its mark asked by id: a lookup that
	 * failed must not be the way a marked file's version slips out.
	 */
	public function testAVersionOfAMarkedFileTheReaderCannotResolveIsRefused(): void {
		$ownersView = $this->createMock(\OCP\Files\FileInfo::class);
		$ownersView->method('getId')->willReturn(42);
		$version = $this->createMock(IVersion::class);
		$version->method('getSourceFile')->willReturn($ownersView);
		$node = $this->createMock(VersionFile::class);
		$node->method('getVersion')->willReturn($version);
		$this->tree->method('getNodeForPath')->willReturn($node);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->watermarkService->method('isMarked')->with(42)->willReturn(true);

		$this->expectException(Forbidden::class);

		$this->plugin()->httpGet($this->request('versions/alice/versions/42/1700000000'), new Response());
	}

	// -----------------------------------------------------------------------
	// Photos collections
	// -----------------------------------------------------------------------

	/** A file owned by $owner, as Photos resolves it through the album owner's folder. */
	private function photoFile(string $owner = 'bob'): File&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($owner);

		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('image/png');
		$file->method('getName')->willReturn('pic.png');
		$file->method('getOwner')->willReturn($user);
		return $file;
	}

	/** @param File[] $readersView what the reader's own folder resolves the photo's id to */
	private function readerSees(array $readersView): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(1471)->willReturn($readersView);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
	}

	/**
	 * A collaborator on a shared album gets the album owner's file - and, for the first time,
	 * a watermark on it.
	 *
	 * Photos serves `sharedalbums/...` from the same DAV server with `fopen()` on the stored
	 * file, and its node was neither a DAV file nor a trash node, so every marked photo in a
	 * shared album went to the collaborators clean. The album is also a share the storage
	 * cannot see, which the internal-share switch has to be told.
	 */
	public function testAPhotoInAnAlbumSharedWithTheReaderIsWatermarked(): void {
		$owners = $this->photoFile('bob');
		$node = $this->createMock(AlbumPhoto::class);
		$node->method('getFileId')->willReturn(1471);
		$node->method('getFileInfo')->willReturn($owners);
		$this->tree->method('getNodeForPath')->willReturn($node);
		$this->readerSees([]);

		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($owners)
			->willReturn($this->renderedCopy());

		$shareAccess = new ShareAccess($this->userSession);
		$response = new Response();

		$this->assertFalse($this->plugin($shareAccess)->httpGet(
			$this->request('photos/alice/sharedalbums/Alb (bob)/1471-pic.png'),
			$response,
		));
		$this->assertSame('WATERMARKED-BYTES', stream_get_contents($response->getBody()));

		$ownStorage = $this->createMock(\OCP\Files\Storage\IStorage::class);
		$ownStorage->method('instanceOfStorage')->willReturn(false);
		$onOwnersStorage = $this->createMock(\OCP\Files\FileInfo::class);
		$onOwnersStorage->method('getStorage')->willReturn($ownStorage);
		$this->assertTrue(
			$shareAccess->isInternalShareAccess($onOwnersStorage),
			'an album shared with the reader is a share, though the storage cannot say so',
		);
	}

	/**
	 * A reader who reaches the photo in their own folder is read through it - their own file,
	 * or a share mounted for them, which the storage then reports by itself.
	 */
	public function testAPhotoTheReaderCanReachIsReadThroughTheirOwnFolder(): void {
		$readers = $this->photoFile('alice');
		$node = $this->createMock(PlacePhoto::class);
		$node->method('getFileId')->willReturn(1471);
		$node->expects($this->never())->method('getFileInfo');
		$this->tree->method('getNodeForPath')->willReturn($node);
		$this->readerSees([$readers]);

		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($readers)
			->willReturn($this->renderedCopy());

		$shareAccess = new ShareAccess($this->userSession);
		$this->assertFalse($this->plugin($shareAccess)->httpGet(
			$this->request('photos/alice/places/Paris/1471-pic.png'),
			new Response(),
		));

		$ownStorage = $this->createMock(\OCP\Files\Storage\IStorage::class);
		$ownStorage->method('instanceOfStorage')->willReturn(false);
		$own = $this->createMock(\OCP\Files\FileInfo::class);
		$own->method('getStorage')->willReturn($ownStorage);
		$this->assertFalse($shareAccess->isInternalShareAccess($own), 'the reader\'s own photo is not a share');
	}

	/** A public album link is a public link, whoever happens to be signed in. */
	public function testAPhotoInAPublicAlbumIsAPublicLink(): void {
		$owners = $this->photoFile('bob');
		$node = $this->createMock(PublicAlbumPhoto::class);
		$node->method('getFileId')->willReturn(1471);
		$node->method('getFileInfo')->willReturn($owners);
		$this->tree->method('getNodeForPath')->willReturn($node);

		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($owners)
			->willReturn($this->renderedCopy());

		$shareAccess = new ShareAccess($this->userSession);
		$this->plugin($shareAccess)->httpGet($this->request('photospublic/TOKEN/1471-pic.png'), new Response());

		$this->assertTrue($shareAccess->isExternalShareAccess());
	}

	public function testAMarkedPhotoThatCannotBeResolvedIsRefused(): void {
		$node = $this->createMock(AlbumPhoto::class);
		$node->method('getFileId')->willReturn(1471);
		$node->method('getFileInfo')->willThrowException(new NotFound('Photo not found for user'));
		$this->tree->method('getNodeForPath')->willReturn($node);
		$this->readerSees([]);
		$this->watermarkService->method('isMarked')->with(1471)->willReturn(true);

		$this->expectException(Forbidden::class);

		$this->plugin()->httpGet($this->request('photos/alice/albums/Alb/1471-pic.png'), new Response());
	}

	public function testAnUnmarkedPhotoThatCannotBeResolvedIsLeftToPhotos(): void {
		$node = $this->createMock(AlbumPhoto::class);
		$node->method('getFileId')->willReturn(1471);
		$node->method('getFileInfo')->willThrowException(new NotFound('Photo not found for user'));
		$this->tree->method('getNodeForPath')->willReturn($node);
		$this->readerSees([]);
		$this->watermarkService->method('isMarked')->with(1471)->willReturn(false);

		$this->assertTrue($this->plugin()->httpGet($this->request('photos/alice/albums/Alb/1471-pic.png'), new Response()));
	}

	// -----------------------------------------------------------------------
	// Anonymous render limit
	// -----------------------------------------------------------------------

	/**
	 * Past the limit the answer is a 429 that says when to come back - not a 403, which a
	 * client reads as permanent, and never the stored file.
	 */
	public function testAnAnonymousAddressPastItsRenderLimitIsToldToComeBack(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->method('watermarkForDownload')
			->willThrowException(new RenderRateLimitedException(600));

		try {
			$this->plugin()->httpGet($this->request(), new Response());
			$this->fail('a rate-limited render must not be served or left to core');
		} catch (TooManyRendersException $e) {
			$this->assertSame(429, $e->getHTTPCode());
			$this->assertSame(['Retry-After' => '600'], $e->getHTTPHeaders($this->server));
		}
	}

	public function testAVersionPastTheRenderLimitIsToldToComeBackToo(): void {
		[$node] = $this->versionNode();
		$this->tree->method('getNodeForPath')->willReturn($node);
		$this->watermarkService->method('watermarkVersionForDownload')
			->willThrowException(new RenderRateLimitedException(600));

		$this->expectException(TooManyRendersException::class);

		$this->plugin()->httpGet($this->request('versions/alice/versions/42/1700000000'), new Response());
	}
}
