<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OC\Streamer;
use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\Dav\ZipInterceptorPlugin;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IDateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * @covers \OCA\FilesWatermark\Dav\ZipInterceptorPlugin
 */
class ZipInterceptorPluginTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private IEventDispatcher&MockObject $eventDispatcher;
	private Tree&MockObject $tree;
	private Server $server;

	/** @var string[] */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();
		Streamer::reset();

		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->tree = $this->createMock(Tree::class);
		$this->server = new Server();
		$this->server->tree = $this->tree;

		// The coarse gate: on by default so tests exercise the per-member logic.
		$this->watermarkService->method('hasDeliveryTriggerConfigured')->willReturn(true);
		$this->watermarkService->method('isSupported')->willReturn(true);
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $path) {
			if (file_exists($path)) {
				@unlink($path);
				@rmdir(dirname($path));
			}
		}
		Streamer::reset();
		parent::tearDown();
	}

	private function plugin(bool $publicContext = false): ZipInterceptorPlugin {
		$plugin = new ZipInterceptorPlugin(
			$this->watermarkService,
			$this->createMock(IDateTimeZone::class),
			$this->eventDispatcher,
			$this->createMock(LoggerInterface::class),
			$publicContext,
		);
		$plugin->initialize($this->server);
		return $plugin;
	}

	private function file(
		int $id,
		string $path,
		string $name,
		string $contents = 'ORIGINAL',
		int $size = 8,
	): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($path);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getSize')->willReturn($size);
		$file->method('getMTime')->willReturn(1700000000);
		$file->method('fopen')->willReturnCallback(static function () use ($contents) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, $contents);
			rewind($stream);
			return $stream;
		});
		return $file;
	}

	/**
	 * @param array<int, File|Folder> $children
	 */
	private function folder(string $path, string $name, array $children): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getName')->willReturn($name);
		$folder->method('getMTime')->willReturn(1700000000);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	private function davDirectory(Folder $folder): DavDirectory&MockObject {
		$dav = $this->createMock(DavDirectory::class);
		$dav->method('getNode')->willReturn($folder);
		return $dav;
	}

	private function renderedCopy(string $contents = 'WATERMARKED'): string {
		$dir = sys_get_temp_dir() . '/nc_watermark_test_' . uniqid('', true);
		mkdir($dir);
		$path = $dir . '/copy.pdf';
		file_put_contents($path, $contents);
		$this->tmpFiles[] = $path;
		return $path;
	}

	private function zipRequest(string $path = 'files/bob/Shared', array $query = []): Request {
		// Sabre derives query parameters from the URL, so they belong in the URL.
		$url = '/' . $path . ($query === [] ? '' : '?' . http_build_query($query));
		$request = new Request('GET', $url);
		$request->setHeader('Accept', 'application/zip');
		return $request;
	}

	// ---------------------------------------------------------------------
	// The regression this plugin exists for.
	// ---------------------------------------------------------------------

	/**
	 * A shared *single file* is mounted inside the recipient's own home, so the
	 * containing folder reports owner access while the member itself is a received
	 * share. Gating on the container leaked the clean original for exactly this case.
	 */
	public function testGatesPerMemberNotPerContainer(): void {
		$shared = $this->file(1, '/bob/files/Shared/secret.pdf', 'secret.pdf');
		$own = $this->file(2, '/bob/files/Shared/mine.pdf', 'mine.pdf', 'MY-ORIGINAL');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$shared, $own]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));

		// The container answers "no trigger" — the old gate stopped right here.
		$this->watermarkService->method('deliveryTriggerFor')
			->willReturnCallback(static fn ($node) => $node->getId() === 1 ? 'on_share' : null);
		$this->watermarkService->method('watermarkForDownload')
			->willReturnCallback(fn ($file) => $file->getId() === 1 ? $this->renderedCopy() : null);

		$this->assertFalse($this->plugin()->httpGet($this->zipRequest(), new Response()));

		$members = Streamer::members();
		$this->assertSame('WATERMARKED', $members['/Shared/secret.pdf']['contents']);
		// The recipient's own file is untouched.
		$this->assertSame('MY-ORIGINAL', $members['/Shared/mine.pdf']['contents']);
	}

	public function testSubstitutedMemberReportsTheWatermarkedSize(): void {
		// Tar writes the size up front, so a stale original size corrupts the archive.
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf', 'ORIGINAL', size: 8);
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')
			->willReturn($this->renderedCopy('WATERMARKED'));

		$this->plugin()->httpGet($this->zipRequest(), new Response());

		$this->assertSame(strlen('WATERMARKED'), Streamer::members()['/Shared/a.pdf']['size']);
	}

	// ---------------------------------------------------------------------
	// Archive shape
	// ---------------------------------------------------------------------

	public function testWholeFolderDownloadNestsUnderTheFolderName(): void {
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')->willReturn($this->renderedCopy());

		$this->plugin()->httpGet($this->zipRequest(), new Response());

		// rootPath is dirname('/bob/files/Shared'), so members keep the folder prefix.
		$this->assertContains('Shared', Streamer::dirs());
		$this->assertArrayHasKey('/Shared/a.pdf', Streamer::members());
	}

	public function testSelectionDownloadIsFlatAndNamedDownload(): void {
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$davChild = $this->createMock(DavFile::class);
		$davChild->method('getNode')->willReturn($file);

		$davDir = $this->davDirectory($folder);
		$davDir->expects($this->once())->method('getChild')->with('a.pdf')->willReturn($davChild);

		$this->tree->method('getNodeForPath')->willReturn($davDir);
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')->willReturn($this->renderedCopy());

		$request = $this->zipRequest(query: ['files' => '["a.pdf"]']);
		$this->plugin()->httpGet($request, new Response());

		// A selection is flat: rootPath is the folder itself and no root dir entry is added.
		$this->assertSame([], Streamer::dirs());
		$this->assertArrayHasKey('/a.pdf', Streamer::members());
	}

	public function testMemberFilterCanComeFromTheXNcFilesHeader(): void {
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file, $this->file(2, '/bob/files/Shared/b.pdf', 'b.pdf')]);

		$davChild = $this->createMock(DavFile::class);
		$davChild->method('getNode')->willReturn($file);

		$davDir = $this->davDirectory($folder);
		$davDir->expects($this->once())->method('getChild')->with('a.pdf')->willReturn($davChild);

		$this->tree->method('getNodeForPath')->willReturn($davDir);
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')->willReturn($this->renderedCopy());

		$request = new Request('GET', '/files/bob/Shared');
		$request->setHeader('Accept', 'application/zip');
		$request->setHeader('X-NC-Files', 'a.pdf');
		$this->plugin()->httpGet($request, new Response());

		// Only the selected member is archived.
		$this->assertSame(['/a.pdf'], array_keys(Streamer::members()));
	}

	public function testAcceptQueryParameterOverridesTheHeader(): void {
		// Browser folder-download links cannot set headers, so ?accept= must work alone.
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')->willReturn($this->renderedCopy());

		$request = new Request('GET', '/files/bob/Shared?accept=zip');

		$this->assertFalse($this->plugin()->httpGet($request, new Response()));
	}

	public function testNonArchiveRequestIsLeftToCore(): void {
		$folder = $this->folder('/bob/files/Shared', 'Shared', []);
		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));

		// A plain GET on a directory is not an archive request.
		$this->assertTrue($this->plugin()->httpGet(new Request('GET', '/files/bob/Shared'), new Response()));
	}

	public function testMalformedMemberFilterIsLeftToCore(): void {
		$folder = $this->folder('/bob/files/Shared', 'Shared', []);
		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));

		// A non-string entry: let core produce its own complaint rather than guessing.
		$request = $this->zipRequest(query: ['files' => '[{"not":"a string"}]']);
		$this->assertTrue($this->plugin()->httpGet($request, new Response()));
	}

	// ---------------------------------------------------------------------
	// Deferral and vetoes
	// ---------------------------------------------------------------------

	public function testDefersToCoreWhenNoTriggerIsConfiguredAtAll(): void {
		$service = $this->createMock(WatermarkService::class);
		$service->method('hasDeliveryTriggerConfigured')->willReturn(false);
		$service->expects($this->never())->method('watermarkForDownload');

		$plugin = new ZipInterceptorPlugin(
			$service,
			$this->createMock(IDateTimeZone::class),
			$this->eventDispatcher,
			$this->createMock(LoggerInterface::class),
		);
		$plugin->initialize($this->server);

		$folder = $this->folder('/bob/files/Shared', 'Shared', []);
		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));

		$this->assertTrue($plugin->httpGet($this->zipRequest(), new Response()));
	}

	public function testDefersToCoreWhenNothingWasSubstituted(): void {
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		// Owner access: no member needs substituting, so core's archive is identical.
		$this->watermarkService->method('deliveryTriggerFor')->willReturn(null);

		$this->assertTrue($this->plugin()->httpGet($this->zipRequest(), new Response()));
		$this->assertSame([], Streamer::members());
	}

	public function testBeforeZipCreatedVetoIsHonoured(): void {
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf')]);
		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));

		$this->eventDispatcher->method('dispatchTyped')
			->willReturnCallback(static function (BeforeZipCreatedEvent $event): void {
				$event->setSuccessful(false);
				$event->setErrorMessage('archive downloads are disabled here');
			});

		// Taking over the request must not silently bypass another app's veto.
		$this->expectException(Forbidden::class);
		$this->expectExceptionMessage('archive downloads are disabled here');
		$this->plugin()->httpGet($this->zipRequest(), new Response());
	}

	// ---------------------------------------------------------------------
	// Caps
	// ---------------------------------------------------------------------

	public function testExceedingTheByteCapDeniesUnderOnShare(): void {
		// One member over MAX_BYTES (256 MiB) is enough to trip the cap.
		$file = $this->file(1, '/bob/files/Shared/huge.pdf', 'huge.pdf', size: 268435457);
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$this->expectException(Forbidden::class);
		$this->plugin()->httpGet($this->zipRequest(), new Response());
	}

	public function testExceedingTheByteCapDegradesToAPlainArchiveUnderOnDownload(): void {
		$file = $this->file(1, '/bob/files/Shared/huge.pdf', 'huge.pdf', size: 268435457);
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_download');

		// Best-effort trigger: hand back to core rather than failing the download.
		$this->assertTrue($this->plugin()->httpGet($this->zipRequest(), new Response()));
		$this->assertSame([], Streamer::members());
	}

	public function testExceedingTheMemberCapDeniesUnderOnShare(): void {
		$children = [];
		for ($i = 1; $i <= 201; $i++) {
			$children[] = $this->file($i, "/bob/files/Shared/f$i.pdf", "f$i.pdf", size: 1);
		}
		$folder = $this->folder('/bob/files/Shared', 'Shared', $children);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')
			->willReturnCallback(fn () => $this->renderedCopy());

		$this->expectException(Forbidden::class);
		$this->plugin()->httpGet($this->zipRequest(), new Response());
	}

	// ---------------------------------------------------------------------
	// Failed renders
	// ---------------------------------------------------------------------

	public function testOnShareDeniesWhenAMemberCannotBeRendered(): void {
		$file = $this->file(1, '/bob/files/Shared/broken.pdf', 'broken.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);
		$this->watermarkService->method('deliveryTrigger')->willReturn('on_share');

		// Denied before a single byte goes out, so this is a clean 403 rather than a
		// truncated archive containing the clean original.
		$this->expectException(Forbidden::class);
		try {
			$this->plugin()->httpGet($this->zipRequest(), new Response());
		} finally {
			$this->assertSame([], Streamer::members());
		}
	}

	public function testNestedFoldersAreWalkedDepthFirst(): void {
		$nested = $this->file(2, '/bob/files/Shared/sub/deep.pdf', 'deep.pdf');
		$sub = $this->folder('/bob/files/Shared/sub', 'sub', [$nested]);
		$top = $this->file(1, '/bob/files/Shared/top.pdf', 'top.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$top, $sub]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')
			->willReturnCallback(fn () => $this->renderedCopy());

		$this->plugin()->httpGet($this->zipRequest(), new Response());

		// A file nested below the top level must be watermarked too.
		$members = Streamer::members();
		$this->assertSame('WATERMARKED', $members['/Shared/sub/deep.pdf']['contents']);
		$this->assertSame('WATERMARKED', $members['/Shared/top.pdf']['contents']);
	}

	public function testTempCopiesAreCleanedUpAfterStreaming(): void {
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);
		$tmp = $this->renderedCopy();

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')->willReturn($tmp);

		$this->plugin()->httpGet($this->zipRequest(), new Response());

		$this->assertFileDoesNotExist($tmp, 'the rendered temp copy must not outlive the request');
	}

	public function testAfterGetSuppressesSabresOwnResponseOnlyWhenHandled(): void {
		$plugin = $this->plugin();
		// Nothing handled yet: Sabre must send its normal response.
		$this->assertTrue($plugin->afterGet($this->zipRequest(), new Response()));

		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);
		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')->willReturn($this->renderedCopy());

		$plugin->httpGet($this->zipRequest(), new Response());

		// The archive went straight to the output buffer, so Sabre must stay quiet.
		$this->assertFalse($plugin->afterGet($this->zipRequest(), new Response()));
	}
}
