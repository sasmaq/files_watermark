<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\BackgroundJob\MarkFolderJob;
use OCA\FilesWatermark\Service\FileTooLargeException;
use OCA\FilesWatermark\Service\FolderMarker;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Marking a whole tree that has just come into scope - part of it in the request, the rest
 * in a job.
 *
 * @covers \OCA\FilesWatermark\Service\FolderMarker
 */
class FolderMarkerTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;
	private IUserMountCache&MockObject $mountCache;
	private IJobList&MockObject $jobList;
	private LoggerInterface&MockObject $logger;
	private FolderMarker $marker;

	/** @var list<File> what mark() was called with, in order */
	private array $marked = [];

	protected function setUp(): void {
		parent::setUp();

		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->mountCache = $this->createMock(IUserMountCache::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->marker = new FolderMarker(
			$this->watermarkService,
			$this->rootFolder,
			$this->mountCache,
			$this->jobList,
			$this->logger,
		);
	}

	/**
	 * Supported means PDF here; nothing is marked yet unless the test says so, and everything
	 * is in scope unless the test says so.
	 *
	 * @param list<int> $alreadyMarked
	 */
	private function policy(array $alreadyMarked = [], bool $inScope = true): void {
		$this->watermarkService->method('isSupported')
			->willReturnCallback(static fn (string $mime): bool => $mime === 'application/pdf');
		$this->watermarkService->method('markedFileIds')
			->willReturnCallback(static fn (array $ids): array => array_values(array_intersect($ids, $alreadyMarked)));
		$this->watermarkService->method('isInPolicyScope')->willReturn($inScope);
		$this->watermarkService->method('mark')->willReturnCallback(function (File $file): bool {
			$this->marked[] = $file;
			return true;
		});
	}

	private function file(int $id, string $mime = 'application/pdf'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getPath')->willReturn("/alice/files/Tagged/{$id}.pdf");
		return $file;
	}

	/** @param list<Node> $children */
	private function folder(array $children, string $mount = '/alice/', int $id = 7): Folder&MockObject {
		$mountPoint = $this->createMock(IMountPoint::class);
		$mountPoint->method('getMountPoint')->willReturn($mount);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getMountPoint')->willReturn($mountPoint);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	/** @return list<int> */
	private function markedIds(): array {
		return array_map(static fn (File $file): int => $file->getId(), $this->marked);
	}

	/**
	 * **Every unmarked file at any depth is marked.** One already marked and one of a type
	 * nothing can watermark are passed over.
	 */
	public function testMarksTheUnmarkedFilesOfTheWholeTree(): void {
		$this->policy(alreadyMarked: [12]);
		$tree = $this->folder([
			$this->file(11),
			$this->file(12),
			$this->file(13, 'text/plain'),
			$this->folder([$this->file(21), $this->folder([$this->file(31)])]),
		]);

		$this->assertTrue($this->marker->markTree($tree, null, 'tag'));

		$this->assertEqualsCanonicalizing([11, 21, 31], $this->markedIds());
	}

	/** The mark is placed under the upload trigger, naming whoever tagged or moved the folder. */
	public function testTheMarkIsTheUploadPolicysAndNamesTheActor(): void {
		$this->policy();
		$alice = $this->createMock(IUser::class);
		$file = $this->file(11);

		$this->watermarkService->expects($this->once())
			->method('mark')
			->with($file, WatermarkService::TRIGGER_ON_UPLOAD, $alice);

		$this->marker->markTree($this->folder([$file]), $alice, 'tag');
	}

	/**
	 * **A mount inside the tree is not walked.** It is stored somewhere else, so the tag on
	 * this folder does not reach it, and walking it would only spend the budget.
	 */
	public function testAMountInsideTheTreeIsNotWalked(): void {
		$this->policy();
		$share = $this->folder([$this->file(91)], '/alice/files/Tagged/FromBob/');
		$share->expects($this->never())->method('getDirectoryListing');

		$this->marker->markTree($this->folder([$this->file(11), $share]), null, 'tag');

		$this->assertSame([11], $this->markedIds());
	}

	/** Out of scope is the MIME whitelist saying no - not a failure, and not logged. */
	public function testAFileOutsideTheScopeIsNeitherMarkedNorLogged(): void {
		$this->policy(inScope: false);
		$this->logger->expects($this->never())->method('warning');

		$this->marker->markTree($this->folder([$this->file(11)]), null, 'tag');

		$this->assertSame([], $this->marked);
	}

	/** One file that cannot be marked does not cost the rest of the tree theirs. */
	public function testAFileThatCannotBeMarkedIsLoggedAndTheRestAreStillMarked(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('markedFileIds')->willReturn([]);
		$this->watermarkService->method('isInPolicyScope')->willReturn(true);
		$this->watermarkService->method('mark')->willReturnCallback(function (File $file): bool {
			if ($file->getId() === 11) {
				throw new FileTooLargeException('This file is too large to watermark.');
			}
			$this->marked[] = $file;
			return true;
		});

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not mark'), $this->callback(
				static fn (array $context): bool => $context['cause'] === 'move',
			));

		$this->marker->markTree($this->folder([$this->file(11), $this->file(12)]), null, 'move');

		$this->assertSame([12], $this->markedIds());
	}

	/**
	 * **The budget stops the walk**, and says so. What the listing had already handed over
	 * is still marked - it is in hand, and the job will skip it.
	 */
	public function testTheBudgetStopsTheWalkAfterMarkingWhatItReached(): void {
		$this->policy();
		$tree = $this->folder([$this->file(11), $this->file(12), $this->file(13)]);

		$this->assertFalse($this->marker->markTree($tree, null, 'tag', 2));

		$this->assertSame([11, 12], $this->markedIds());
	}

	public function testATreeWithinTheBudgetIsFinishedInlineAndNothingIsQueued(): void {
		$this->policy();
		$this->jobList->expects($this->never())->method('add');

		$this->marker->markTreeOrQueue($this->folder([$this->file(11)]), null, 'tag');

		$this->assertSame([11], $this->markedIds());
	}

	/**
	 * **A tree larger than the budget is handed to the job**, which walks all of it again
	 * from the top - with the folder, who to name, and what brought it into scope.
	 */
	public function testATreeLargerThanTheBudgetIsQueuedForTheRest(): void {
		$this->policy();
		$files = [];
		for ($i = 1; $i <= FolderMarker::INLINE_BUDGET + 1; $i++) {
			$files[] = $this->file(1000 + $i);
		}
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');

		$this->jobList->expects($this->once())
			->method('add')
			->with(MarkFolderJob::class, ['folderId' => 7, 'uid' => 'alice', 'cause' => 'tag']);

		$this->marker->markTreeOrQueue($this->folder($files), $alice, 'tag');

		$this->assertCount(FolderMarker::INLINE_BUDGET, $this->marked);
	}

	/**
	 * Without a session the root folder finds nothing by id, so the folder is reached through
	 * a user who has it mounted.
	 */
	public function testAFolderIsResolvedThroughTheMountCache(): void {
		$folder = $this->createMock(Folder::class);
		$this->mountCache->method('getMountsForFileId')->with(7)->willReturn([$this->mountFor('bob'), $this->mountFor('alice')]);
		$this->userFolders(['bob' => null, 'alice' => $folder]);
		$this->rootFolder->expects($this->never())->method('getFirstNodeById');

		$this->assertSame($folder, $this->marker->folderById(7));
	}

	public function testAnIdThatIsAFileResolvesToNoFolder(): void {
		$this->mountCache->method('getMountsForFileId')->willReturn([$this->mountFor('alice')]);
		$this->userFolders(['alice' => $this->file(7)]);

		$this->assertNull($this->marker->folderById(7));
	}

	public function testAnIdNobodyHasMountedResolvesToNoFolder(): void {
		$this->mountCache->method('getMountsForFileId')->willReturn([]);

		$this->assertNull($this->marker->folderById(7));
	}

	private function mountFor(string $uid): ICachedMountFileInfo {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$mount = $this->createMock(ICachedMountFileInfo::class);
		$mount->method('getUser')->willReturn($user);
		return $mount;
	}

	/** @param array<string, ?Node> $nodes what each user's own folder resolves 7 to */
	private function userFolders(array $nodes): void {
		$this->rootFolder->method('getUserFolder')->willReturnCallback(function (string $uid) use ($nodes): Folder {
			$folder = $this->createMock(Folder::class);
			$folder->method('getFirstNodeById')->with(7)->willReturn($nodes[$uid] ?? null);
			return $folder;
		});
	}
}
