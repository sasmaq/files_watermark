<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\Files_Trashbin\Sabre\ITrash;
use OCA\FilesWatermark\Dav\PropFindPlugin;
use OCA\FilesWatermark\Db\WatermarkMark;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\ICollection;
use Sabre\DAV\IFile;
use Sabre\DAV\INode;
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

	/**
	 * Mark rows keyed by file id, as the batched lookup returns them.
	 *
	 * @param int[] $ids
	 * @return array<int, WatermarkMark>
	 */
	private function marks(array $ids, string $trigger = WatermarkService::TRIGGER_ON_DEMAND, ?string $origin = null): array {
		$marks = [];
		foreach ($ids as $id) {
			$mark = new WatermarkMark();
			$mark->setFileId($id);
			$mark->setTrigger($trigger);
			$mark->setOriginOwner($origin);
			$marks[$id] = $mark;
		}
		return $marks;
	}

	private function session(?string $uid): IUserSession&MockObject {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
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
		$this->markMapper->method('findByFileIds')->with([7])->willReturn($this->marks([7]));

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('1', $propFind->get(self::PROP));
	}

	public function testReportsCleanFile(): void {
		$this->markMapper->method('findByFileIds')->with([7])->willReturn([]);

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	public function testUnrequestedPropertyCostsNoQuery(): void {
		$this->markMapper->expects($this->never())->method('findByFileIds');

		$propFind = $this->propFind(properties: ['{DAV:}getcontentlength']);
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertNull($propFind->get(self::PROP));
	}

	public function testNonNextcloudNodeIsIgnored(): void {
		// A plain Sabre node has no file id to report a status for.
		$this->markMapper->expects($this->never())->method('findByFileIds');

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, new SimpleCollection('nope'));

		$this->assertNull($propFind->get(self::PROP));
	}

	public function testFolderListingIsResolvedInOneBatchedQuery(): void {
		$davDir = $this->davDirectory(1, [10, 11, 12]);

		$queries = [];
		$this->markMapper->method('findByFileIds')
			->willReturnCallback(function (array $ids) use (&$queries): array {
				$queries[] = $ids;
				return $this->marks(array_values(array_intersect($ids, [11])));
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
			->method('findByFileIds')
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
			->method('findByFileIds')
			->with([1])
			->willReturn([]);

		$propFind = $this->propFind(depth: 1);
		$this->plugin->propFind($propFind, $davDir);

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	public function testRepeatedLookupsForTheSameFileAreCached(): void {
		$this->markMapper->expects($this->once())
			->method('findByFileIds')
			->willReturn($this->marks([7]));

		foreach (range(1, 3) as $_) {
			$propFind = $this->propFind();
			$this->plugin->propFind($propFind, $this->davFile(7));
			$this->assertSame('1', $propFind->get(self::PROP));
		}
	}

	// -----------------------------------------------------------------------
	// The trash - a deleted file is still a marked file
	// -----------------------------------------------------------------------

	/**
	 * `ITrash` does not extend `INode` - core's trash nodes get there by implementing both
	 * (`AbstractTrashFile extends AbstractTrash implements IFile, ITrash`). The mock has to
	 * do the same, or it is not a node the DAV tree could ever have returned.
	 *
	 * @return ITrash&MockObject
	 */
	private function trashFile(int $fileId): ITrash {
		$node = $this->createMockForIntersectionOfInterfaces([ITrash::class, IFile::class]);
		$node->method('getFileId')->willReturn($fileId);
		return $node;
	}

	/**
	 * The trash root: a bare `ICollection` with no file id of its own, which is exactly why
	 * the batching cannot wait behind the id lookup.
	 *
	 * @param int[] $childIds
	 */
	private function trashRoot(array $childIds): ICollection {
		$root = $this->createMock(ICollection::class);
		$root->method('getChildren')->willReturn(
			array_map(fn (int $id): ITrash => $this->trashFile($id), $childIds),
		);
		return $root;
	}

	/**
	 * **The badge in the trash.**
	 *
	 * The trashbin is served by the same Sabre server and its listing asks for every
	 * registered property, so this hook always ran for these rows - and declined, because a
	 * trashed node is an `ITrash` and never an `OCA\DAV\Connector\Sabre\Node`. The file
	 * keeps its id through the delete, so it keeps its mark, and its download out of the
	 * trash is watermarked: the badge was the only part that disagreed.
	 */
	public function testAMarkedFileReportsItselfWatermarkedInTheTrash(): void {
		$this->markMapper->method('findByFileIds')->with([7])->willReturn($this->marks([7]));

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->trashFile(7));

		$this->assertSame('1', $propFind->get(self::PROP));
	}

	public function testAnUnmarkedTrashedFileReportsItselfClean(): void {
		$this->markMapper->method('findByFileIds')->willReturn([]);

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->trashFile(7));

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	/**
	 * The trash listing is batched like any other, and this is the case that proves the
	 * batching has to happen before the id lookup: the root has no id, so resolving it
	 * first and returning early would leave every trashed file to its own query.
	 */
	public function testTheTrashListingIsResolvedInOneBatchedQuery(): void {
		$queries = [];
		$this->markMapper->method('findByFileIds')
			->willReturnCallback(function (array $ids) use (&$queries): array {
				$queries[] = $ids;
				return $this->marks(array_values(array_intersect($ids, [11])));
			});

		$depth1 = $this->propFind(depth: 1);
		$this->plugin->propFind($depth1, $this->trashRoot([10, 11, 12]));

		$this->assertSame([[10, 11, 12]], $queries, 'the trash root must prime the whole listing');
		// The root itself carries no id, so it is answered with nothing at all.
		$this->assertNull($depth1->get(self::PROP));

		foreach ([10 => '0', 11 => '1', 12 => '0'] as $childId => $expected) {
			$childPropFind = $this->propFind();
			$this->plugin->propFind($childPropFind, $this->trashFile($childId));
			$this->assertSame($expected, $childPropFind->get(self::PROP), "trashed child $childId");
		}

		$this->assertCount(1, $queries, 'a trash listing must not fan out into a query per row');
	}

	/**
	 * The share switches say nothing about the trash, and must not be asked.
	 *
	 * They describe a file being handed to somebody through a share; a trash listing is the
	 * owner looking at their own deleted files. A trashed node cannot answer `getNode()`
	 * either, so asking would be an error as well as a category mistake.
	 */
	public function testTheShareSwitchIsNeverAskedAboutATrashedFile(): void {
		$this->markMapper->method('findByFileIds')->willReturn([]);
		$service = $this->createMock(WatermarkService::class);
		$service->expects($this->never())->method('isForcedByShare');
		$plugin = new PropFindPlugin($this->markMapper, $service);

		$propFind = $this->propFind();
		$plugin->propFind($propFind, $this->trashFile(7));

		$this->assertSame('0', $propFind->get(self::PROP));
	}

	/**
	 * A collection whose children are not trashed nodes yields no ids and costs no query -
	 * which is what keeps the trash branch from firing on every other collection the DAV
	 * server serves.
	 */
	public function testAnUnrelatedCollectionCostsNoQuery(): void {
		$this->markMapper->expects($this->never())->method('findByFileIds');

		$propFind = $this->propFind(depth: 1);
		$this->plugin->propFind($propFind, new SimpleCollection('nope', []));

		$this->assertNull($propFind->get(self::PROP));
	}

	/**
	 * A node that is neither kind is still ignored - the guard that used to be an early
	 * `instanceof` is now the id lookup, and it has to refuse the same things.
	 */
	public function testANodeWithNoFileIdIsIgnored(): void {
		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->createMock(INode::class));

		$this->assertNull($propFind->get(self::PROP));
	}

	// -----------------------------------------------------------------------
	// `watermark-locked` - may the person looking at this row unmark it
	// -----------------------------------------------------------------------

	private const LOCKED = PropFindPlugin::LOCKED_PROPERTY;

	private function lockedPlugin(?string $uid): PropFindPlugin {
		return new PropFindPlugin($this->markMapper, null, $this->session($uid));
	}

	/**
	 * **The case the property exists for.**
	 *
	 * Bob owns this file - he owns it because he copied it out of a share - so the Files
	 * app's ownership test says the Remove button belongs to him, and the server refuses
	 * it. The property is what stops the button being offered.
	 */
	public function testAnInheritedMarkIsLockedForTheUserWhoCopiedIt(): void {
		$this->markMapper->method('findByFileIds')
			->willReturn($this->marks([7], WatermarkService::TRIGGER_INHERITED, 'alice'));

		$propFind = $this->propFind(properties: [self::PROP, self::LOCKED]);
		$this->lockedPlugin('bob')->propFind($propFind, $this->davFile(7));

		$this->assertSame('1', $propFind->get(self::LOCKED));
		// And it is still watermarked - the two properties answer different questions.
		$this->assertSame('1', $propFind->get(self::PROP));
	}

	/** The origin keeps the button, so copying your own marked file is not a trap. */
	public function testAnInheritedMarkIsNotLockedForItsOrigin(): void {
		$this->markMapper->method('findByFileIds')
			->willReturn($this->marks([7], WatermarkService::TRIGGER_INHERITED, 'alice'));

		$propFind = $this->propFind(properties: [self::LOCKED]);
		$this->lockedPlugin('alice')->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::LOCKED));
	}

	/**
	 * An ordinary mark locks nobody: ownership governs it, exactly as it did before this
	 * property existed. Asserted because the cheap mistake here is to report every marked
	 * file as locked and hide Remove across the whole instance.
	 */
	public function testAnOrdinaryMarkIsNeverLocked(): void {
		$this->markMapper->method('findByFileIds')->willReturn($this->marks([7]));

		$propFind = $this->propFind(properties: [self::LOCKED]);
		$this->lockedPlugin('bob')->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::LOCKED));
	}

	public function testAnUnmarkedFileIsNeverLocked(): void {
		$this->markMapper->method('findByFileIds')->willReturn([]);

		$propFind = $this->propFind(properties: [self::LOCKED]);
		$this->lockedPlugin('bob')->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::LOCKED));
	}

	/**
	 * An inherited mark that names nobody is refused by the API for everyone, so it is
	 * locked for everyone. The fail-closed direction.
	 */
	public function testAnInheritedMarkWithNoOriginIsLockedForEveryone(): void {
		$this->markMapper->method('findByFileIds')
			->willReturn($this->marks([7], WatermarkService::TRIGGER_INHERITED, null));

		$propFind = $this->propFind(properties: [self::LOCKED]);
		$this->lockedPlugin('alice')->propFind($propFind, $this->davFile(7));

		$this->assertSame('1', $propFind->get(self::LOCKED));
	}

	/**
	 * The public server has no viewer to name and no Remove action to govern. Answering
	 * "locked" there would report a property about a button that page does not have.
	 */
	public function testWithNoSessionNothingIsLocked(): void {
		$this->markMapper->method('findByFileIds')
			->willReturn($this->marks([7], WatermarkService::TRIGGER_INHERITED, 'alice'));

		$propFind = $this->propFind(properties: [self::LOCKED]);
		// Exactly how SabrePublicPluginAddListener builds it: no session at all.
		(new PropFindPlugin($this->markMapper))->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::LOCKED));
	}

	/**
	 * Asking for both costs one lookup, not two - they are answered from the same row.
	 */
	public function testBothPropertiesShareOneLookup(): void {
		$this->markMapper->expects($this->once())
			->method('findByFileIds')
			->willReturn($this->marks([7], WatermarkService::TRIGGER_INHERITED, 'alice'));

		$propFind = $this->propFind(properties: [self::PROP, self::LOCKED]);
		$this->lockedPlugin('bob')->propFind($propFind, $this->davFile(7));

		$this->assertSame('1', $propFind->get(self::PROP));
		$this->assertSame('1', $propFind->get(self::LOCKED));
	}

	/**
	 * A client that asks for the lock alone still gets it. The two are registered together
	 * by this app's own bundle, but the property is public and a caller may ask for either.
	 */
	public function testTheLockCanBeAskedForOnItsOwn(): void {
		$this->markMapper->method('findByFileIds')
			->willReturn($this->marks([7], WatermarkService::TRIGGER_INHERITED, 'alice'));

		$propFind = $this->propFind(properties: [self::LOCKED]);
		$this->lockedPlugin('bob')->propFind($propFind, $this->davFile(7));

		$this->assertSame('1', $propFind->get(self::LOCKED));
		$this->assertNull($propFind->get(self::PROP), 'an unrequested property must stay unanswered');
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
		$this->markMapper->method('findByFileIds')->willReturn([]);
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
		$this->markMapper->method('findByFileIds')->willReturn([]);
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
		$this->markMapper->method('findByFileIds')->willReturn($this->marks([7]));
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
		$this->markMapper->method('findByFileIds')->willReturn([]);
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
		$this->markMapper->method('findByFileIds')->willReturn([]);

		$propFind = $this->propFind();
		$this->plugin->propFind($propFind, $this->davFile(7));

		$this->assertSame('0', $propFind->get(self::PROP));
	}
}
