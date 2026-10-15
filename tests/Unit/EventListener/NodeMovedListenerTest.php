<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\NodeMovedListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A recipient moving a protected file out of a share and into their own home.
 *
 * @covers \OCA\FilesWatermark\EventListener\NodeMovedListener
 */
class NodeMovedListenerTest extends TestCase {

	private const SOURCE_PATH = '/bob/files/Proj/report.pdf';

	private WatermarkService&MockObject $watermarkService;
	private LoggerInterface&MockObject $logger;
	private NodeMovedListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new NodeMovedListener($this->watermarkService, $this->logger);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function file(int $id, string $owner, string $path = self::SOURCE_PATH, string $name = 'report.pdf'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getOwner')->willReturn($this->user($owner));
		$file->method('getPath')->willReturn($path);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	/** Where the moved node is going, before it exists: only its parent can say whose it is. */
	private function destination(string $owner): Node&MockObject {
		$parent = $this->createMock(Folder::class);
		$parent->method('getOwner')->willReturn($this->user($owner));

		$target = $this->createMock(File::class);
		$target->method('getParent')->willReturn($parent);
		return $target;
	}

	/** What the source is after the move: a path to nothing. */
	private function gone(string $path = self::SOURCE_PATH): Node&MockObject {
		$source = $this->createMock(File::class);
		$source->method('getPath')->willReturn($path);
		return $source;
	}

	private function move(Node $source, Node $destination, Node $arrived): void {
		$this->listener->handle(new BeforeNodeRenamedEvent($source, $destination));
		$this->listener->handle(new NodeRenamedEvent($this->gone($source->getPath()), $arrived));
	}

	/**
	 * The switch escape: a file watermarked only because it is in a share, moved into the
	 * recipient's own storage. It is given the mark a copy would get, naming its owner.
	 */
	public function testAShareForcedFileMovedOutOfTheShareIsMarkedForItsOwner(): void {
		$source = $this->file(5, 'alice');
		$arrived = $this->file(5, 'bob', '/bob/files/report.pdf');

		$this->watermarkService->method('markOrigins')->willReturn([]);
		$this->watermarkService->method('isForcedByShare')->with($source)->willReturn(true);
		$this->watermarkService->expects($this->once())
			->method('carryMark')
			->with($arrived, 5, 'alice');

		$this->move($source, $this->destination('bob'), $arrived);
	}

	/** The ordinary case for a marked file: the id survived, so the mark is already there. */
	public function testAMarkedFileWhoseIdSurvivedIsLeftAlone(): void {
		$this->watermarkService->method('markOrigins')->willReturn([5 => 'alice']);
		$this->watermarkService->expects($this->never())->method('carryMark');

		$this->move($this->file(5, 'alice'), $this->destination('bob'), $this->file(5, 'bob', '/bob/files/report.pdf'));
	}

	/** Core's fallback copies the row under a new id, which no mark names. */
	public function testAMarkedFileWhoseIdDidNotSurviveTakesItsMarkAlong(): void {
		$arrived = $this->file(9, 'bob', '/bob/files/report.pdf');
		$this->watermarkService->method('markOrigins')->willReturn([5 => 'alice']);
		$this->watermarkService->expects($this->once())
			->method('carryMark')
			->with($arrived, 5, 'alice');

		$this->move($this->file(5, 'alice'), $this->destination('bob'), $arrived);
	}

	/** Nothing that stays with one owner is looked at - not even the mark table. */
	public function testAMoveWithinOneOwnersFilesIsNotLookedAt(): void {
		$this->watermarkService->expects($this->never())->method('markOrigins');
		$this->watermarkService->expects($this->never())->method('carryMark');

		$this->move($this->file(5, 'alice'), $this->destination('alice'), $this->file(5, 'alice', '/alice/files/report.pdf'));
	}

	public function testAnUnprotectedFileIsLeftAlone(): void {
		$this->watermarkService->method('markOrigins')->willReturn([]);
		$this->watermarkService->method('isForcedByShare')->willReturn(false);
		$this->watermarkService->expects($this->never())->method('carryMark');

		$this->move($this->file(5, 'alice'), $this->destination('bob'), $this->file(5, 'bob', '/bob/files/report.pdf'));
	}

	/** A folder moved out of a share carries each protected file in it, at any depth. */
	public function testAFolderMovedOutOfAShareCarriesEachProtectedFile(): void {
		$deep = $this->file(11, 'alice', '/bob/files/Proj/sub/a.pdf', 'a.pdf');
		$plain = $this->file(12, 'alice', '/bob/files/Proj/b.txt', 'b.txt');

		$sub = $this->createMock(Folder::class);
		$sub->method('getName')->willReturn('sub');
		$sub->method('getDirectoryListing')->willReturn([$deep]);

		$source = $this->createMock(Folder::class);
		$source->method('getPath')->willReturn('/bob/files/Proj');
		$source->method('getOwner')->willReturn($this->user('alice'));
		$source->method('getDirectoryListing')->willReturn([$sub, $plain]);

		$deepArrived = $this->file(11, 'bob', '/bob/files/Moved/sub/a.pdf', 'a.pdf');
		$arrived = $this->createMock(Folder::class);
		$arrived->method('get')->willReturnCallback(
			static fn (string $relative): Node => $relative === 'sub/a.pdf' ? $deepArrived : throw new \RuntimeException('not asked for'),
		);

		$this->watermarkService->method('markOrigins')->with([12, 11])->willReturn([]);
		$this->watermarkService->method('isForcedByShare')
			->willReturnCallback(static fn (File $file): bool => $file->getId() === 11);
		$this->watermarkService->expects($this->once())
			->method('carryMark')
			->with($deepArrived, 11, 'alice');

		$this->move($source, $this->destination('bob'), $arrived);
	}

	/** A decision is kept for its own move only. */
	public function testACompletedMoveOfSomethingElseCarriesNothing(): void {
		$this->watermarkService->method('markOrigins')->willReturn([]);
		$this->watermarkService->method('isForcedByShare')->willReturn(true);
		$this->watermarkService->expects($this->never())->method('carryMark');

		$this->listener->handle(new BeforeNodeRenamedEvent($this->file(5, 'alice'), $this->destination('bob')));
		$this->listener->handle(new NodeRenamedEvent($this->gone('/bob/files/Proj/other.pdf'), $this->file(5, 'bob')));
	}

	/** The move stands whatever this decides; a failure is logged, never thrown. */
	public function testAFailureIsLoggedRatherThanFailingTheMove(): void {
		$this->watermarkService->method('markOrigins')->willThrowException(new \RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('warning');

		$this->listener->handle(new BeforeNodeRenamedEvent($this->file(5, 'alice'), $this->destination('bob')));
	}
}
