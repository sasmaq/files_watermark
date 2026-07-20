<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\Dav\PropFindPlugin;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
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

	private WatermarkLogMapper&MockObject $logMapper;
	private PropFindPlugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->logMapper = $this->createMock(WatermarkLogMapper::class);
		$this->plugin = new PropFindPlugin($this->logMapper);
	}

	private function davFile(int $id): DavFile {
		$davFile = $this->createMock(DavFile::class);
		$davFile->method('getId')->willReturn($id);
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
		$this->logMapper->method('findWatermarkedFileIds')->with([7])->willReturn([7]);

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('1', $propFind->get(self::PROP));
	}

	public function testReportsCleanFile(): void {
		$this->logMapper->method('findWatermarkedFileIds')->with([7])->willReturn([]);

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	public function testUnrequestedPropertyCostsNoQuery(): void {
		$this->logMapper->expects($this->never())->method('findWatermarkedFileIds');

		$propFind = $this->propFind(properties: ['{DAV:}getcontentlength']);
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertNull($propFind->get(self::PROP));
	}

	public function testNonNextcloudNodeIsIgnored(): void {
		// A plain Sabre node has no file id to report a status for.
		$this->logMapper->expects($this->never())->method('findWatermarkedFileIds');

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, new SimpleCollection('nope'));

		$this->assertNull($propFind->get(self::PROP));
	}

	public function testFolderListingIsResolvedInOneBatchedQuery(): void {
		$davDir = $this->davDirectory(1, [10, 11, 12]);

		$queries = [];
		$this->logMapper->method('findWatermarkedFileIds')
			->willReturnCallback(static function (array $ids) use (&$queries): array {
				$queries[] = $ids;
				return array_values(array_intersect($ids, [11]));
			});

		$depth1 = $this->propFind(depth: 1);
		$this->plugin->propFind($depth1, $davDir);

		// The listing is primed in a single batch, plus one lookup for the folder's own
		// id (a folder is never watermarked, but it is still asked). Two queries, and —
		// crucially — a constant two rather than one per child.
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
		$this->logMapper->expects($this->once())
			->method('findWatermarkedFileIds')
			->with([1])
			->willReturn([]);

		$propFind = $this->propFind(depth: 0);
		$this->plugin->propFind($propFind, $davDir);

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	public function testEmptyFolderIsHandledWithoutABatchQuery(): void {
		$davDir = $this->davDirectory(1, []);

		// No children to batch; only the folder's own lookup happens.
		$this->logMapper->expects($this->once())
			->method('findWatermarkedFileIds')
			->with([1])
			->willReturn([]);

		$propFind = $this->propFind(depth: 1);
		$this->plugin->propFind($propFind, $davDir);

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	public function testRepeatedLookupsForTheSameFileAreCached(): void {
		$this->logMapper->expects($this->once())
			->method('findWatermarkedFileIds')
			->willReturn([7]);

		foreach (range(1, 3) as $_) {
			$propFind = $this->propFind();
			$this->plugin->propFind($propFind, $this->davFile(7));
			$this->assertSame('1', $propFind->get(self::PROP));
		}
	}
}
