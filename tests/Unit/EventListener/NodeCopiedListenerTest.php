<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\NodeCopiedListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener that keeps a mark from being shed by copying the file.
 *
 * A mark is a row against a file id and a copy gets a new one, so before this listener the
 * copy of a marked file was a clean, unmarked, downloadable original - and its new owner was
 * offered Remove on it as well. There is deliberately no move case here and none to write: a
 * move keeps the file id (core's cache moves the row in place), so the mark travels on its
 * own.
 */
class NodeCopiedListenerTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private LoggerInterface&MockObject $logger;
	private NodeCopiedListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new NodeCopiedListener($this->watermarkService, $this->logger);
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function file(int $id, string $owner = 'alice', string $name = 'report.pdf'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/' . $owner . '/files/' . $name);
		$file->method('getOwner')->willReturn($this->user($owner));
		return $file;
	}

	/**
	 * @param list<File|Folder> $children
	 */
	private function folder(string $owner, array $children = []): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getOwner')->willReturn($this->user($owner));
		$folder->method('getPath')->willReturn('/' . $owner . '/files/docs');
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	private function copy(mixed $source, mixed $target): void {
		$this->listener->handle(new NodeCopiedEvent($source, $target));
	}

	public function testIgnoresEventsItIsNotFor(): void {
		$this->watermarkService->expects($this->never())->method('inheritMark');

		$this->listener->handle($this->createMock(Event::class));
		$this->listener->handle(new NodeWrittenEvent($this->file(42)));
	}

	/**
	 * The whole point: the copy of a marked file is marked.
	 *
	 * Nothing about who is copying or where it lands enters into it - the mark is about the
	 * document, which is the same answer the app gives on every other path.
	 */
	public function testACopyOfAMarkedFileIsMarked(): void {
		$source = $this->file(42);
		$target = $this->file(99, 'bob');
		$this->watermarkService->method('isMarked')->with(42)->willReturn(true);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($target, $source);

		$this->copy($source, $target);
	}

	public function testAnOrdinaryCopyOfAnUnmarkedFileIsLeftAlone(): void {
		$this->watermarkService->method('isMarked')->willReturn(false);
		$this->watermarkService->expects($this->never())->method('inheritMark');

		$this->copy($this->file(42), $this->file(99));
	}

	/**
	 * **The second reason, and the one that is easy to miss.**
	 *
	 * An instance running the internal-share switch and no marks at all has the same hole:
	 * the switch is evaluated per fetch against the storage the file is on, so it has nothing
	 * to say once the file sits in the recipient's own storage. The copy crossing from one
	 * owner to another is what turns that per-fetch policy into a durable mark.
	 */
	public function testACopyThatCarriesAFileOutOfAShareIsMarked(): void {
		$source = $this->file(42, 'alice');
		$target = $this->file(99, 'bob');
		$this->watermarkService->method('isMarked')->willReturn(false);
		$this->watermarkService->method('isForcedByShare')->with($source)->willReturn(true);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($target, $source);

		$this->copy($source, $target);
	}

	/**
	 * A copy inside one user's own files never asks the share question at all.
	 *
	 * Asserted on the *call*, not on the outcome: the share switch cannot apply to a copy
	 * that stays with its owner, and asking anyway would put a scope check - which reads the
	 * file's tags - on the hot path of every ordinary copy.
	 */
	public function testACopyThatStaysWithItsOwnerNeverAsksTheShareQuestion(): void {
		$this->watermarkService->method('isMarked')->willReturn(false);
		$this->watermarkService->expects($this->never())->method('isForcedByShare');
		$this->watermarkService->expects($this->never())->method('inheritMark');

		$this->copy($this->file(42, 'alice'), $this->file(99, 'alice'));
	}

	/**
	 * **Copying the folder must not be the way round copying the file.**
	 *
	 * `View::copy()` emits one event for the top of the tree, so without the walk a marked
	 * file one directory down would come out clean - the same escape with one more click.
	 */
	public function testAMarkedFileNestedInACopiedFolderIsMarked(): void {
		$deepSource = $this->file(42, 'alice', 'secret.pdf');
		$deepTarget = $this->file(99, 'bob', 'secret.pdf');

		$sourceSub = $this->folder('alice', [$deepSource]);
		$sourceSub->method('getName')->willReturn('sub');
		$targetSub = $this->folder('bob', [$deepTarget]);
		$targetSub->method('get')->with('secret.pdf')->willReturn($deepTarget);

		$sourceRoot = $this->folder('alice', [$sourceSub]);
		$targetRoot = $this->folder('bob');
		$targetRoot->method('get')->with('sub')->willReturn($targetSub);

		$this->watermarkService->method('markedFileIds')->with([42])->willReturn([42]);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($deepTarget, $deepSource);

		$this->copy($sourceRoot, $targetRoot);
	}

	/**
	 * The marked ids are asked for once per directory, not once per file.
	 *
	 * The reason the walk goes down the *source* side: a folder copy is already O(files), and
	 * a query per child would make the cheap half of the operation the expensive one.
	 */
	public function testTheWalkAsksForMarkedIdsOncePerDirectory(): void {
		$children = [$this->file(1, 'alice', 'a.pdf'), $this->file(2, 'alice', 'b.pdf'), $this->file(3, 'alice', 'c.pdf')];
		$sourceRoot = $this->folder('alice', $children);
		$targetRoot = $this->folder('alice');
		$targetRoot->method('get')->willReturn($this->file(9, 'alice'));

		$this->watermarkService->expects($this->once())
			->method('markedFileIds')
			->with([1, 2, 3])
			->willReturn([]);

		$this->copy($sourceRoot, $targetRoot);
	}

	/**
	 * One child whose copy cannot be resolved does not cost the rest of the folder its marks.
	 */
	public function testAnUnresolvableChildDoesNotStopTheWalk(): void {
		$lost = $this->file(1, 'alice', 'lost.pdf');
		$found = $this->file(2, 'alice', 'found.pdf');
		$foundTarget = $this->file(99, 'bob', 'found.pdf');

		$sourceRoot = $this->folder('alice', [$lost, $found]);
		$targetRoot = $this->folder('bob');
		$targetRoot->method('get')->willReturnCallback(
			fn (string $name): File => $name === 'found.pdf'
				? $foundTarget
				: throw new \OCP\Files\NotFoundException(),
		);

		$this->watermarkService->method('markedFileIds')->willReturn([1, 2]);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($foundTarget, $found);

		$this->copy($sourceRoot, $targetRoot);
	}

	/**
	 * **The copy still stands.**
	 *
	 * It has already happened by the time this event fires; throwing would turn a completed
	 * copy into an error the user cannot act on and would not un-copy anything. The warning
	 * is the whole record that a protected file has an unmarked copy, so it is asserted.
	 */
	public function testAFailureIsLoggedAndDoesNotEscape(): void {
		$this->watermarkService->method('isMarked')->willReturn(true);
		$this->watermarkService->method('inheritMark')
			->willThrowException(new \RuntimeException('db is gone'));

		$this->logger->expects($this->once())->method('warning');

		$this->copy($this->file(42), $this->file(99, 'bob'));
	}
}
