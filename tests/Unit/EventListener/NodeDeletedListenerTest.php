<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\NodeDeletedListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A recipient deleting a protected file inside a folder shared with them, which puts a copy
 * of it in their own trash.
 *
 * @covers \OCA\FilesWatermark\EventListener\NodeDeletedListener
 */
class NodeDeletedListenerTest extends TestCase {

	private const SOURCE_PATH = '/bob/files/Proj/report.pdf';
	private const TIMESTAMP = 1792650000;
	private const COPY_PATH = '/bob/files_trashbin/files/report.pdf.d1792650000';

	private IRootFolder&MockObject $rootFolder;
	private WatermarkService&MockObject $watermarkService;
	private LoggerInterface&MockObject $logger;
	private NodeDeletedListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new NodeDeletedListener($this->rootFolder, $this->watermarkService, $this->logger);
		\OC_Hook::clear();
	}

	protected function tearDown(): void {
		\OC_Hook::clear();
		parent::tearDown();
	}

	/** What files_trashbin does between the two events, through the registry core uses. */
	private function trashHookFires(string $filePath, string $trashName): void {
		\OC_Hook::emit(NodeDeletedListener::TRASH_SIGNAL_CLASS, NodeDeletedListener::TRASH_SIGNAL_NAME, [
			'filePath' => $filePath,
			'trashPath' => '/' . $trashName . '.d' . self::TIMESTAMP,
		]);
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

	/** What the source is after the delete: a path to nothing. */
	private function gone(string $path = self::SOURCE_PATH): Node&MockObject {
		$source = $this->createMock(File::class);
		$source->method('getPath')->willReturn($path);
		return $source;
	}

	/** The trash copy at $path, or nothing there. */
	private function trashHolds(?Node $copy, string $path = self::COPY_PATH): void {
		$this->rootFolder->method('get')->willReturnCallback(
			static fn (string $asked): Node => $asked === $path && $copy !== null ? $copy : throw new NotFoundException($asked),
		);
	}

	/** The whole delete as core runs it: the event, the trash's hook, the event. */
	private function delete(Node $source, ?string $trashName = 'report.pdf'): void {
		$this->listener->handle(new BeforeNodeDeletedEvent($source));
		if ($trashName !== null) {
			$this->trashHookFires(substr($source->getPath(), strlen('/bob/files')), $trashName);
		}
		$this->listener->handle(new NodeDeletedEvent($this->gone($source->getPath())));
	}

	/** The hole: a marked file, deleted by a recipient, keeps its mark in their trash too. */
	public function testAMarkedFileDeletedByARecipientMarksTheirTrashCopy(): void {
		$copy = $this->file(42, 'bob', self::COPY_PATH, 'report.pdf.d' . self::TIMESTAMP);
		$this->trashHolds($copy);

		$this->watermarkService->method('markOrigins')->with([5])->willReturn([5 => 'alice']);
		$this->watermarkService->expects($this->once())
			->method('carryMark')
			->with($copy, 5, 'alice');

		$this->delete($this->file(5, 'alice'));
	}

	/** A mark recorded before origins were kept still names the owner, not the deleter. */
	public function testAMarkWithNoRecordedOriginNamesTheOwner(): void {
		$copy = $this->file(42, 'bob', self::COPY_PATH);
		$this->trashHolds($copy);

		$this->watermarkService->method('markOrigins')->willReturn([5 => null]);
		$this->watermarkService->expects($this->once())
			->method('carryMark')
			->with($copy, 5, 'alice');

		$this->delete($this->file(5, 'alice'));
	}

	/**
	 * The switch escape: watermarked only because it is in a share, and the trash copy is not
	 * in the share. Asked of the source, before it is gone.
	 */
	public function testAShareForcedFileDeletedByARecipientMarksTheirTrashCopy(): void {
		$source = $this->file(5, 'alice');
		$copy = $this->file(42, 'bob', self::COPY_PATH);
		$this->trashHolds($copy);

		$this->watermarkService->method('markOrigins')->willReturn([]);
		$this->watermarkService->method('isForcedByShare')->with($source)->willReturn(true);
		$this->watermarkService->expects($this->once())
			->method('carryMark')
			->with($copy, 5, 'alice');

		$this->delete($source);
	}

	public function testAnUnprotectedFileIsLeftAlone(): void {
		$this->watermarkService->method('markOrigins')->willReturn([]);
		$this->watermarkService->method('isForcedByShare')->willReturn(false);
		$this->rootFolder->expects($this->never())->method('get');
		$this->watermarkService->expects($this->never())->method('carryMark');

		$this->delete($this->file(5, 'alice'));
	}

	/** Core makes no copy for an owner's own delete, and nothing is looked up for one. */
	public function testAnOwnersOwnDeleteIsNotLookedAt(): void {
		$this->watermarkService->expects($this->never())->method('markOrigins');
		$this->watermarkService->expects($this->never())->method('isForcedByShare');
		$this->watermarkService->expects($this->never())->method('carryMark');

		$this->delete($this->file(5, 'bob'));
	}

	/** Trash disabled, or the file too big for it: the delete was permanent and made no copy. */
	public function testADeleteThatNeverReachedTheTrashCarriesNothing(): void {
		$this->watermarkService->method('markOrigins')->willReturn([5 => 'alice']);
		$this->rootFolder->expects($this->never())->method('get');
		$this->watermarkService->expects($this->never())->method('carryMark');

		$this->delete($this->file(5, 'alice'), null);
	}

	/** Core skips the copy when it would overrun the recipient's quota. */
	public function testNoCopyInTheRecipientsTrashMeansNothingToMark(): void {
		$this->trashHolds(null);
		$this->watermarkService->method('markOrigins')->willReturn([5 => 'alice']);
		$this->watermarkService->expects($this->never())->method('carryMark');
		$this->logger->expects($this->never())->method('warning');

		$this->delete($this->file(5, 'alice'));
	}

	/** A folder's copy is walked to each protected file in it, at any depth. */
	public function testAFolderDeletedByARecipientMarksEachProtectedFileInTheCopy(): void {
		$deep = $this->file(11, 'alice', '/bob/files/Proj/sub/a.pdf', 'a.pdf');
		$plain = $this->file(12, 'alice', '/bob/files/Proj/b.txt', 'b.txt');

		$sub = $this->createMock(Folder::class);
		$sub->method('getName')->willReturn('sub');
		$sub->method('getDirectoryListing')->willReturn([$deep]);

		$source = $this->createMock(Folder::class);
		$source->method('getPath')->willReturn('/bob/files/Proj');
		$source->method('getOwner')->willReturn($this->user('alice'));
		$source->method('getDirectoryListing')->willReturn([$sub, $plain]);

		$deepCopy = $this->file(51, 'bob', '/bob/files_trashbin/files/Proj.d' . self::TIMESTAMP . '/sub/a.pdf', 'a.pdf');
		$copy = $this->createMock(Folder::class);
		$copy->method('get')->willReturnCallback(
			static fn (string $relative): Node => $relative === 'sub/a.pdf' ? $deepCopy : throw new NotFoundException($relative),
		);
		$this->trashHolds($copy, '/bob/files_trashbin/files/Proj.d' . self::TIMESTAMP);

		$this->watermarkService->method('markOrigins')->with([12, 11])->willReturn([11 => 'alice']);
		$this->watermarkService->method('isForcedByShare')->willReturn(false);
		$this->watermarkService->expects($this->once())
			->method('carryMark')
			->with($deepCopy, 11, 'alice');

		$this->delete($source, 'Proj');
	}

	/** The copy is named after the deleter's path, cut down the way core cuts a long name. */
	public function testALongNameIsLookedUpUnderTheNameCoreGivesTheCopy(): void {
		$name = str_repeat('a', 300) . '.pdf';
		$source = $this->file(5, 'alice', '/bob/files/Proj/' . $name, $name);

		$this->watermarkService->method('markOrigins')->willReturn([5 => 'alice']);
		$this->rootFolder->expects($this->once())
			->method('get')
			->with($this->callback(static function (string $path): bool {
				$copyName = substr($path, strlen('/bob/files_trashbin/files/'));
				return str_starts_with($path, '/bob/files_trashbin/files/')
					&& strlen($copyName) === 250
					&& str_ends_with($copyName, '.pdf.d' . self::TIMESTAMP);
			}))
			->willThrowException(new NotFoundException());

		$this->delete($source, $name);
	}

	/** A decision is kept for its own delete only. */
	public function testTheTrashHookForAnotherPathCarriesNothing(): void {
		$this->watermarkService->method('markOrigins')->willReturn([5 => 'alice']);
		$this->rootFolder->expects($this->never())->method('get');
		$this->watermarkService->expects($this->never())->method('carryMark');

		$this->listener->handle(new BeforeNodeDeletedEvent($this->file(5, 'alice')));
		$this->trashHookFires('/Proj/other.pdf', 'other.pdf');
		$this->listener->handle(new NodeDeletedEvent($this->gone()));
	}

	/**
	 * `remote.php` never boots this app, so the hook cannot be connected at boot; the first
	 * event connects it. Only a delete worth following does, and only once.
	 */
	public function testTheTrashHookIsConnectedOnlyByADeleteWorthFollowing(): void {
		$this->watermarkService->method('markOrigins')->willReturnCallback(
			static fn (array $ids): array => $ids === [5] ? [5 => 'alice'] : [],
		);
		$this->watermarkService->method('isForcedByShare')->willReturn(false);

		$this->listener->handle(new BeforeNodeDeletedEvent($this->file(6, 'alice', '/bob/files/Proj/plain.pdf')));
		$this->assertFalse(\OC_Hook::emit(NodeDeletedListener::TRASH_SIGNAL_CLASS, NodeDeletedListener::TRASH_SIGNAL_NAME, []));

		$this->listener->handle(new BeforeNodeDeletedEvent($this->file(5, 'alice')));
		$this->listener->handle(new BeforeNodeDeletedEvent($this->file(5, 'alice', '/bob/files/Proj/again.pdf')));
		$this->assertTrue(\OC_Hook::emit(NodeDeletedListener::TRASH_SIGNAL_CLASS, NodeDeletedListener::TRASH_SIGNAL_NAME, []));
		$this->assertFalse(\OC_Hook::connect(NodeDeletedListener::TRASH_SIGNAL_CLASS, NodeDeletedListener::TRASH_SIGNAL_NAME, $this->listener, 'movedToTrash'));
	}

	/** The delete stands whatever this decides; a failure is logged, never thrown. */
	public function testAFailureIsLoggedRatherThanFailingTheDelete(): void {
		$this->watermarkService->method('markOrigins')->willThrowException(new \RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('warning');

		$this->listener->handle(new BeforeNodeDeletedEvent($this->file(5, 'alice')));
	}
}
