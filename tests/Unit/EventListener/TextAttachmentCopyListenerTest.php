<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\TextAttachmentCopyListener;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener that keeps a note's attachments from being shed by copying the note.
 *
 * Text writes the attachments' copies itself, with `newFile()`, so they arrive under new ids
 * with no copy event - and before this listener, unmarked. The fixtures lay out what Text
 * reads and writes: `.attachments.{id}` beside each document in its owner's folder.
 */
class TextAttachmentCopyListenerTest extends TestCase {

	private const SOURCE_ID = 10;
	private const TARGET_ID = 20;

	private IRootFolder&MockObject $rootFolder;
	private WatermarkService&MockObject $watermarkService;
	private IUserSession&MockObject $userSession;
	private ShareAccess $shareAccess;
	private LoggerInterface&MockObject $logger;
	private TextAttachmentCopyListener $listener;

	/** @var array<string, array<int, File>> what each user's folder finds by id */
	private array $byId = [];

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->shareAccess = new ShareAccess($this->userSession);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->rootFolder->method('getUserFolder')->willReturnCallback(function (string $uid): Folder {
			$folder = $this->createMock(Folder::class);
			$folder->method('getFirstNodeById')->willReturnCallback(
				fn (int $id): ?File => $this->byId[$uid][$id] ?? null,
			);
			return $folder;
		});

		$this->listener = new TextAttachmentCopyListener(
			$this->rootFolder,
			$this->watermarkService,
			$this->shareAccess,
			$this->logger,
		);
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function file(int $id, string $owner, string $name, string $mime = 'image/png', string $path = ''): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getOwner')->willReturn($this->user($owner));
		$file->method('getPath')->willReturn($path !== '' ? $path : '/' . $owner . '/files/' . $name);
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);
		$file->method('getStorage')->willReturn($storage);
		return $file;
	}

	/**
	 * A Markdown document in $owner's folder, with $attachments in its `.attachments.{id}`.
	 *
	 * @param list<File> $attachments null for a document with no attachment folder
	 */
	private function document(int $id, string $owner, ?array $attachments, string $path = ''): File&MockObject {
		$document = $this->file($id, $owner, 'note.md', 'text/markdown', $path);

		$dir = null;
		if ($attachments !== null) {
			$dir = $this->createMock(Folder::class);
			$dir->method('getDirectoryListing')->willReturn($attachments);
			$byName = [];
			foreach ($attachments as $attachment) {
				$byName[$attachment->getName()] = $attachment;
			}
			$dir->method('get')->willReturnCallback(
				fn (string $name): File => $byName[$name] ?? throw new NotFoundException(),
			);
		}

		$parent = $this->createMock(Folder::class);
		$parent->method('get')->willReturnCallback(
			fn (string $name): Folder => $name === '.attachments.' . $id && $dir !== null
				? $dir
				: throw new NotFoundException(),
		);

		// Text finds the owner's view of the document by id; so does this.
		$owners = $this->file($id, $owner, 'note.md', 'text/markdown');
		$owners->method('getParent')->willReturn($parent);
		$this->byId[$owner][$id] = $owners;

		return $document;
	}

	private function copy(File $source, File $target): void {
		$this->listener->handle(new NodeCopiedEvent($source, $target));
	}

	public function testIgnoresEventsItIsNotFor(): void {
		$this->watermarkService->expects($this->never())->method('markedFileIds');

		$this->listener->handle($this->createMock(Event::class));
		$this->listener->handle(new NodeWrittenEvent($this->file(1, 'alice', 'a.png')));
	}

	/**
	 * Text copies attachments for a Markdown file only, so a copy of anything else is not
	 * looked at - not even to find an attachment folder.
	 */
	public function testACopyOfAnythingButMarkdownIsLeftAlone(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->copy($this->file(1, 'alice', 'a.pdf', 'application/pdf'), $this->file(2, 'alice', 'b.pdf', 'application/pdf'));
	}

	/**
	 * The whole point: the attachment Text copied beside the new note keeps the mark the
	 * original carries.
	 */
	public function testTheCopyOfAMarkedAttachmentIsMarked(): void {
		$image = $this->file(101, 'alice', 'image.png');
		$imageCopy = $this->file(201, 'alice', 'image.png');
		$source = $this->document(self::SOURCE_ID, 'alice', [$image]);
		$target = $this->document(self::TARGET_ID, 'alice', [$imageCopy]);

		$this->watermarkService->method('markedFileIds')->with([101])->willReturn([101]);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($imageCopy, $image);

		$this->copy($source, $target);
	}

	/**
	 * Paired by name, one query for the folder, and only what is protected is touched.
	 */
	public function testOnlyTheMarkedAttachmentsCopiesAreMarked(): void {
		$marked = $this->file(101, 'alice', 'marked.png');
		$plain = $this->file(102, 'alice', 'plain.png');
		$markedCopy = $this->file(201, 'alice', 'marked.png');
		$plainCopy = $this->file(202, 'alice', 'plain.png');
		$source = $this->document(self::SOURCE_ID, 'alice', [$marked, $plain]);
		$target = $this->document(self::TARGET_ID, 'alice', [$plainCopy, $markedCopy]);

		$this->watermarkService->expects($this->once())
			->method('markedFileIds')
			->with([101, 102])
			->willReturn([101]);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($markedCopy, $marked);

		$this->copy($source, $target);
	}

	/**
	 * **Through a single-file share of the note**, where the recipient could never list the
	 * folder the attachments came from. Text reads them from the owner's folder regardless.
	 */
	public function testARecipientsCopyOfASharedNoteCarriesTheMarks(): void {
		$image = $this->file(101, 'alice', 'image.png');
		$imageCopy = $this->file(201, 'bob', 'image.png');
		$source = $this->document(self::SOURCE_ID, 'alice', [$image], '/bob/files/note.md');
		$target = $this->document(self::TARGET_ID, 'bob', [$imageCopy]);

		$this->watermarkService->method('markedFileIds')->willReturn([101]);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($imageCopy, $image);

		$this->copy($source, $target);
	}

	/**
	 * A note copied inside its owner's own files never asks the share question - the switch
	 * cannot apply to a copy that stays with its owner.
	 */
	public function testACopyThatStaysWithItsOwnerNeverAsksTheShareQuestion(): void {
		$source = $this->document(self::SOURCE_ID, 'alice', [$this->file(101, 'alice', 'image.png')]);
		$target = $this->document(self::TARGET_ID, 'alice', [$this->file(201, 'alice', 'image.png')]);

		$this->watermarkService->method('markedFileIds')->willReturn([]);
		$this->watermarkService->expects($this->never())->method('isForcedByShare');
		$this->watermarkService->expects($this->never())->method('inheritMark');

		$this->copy($source, $target);
	}

	/**
	 * **The share switch, when the recipient only ever reached the attachment through the
	 * note.** Text's media endpoint serves it to them as a share the storage cannot see, so
	 * that is how it is asked here - and only for this question: the copy request goes on
	 * without an indirect share noted.
	 */
	public function testAnAttachmentReachedOnlyThroughTheNoteIsAskedAsAnIndirectShare(): void {
		$image = $this->file(101, 'alice', 'image.png');
		$imageCopy = $this->file(201, 'bob', 'image.png');
		$source = $this->document(self::SOURCE_ID, 'alice', [$image], '/bob/files/note.md');
		$target = $this->document(self::TARGET_ID, 'bob', [$imageCopy]);

		$this->watermarkService->method('markedFileIds')->willReturn([]);
		$this->watermarkService->method('isForcedByShare')->willReturnCallback(
			fn (File $node): bool => $node === $image && $this->shareAccess->isInternalShareAccess($node),
		);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($imageCopy, $image);

		$this->copy($source, $target);

		$this->assertFalse($this->shareAccess->isInternalShareAccess($image));
	}

	/**
	 * When a share mounts the attachment in the copier's own folder, that node is the one
	 * asked, as the media endpoint would ask it.
	 */
	public function testAnAttachmentTheCopierCanReachIsAskedAsTheyReachIt(): void {
		$image = $this->file(101, 'alice', 'image.png');
		$asBobSeesIt = $this->file(101, 'alice', 'image.png', 'image/png', '/bob/files/docs/.attachments.10/image.png');
		$this->byId['bob'][101] = $asBobSeesIt;
		$imageCopy = $this->file(201, 'bob', 'image.png');
		$source = $this->document(self::SOURCE_ID, 'alice', [$image], '/bob/files/docs/note.md');
		$target = $this->document(self::TARGET_ID, 'bob', [$imageCopy]);

		$this->watermarkService->method('markedFileIds')->willReturn([]);
		$this->watermarkService->expects($this->once())
			->method('isForcedByShare')
			->with($asBobSeesIt)
			->willReturn(true);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($imageCopy, $image);

		$this->copy($source, $target);
	}

	public function testANoteWithoutAttachmentsIsLeftAlone(): void {
		$source = $this->document(self::SOURCE_ID, 'alice', null);
		$target = $this->document(self::TARGET_ID, 'alice', null);

		$this->watermarkService->expects($this->never())->method('markedFileIds');

		$this->copy($source, $target);
	}

	/**
	 * One attachment Text did not copy does not cost the others their marks.
	 */
	public function testAnAttachmentWithNoCopyDoesNotStopTheRest(): void {
		$lost = $this->file(101, 'alice', 'lost.png');
		$found = $this->file(102, 'alice', 'found.png');
		$foundCopy = $this->file(202, 'alice', 'found.png');
		$source = $this->document(self::SOURCE_ID, 'alice', [$lost, $found]);
		$target = $this->document(self::TARGET_ID, 'alice', [$foundCopy]);

		$this->watermarkService->method('markedFileIds')->willReturn([101, 102]);

		$this->watermarkService->expects($this->once())
			->method('inheritMark')
			->with($foundCopy, $found);

		$this->copy($source, $target);
	}

	/**
	 * **The copy still stands.** The warning is the whole record that a protected attachment
	 * has an unmarked copy, so it is asserted.
	 */
	public function testAFailureIsLoggedAndDoesNotEscape(): void {
		$source = $this->document(self::SOURCE_ID, 'alice', [$this->file(101, 'alice', 'image.png')]);
		$target = $this->document(self::TARGET_ID, 'alice', [$this->file(201, 'alice', 'image.png')]);

		$this->watermarkService->method('markedFileIds')->willReturn([101]);
		$this->watermarkService->method('inheritMark')
			->willThrowException(new \RuntimeException('db is gone'));

		$this->logger->expects($this->once())->method('warning');

		$this->copy($source, $target);
	}
}
