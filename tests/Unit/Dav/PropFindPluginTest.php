<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\Dav\PropFindPlugin;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;

/**
 * @covers \OCA\FilesWatermark\Dav\PropFindPlugin
 */
class PropFindPluginTest extends TestCase {

	private const PROP = PropFindPlugin::WATERMARKED_PROPERTY;

	private WatermarkMarkMapper&MockObject $markMapper;
	private PropFindPlugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->markMapper = $this->createMock(WatermarkMarkMapper::class);
		$this->plugin = new PropFindPlugin($this->markMapper);
	}

	private function davFile(int $id): DavFile {
		$davFile = $this->createMock(DavFile::class);
		$davFile->method('getId')->willReturn($id);
		// The public instance asks the node for the `OCP\Files` node behind it to put the
		// scope question to the service. An unstubbed mock answers null there, which would
		// make every delivery-mode assertion below pass through the plugin's catch instead
		// of its logic - and so pass for the wrong reason.
		$davFile->method('getNode')->willReturn($this->createMock(File::class));
		return $davFile;
	}

	/** @param int[] $childIds */
	private function davDirectory(int $id, array $childIds): DavDirectory {
		$children = array_map(function (int $childId): File {
			$child = $this->createMock(File::class);
			$child->method('getId')->willReturn($childId);
			return $child;
		}, $childIds);

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($children);

		$davDir = $this->createMock(DavDirectory::class);
		$davDir->method('getId')->willReturn($id);
		$davDir->method('getNode')->willReturn($folder);
		return $davDir;
	}

	private function propFind(int $depth = 0, array $properties = [self::PROP]): PropFind {
		return new PropFind('files/alice/report.pdf', $properties, $depth);
	}

	public function testRegistersOnPropFind(): void {
		$server = new Server();
		$this->plugin->initialize($server);

		$this->assertNotEmpty($server->listeners('propFind'));
	}

	public function testReportsWatermarkedFile(): void {
		$this->markMapper->method('markedFileIds')->with([7])->willReturn([7]);

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('1', $propFind->get(self::PROP));
	}

	public function testReportsCleanFile(): void {
		$this->markMapper->method('markedFileIds')->with([7])->willReturn([]);

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	public function testUnrequestedPropertyCostsNoQuery(): void {
		$this->markMapper->expects($this->never())->method('markedFileIds');

		$propFind = $this->propFind(properties: ['{DAV:}getcontentlength']);
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertNull($propFind->get(self::PROP));
	}

	public function testNonNextcloudNodeIsIgnored(): void {
		// A plain Sabre node has no file id to report a status for.
		$this->markMapper->expects($this->never())->method('markedFileIds');

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, new SimpleCollection('nope'));

		$this->assertNull($propFind->get(self::PROP));
	}

	public function testFolderListingIsResolvedInOneBatchedQuery(): void {
		$davDir = $this->davDirectory(1, [10, 11, 12]);

		$queries = [];
		$this->markMapper->method('markedFileIds')
			->willReturnCallback(static function (array $ids) use (&$queries): array {
				$queries[] = $ids;
				return array_values(array_intersect($ids, [11]));
			});

		$depth1 = $this->propFind(depth: 1);
		$this->plugin->propFind($depth1, $davDir);

		// The listing is primed in a single batch, plus one lookup for the folder's own
		// id (a folder is never watermarked, but it is still asked). Two queries, and -
		// crucially - a constant two rather than one per child.
		$this->assertSame([[10, 11, 12], [1]], $queries);

		// Children are now answered from the primed cache: no further queries at all.
		foreach ([10 => '0', 11 => '1', 12 => '0'] as $childId => $expected) {
			$childPropFind = $this->propFind();
			$this->plugin->propFind($childPropFind, $this->davFile($childId));
			$this->assertSame($expected, $childPropFind->get(self::PROP), "child $childId");
		}

		$this->assertCount(2, $queries, 'listing a folder must not fan out into a query per child');
	}

	public function testDepthZeroFolderDoesNotPrimeTheListing(): void {
		$davDir = $this->davDirectory(1, [10, 11]);

		// A depth-0 PROPFIND asks about the folder alone, so listing it would be wasted work.
		$this->markMapper->expects($this->once())
			->method('markedFileIds')
			->with([1])
			->willReturn([]);

		$propFind = $this->propFind(depth: 0);
		$this->plugin->propFind($propFind, $davDir);

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	public function testEmptyFolderIsHandledWithoutABatchQuery(): void {
		$davDir = $this->davDirectory(1, []);

		// No children to batch; only the folder's own lookup happens.
		$this->markMapper->expects($this->once())
			->method('markedFileIds')
			->with([1])
			->willReturn([]);

		$propFind = $this->propFind(depth: 1);
		$this->plugin->propFind($propFind, $davDir);

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	public function testRepeatedLookupsForTheSameFileAreCached(): void {
		$this->markMapper->expects($this->once())
			->method('markedFileIds')
			->willReturn([7]);

		foreach (range(1, 3) as $_) {
			$propFind = $this->propFind();
			$this->plugin->propFind($propFind, $this->davFile(7));
			$this->assertSame('1', $propFind->get(self::PROP));
		}
	}

	// -----------------------------------------------------------------------
	// Public (delivery) mode - the instance the public share page's listing goes through
	// -----------------------------------------------------------------------

	/**
	 * On the public DAV server the request being served *is* the share, so a file behind a
	 * link the policy watermarks has to report itself watermarked even though nobody has
	 * marked it. Reporting only marks would leave a visitor's whole listing unbadged on
	 * exactly the installs that watermark all of it.
	 */
	public function testPublicModeReportsAFileTheShareSwitchWatermarks(): void {
		$this->markMapper->method('markedFileIds')->willReturn([]);
		$service = $this->createMock(WatermarkService::class);
		$service->method('isForcedByShare')->willReturn(true);
		$plugin = new PropFindPlugin($this->markMapper, $service);

		$propFind = $this->propFind();
		$plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('1', $propFind->get(self::PROP));
	}

	public function testPublicModeStillReportsACleanFileAsClean(): void {
		// The switch being off must leave the answer exactly where the mark left it -
		// otherwise every public listing would badge every file.
		$this->markMapper->method('markedFileIds')->willReturn([]);
		$service = $this->createMock(WatermarkService::class);
		$service->method('isForcedByShare')->willReturn(false);
		$plugin = new PropFindPlugin($this->markMapper, $service);

		$propFind = $this->propFind();
		$plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	public function testAMarkedFileIsNeverAskedAboutTheShareSwitch(): void {
		// The batched mark lookup answers most rows on its own; the service is the second
		// question, not the first, and asking it anyway would cost a scope check per row.
		$this->markMapper->method('markedFileIds')->willReturn([7]);
		$service = $this->createMock(WatermarkService::class);
		$service->expects($this->never())->method('isForcedByShare');
		$plugin = new PropFindPlugin($this->markMapper, $service);

		$propFind = $this->propFind();
		$plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('1', $propFind->get(self::PROP));
	}

	/**
	 * The badge is an indicator; the download path decides the real answer for itself a
	 * moment later. A scope check that throws must not take the whole listing with it.
	 */
	public function testAFailingScopeCheckLeavesTheListingRenderable(): void {
		$this->markMapper->method('markedFileIds')->willReturn([]);
		$service = $this->createMock(WatermarkService::class);
		$service->method('isForcedByShare')->willThrowException(new \RuntimeException('tag lookup failed'));
		$plugin = new PropFindPlugin($this->markMapper, $service);

		$propFind = $this->propFind();
		$plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	/**
	 * The authenticated server passes no service, and must not start reporting the share
	 * switches: they watermark a *recipient's* fetch, and an owner browsing their own files
	 * is not that recipient. Answering wider there would badge every file in the owner's
	 * home the moment they ticked "watermark public links".
	 */
	public function testTheAuthenticatedInstanceReportsMarksAlone(): void {
		$this->markMapper->method('markedFileIds')->willReturn([]);

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::PROP));
	}
}
