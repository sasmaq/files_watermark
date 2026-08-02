<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OC\Streamer;
use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\Dav\ZipInterceptorPlugin;
use OCA\FilesWatermark\Service\ArchiveLimits;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
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

	private function plugin(bool $publicContext = false, ?ArchiveLimits $limits = null): ZipInterceptorPlugin {
		$plugin = new ZipInterceptorPlugin(
			$this->watermarkService,
			$this->createMock(IDateTimeZone::class),
			$this->eventDispatcher,
			$this->createMock(LoggerInterface::class),
			$limits ?? $this->limits(),
			$publicContext,
		);
		$plugin->initialize($this->server);
		return $plugin;
	}

	/**
	 * A real {@see ArchiveLimits} over a stubbed app config, so the caps the plugin sees
	 * are the ones an `occ config:app:set` would produce rather than a mocked answer.
	 */
	private function limits(?int $maxMembers = null, ?int $maxBytes = null): ArchiveLimits {
		$stored = array_filter([
			ArchiveLimits::KEY_MAX_MEMBERS => $maxMembers,
			ArchiveLimits::KEY_MAX_BYTES => $maxBytes,
		], static fn (?int $value): bool => $value !== null);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')
			->willReturnCallback(static fn (string $app, string $key, int $default): int => $stored[$key] ?? $default);

		return new ArchiveLimits($appConfig, $this->createMock(LoggerInterface::class));
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

	/**
	 * Every member's declared size must be the size of the bytes that member actually
	 * carries — the watermarked length for a substituted member, its own for one
	 * streamed untouched.
	 *
	 * **Tar is the case this exists for**, and it is why the archive type is a
	 * parameter rather than always zip: `TarStreamer` writes the size into the member
	 * header *before* the bytes, so a stale original size produces an archive that is
	 * structurally corrupt from that member onwards. `ZipStreamer` derives the size
	 * while streaming and would forgive the same mistake, so a zip-only test proves
	 * nothing about the format that cannot forgive it.
	 *
	 * @dataProvider archiveTypeProvider
	 */
	public function testEachMemberDeclaresTheSizeOfTheBytesItCarries(string $accept, bool $preferTar): void {
		$substituted = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf', 'ORIGINAL', size: 8);
		$untouched = $this->file(2, '/bob/files/Shared/b.pdf', 'b.pdf', 'PLAIN-ORIGINAL', size: 14);
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$substituted, $untouched]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')
			->willReturnCallback(static fn ($node) => $node->getId() === 1 ? 'on_share' : null);
		$this->watermarkService->method('watermarkForDownload')
			->willReturn($this->renderedCopy('WATERMARKED'));

		$request = $this->zipRequest();
		$request->setHeader('Accept', $accept);
		$this->plugin()->httpGet($request, new Response());

		$this->assertSame($preferTar, Streamer::$constructed[0]['preferTar'], 'wrong archive format');

		$members = Streamer::members();
		$this->assertSame(strlen('WATERMARKED'), $members['/Shared/a.pdf']['size']);
		// The other half, and the one a "always report filesize(tmp)" bug would break:
		// a member nobody rendered keeps its own size, not the last temp copy's.
		$this->assertSame(14, $members['/Shared/b.pdf']['size']);
	}

	/** @return array<string, array{string, bool}> */
	public static function archiveTypeProvider(): array {
		return [
			'zip' => ['application/zip', false],
			'tar' => ['application/x-tar', true],
		];
	}

	/**
	 * One render per watermarked member, and none for a member the policy skips.
	 *
	 * Each render is what writes an audit row, so this is where the archive's audit
	 * granularity is decided: a `watermark_log` row per *member*, not per archive. That
	 * is the intended behaviour — an entry that recorded only "an archive was
	 * downloaded" could not answer which documents were in it, which is the question a
	 * watermark exists to answer — so it is pinned here rather than left to whoever next
	 * reads the loop and thinks to batch it.
	 */
	public function testEachWatermarkedMemberIsRenderedOnceAndSkippedMembersNotAtAll(): void {
		$first = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$second = $this->file(2, '/bob/files/Shared/b.pdf', 'b.pdf');
		$skipped = $this->file(3, '/bob/files/Shared/c.pdf', 'c.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$first, $second, $skipped]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')
			->willReturnCallback(static fn ($node) => $node->getId() === 3 ? null : 'on_share');

		$rendered = [];
		$this->watermarkService->expects($this->exactly(2))
			->method('watermarkForDownload')
			->willReturnCallback(function ($file) use (&$rendered) {
				$rendered[] = $file->getId();
				return $this->renderedCopy();
			});

		$this->plugin()->httpGet($this->zipRequest(), new Response());

		$this->assertSame([1, 2], $rendered, 'the wrong members were rendered');
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

	// ---------------------------------------------------------------------
	// What the handler claims. It sits on `method:GET` ahead of core, so every
	// GET on the server passes through it, and anything it claims by mistake is
	// a request core never gets to answer.
	// ---------------------------------------------------------------------

	/**
	 * Only an archive-accepting GET is claimed — and the negative rows are set up
	 * with a member that *would* be substituted, so "not claimed" cannot pass merely
	 * because there was no work to do.
	 *
	 * @dataProvider acceptProvider
	 */
	public function testOnlyArchiveAcceptingGetsAreClaimed(?string $accept, ?bool $preferTar): void {
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')->willReturn($this->renderedCopy());

		$request = new Request('GET', '/files/bob/Shared');
		if ($accept !== null) {
			$request->setHeader('Accept', $accept);
		}

		$handled = $this->plugin()->httpGet($request, new Response()) === false;

		$this->assertSame($preferTar !== null, $handled, 'the request was claimed by the wrong plugin');
		if ($preferTar === null) {
			$this->assertSame([], Streamer::$constructed, 'an archive was built for a non-archive request');
			return;
		}
		$this->assertSame($preferTar, Streamer::$constructed[0]['preferTar']);
	}

	/** @return array<string, array{?string, ?bool}> */
	public static function acceptProvider(): array {
		return [
			// The shorthands are what core's own `?accept=` links carry.
			'zip' => ['application/zip', false],
			'zip shorthand' => ['zip', false],
			'tar' => ['application/x-tar', true],
			'tar shorthand' => ['tar', true],
			// A browser opening the folder, and a client that states nothing at all.
			'a page load' => ['text/html,application/xhtml+xml', null],
			'no Accept header' => [null, null],
		];
	}

	/**
	 * Sabre serves a HEAD by re-dispatching the request as a GET, so without this guard a
	 * HEAD on a folder would build the whole archive — rendering every member, and
	 * recording an audit row for each — to answer a request that carries no body.
	 */
	public function testASabreHeadSubRequestIsLeftToCore(): void {
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf');
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$request = $this->zipRequest();
		$request->setHeader('X-Sabre-Original-Method', 'HEAD');

		$this->assertTrue($this->plugin()->httpGet($request, new Response()));
		$this->assertSame([], Streamer::$constructed);
	}

	/**
	 * A GET on a *file* belongs to `DownloadInterceptorPlugin`. Claiming it here would
	 * hand every single-file download to the archive builder.
	 */
	public function testSingleFileGetIsLeftToCore(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->createMock(DavFile::class));
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$this->assertTrue($this->plugin()->httpGet($this->zipRequest(), new Response()));
		$this->assertSame([], Streamer::$constructed);
	}

	/**
	 * An unresolvable path is core's 404 to produce. Running ahead of it means this
	 * plugin sees requests for paths that do not exist.
	 */
	public function testAnUnresolvablePathIsLeftToCore(): void {
		$this->tree->method('getNodeForPath')->willThrowException(new NotFound());
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$this->assertTrue($this->plugin()->httpGet($this->zipRequest(), new Response()));
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
			$this->limits(),
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

	/**
	 * The caps are configuration, so the tests below fix them by hand — but that is only
	 * worth anything if a *configured* value actually moves the ceiling. Both directions
	 * are asserted, because a plugin that ignored the config and kept its old constants
	 * would still pass every default-valued test in this file.
	 */
	public function testALoweredMemberCapDeniesAnArchiveTheDefaultWouldAllow(): void {
		$children = [
			$this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf', size: 1),
			$this->file(2, '/bob/files/Shared/b.pdf', 'b.pdf', size: 1),
			$this->file(3, '/bob/files/Shared/c.pdf', 'c.pdf', size: 1),
		];
		$folder = $this->folder('/bob/files/Shared', 'Shared', $children);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')
			->willReturnCallback(fn () => $this->renderedCopy());

		// Three members, and the host allows two.
		$this->expectException(Forbidden::class);
		$this->plugin(limits: $this->limits(maxMembers: 2))
			->httpGet($this->zipRequest(), new Response());
	}

	public function testARaisedMemberCapAllowsAnArchiveTheDefaultWouldRefuse(): void {
		$children = [];
		for ($i = 1; $i <= 201; $i++) {
			$children[] = $this->file($i, "/bob/files/Shared/f$i.pdf", "f$i.pdf", size: 1);
		}
		$folder = $this->folder('/bob/files/Shared', 'Shared', $children);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_share');
		$this->watermarkService->method('watermarkForDownload')
			->willReturnCallback(fn () => $this->renderedCopy());

		// 201 members is a 403 at the default of 200 — see the test below.
		$handled = $this->plugin(limits: $this->limits(maxMembers: 250))
			->httpGet($this->zipRequest(), new Response());

		$this->assertFalse($handled, 'the raised cap was ignored');
		$this->assertCount(201, Streamer::members());
	}

	public function testALoweredByteCapDegradesUnderOnDownload(): void {
		$file = $this->file(1, '/bob/files/Shared/a.pdf', 'a.pdf', size: 1024);
		$folder = $this->folder('/bob/files/Shared', 'Shared', [$file]);

		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_download');
		// Stubbed deliberately: without a render to hand back, the plugin would defer to
		// core for having nothing to substitute, and this test would pass at any cap.
		$this->watermarkService->method('watermarkForDownload')
			->willReturnCallback(fn () => $this->renderedCopy());

		// 1 KiB is nowhere near the 256 MiB default, so this can only degrade if the
		// configured ceiling is the one being read.
		$this->assertTrue(
			$this->plugin(limits: $this->limits(maxBytes: 512))->httpGet($this->zipRequest(), new Response()),
			'the lowered byte cap was ignored',
		);
		$this->assertSame([], Streamer::members());

		// The control: the same folder at the default cap is claimed and rebuilt.
		Streamer::reset();
		$this->assertFalse($this->plugin()->httpGet($this->zipRequest(), new Response()));
		$this->assertCount(1, Streamer::members());
	}

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

	/**
	 * The member cap needs its own degradation test, and not because it is symmetrical
	 * with the byte cap — because it is not reached the same way. The byte cap trips on
	 * `getSize()` before anything is rendered; the member cap trips **mid-render**, with
	 * temp copies already on disk. Falling back to core there has to clean them up, or a
	 * best-effort download leaves 200 plaintext copies of user content in the temp dir.
	 */
	public function testExceedingTheMemberCapDegradesAndCleansUpUnderOnDownload(): void {
		$children = [];
		for ($i = 1; $i <= 201; $i++) {
			$children[] = $this->file($i, "/bob/files/Shared/f$i.pdf", "f$i.pdf", size: 1);
		}
		$folder = $this->folder('/bob/files/Shared', 'Shared', $children);

		$rendered = [];
		$this->tree->method('getNodeForPath')->willReturn($this->davDirectory($folder));
		$this->watermarkService->method('deliveryTriggerFor')->willReturn('on_download');
		$this->watermarkService->method('watermarkForDownload')
			->willReturnCallback(function () use (&$rendered) {
				$path = $this->renderedCopy();
				$rendered[] = $path;
				return $path;
			});

		$this->assertTrue($this->plugin()->httpGet($this->zipRequest(), new Response()));
		$this->assertSame([], Streamer::members(), 'a partial archive was streamed');

		$this->assertNotEmpty($rendered, 'nothing was rendered, so the cap was hit too early to prove anything');
		foreach ($rendered as $path) {
			$this->assertFileDoesNotExist($path, 'a temp copy outlived the abandoned render');
		}
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
