<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\Service\FileTooLargeException;
use OCA\FilesWatermark\Service\FolderMarker;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The upload trigger, which is now one method call inside the write event.
 *
 * What this file no longer tests is the more interesting half. The listener used to queue
 * a background job, because the watermark was burned into the file and `NodeWrittenEvent`
 * fires while the write still holds a lock on the node - so a second DAV plugin existed to
 * beat cron to it, and a static suppression map existed to stop this app's own writes
 * re-triggering the listener. All of it is gone: a mark takes no lock and writes no bytes.
 */
class NodeWrittenListenerTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private FolderMarker&MockObject $folderMarker;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private NodeWrittenListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->folderMarker = $this->createMock(FolderMarker::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new NodeWrittenListener(
			$this->watermarkService,
			$this->folderMarker,
			$this->userSession,
			$this->logger,
		);
	}

	private function folder(string $path): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn($path);
		return $folder;
	}

	private function folderMoved(string $from, Folder $to): NodeRenamedEvent {
		return new NodeRenamedEvent($this->folder($from), $to);
	}

	private function file(string $mime = 'application/pdf'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getId')->willReturn(42);
		$file->method('getPath')->willReturn('/alice/files/report.pdf');
		return $file;
	}

	private function loginAlice(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}

	/**
	 * An `on_upload` policy, a supported type, and a file the policy's scope covers - the
	 * shape every marking case starts from.
	 */
	private function onUploadInScope(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->watermarkService->method('isInPolicyScope')->willReturn(true);
	}

	private function moved(File|Folder $target): NodeRenamedEvent {
		return new NodeRenamedEvent($this->createMock(File::class), $target);
	}

	public function testMarksTheFileWhenTheTriggerIsOnUpload(): void {
		$file = $this->file();
		$alice = $this->loginAlice();
		$this->onUploadInScope();

		$this->watermarkService->expects($this->once())
			->method('mark')
			->with($file, WatermarkService::TRIGGER_ON_UPLOAD, $alice)
			->willReturn(true);

		$this->listener->handle(new NodeWrittenEvent($file));
	}

	public function testMarksNothingUnderTheOnDemandPolicy(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_DEMAND);

		$this->watermarkService->expects($this->never())->method('mark');

		$this->listener->handle(new NodeWrittenEvent($this->file()));
	}

	/**
	 * A trigger this version does not have marks nothing.
	 *
	 * `effectiveTrigger()` answers null for a policy row left behind by an older version,
	 * and null must not fall through to marking - it would silently mark every upload on
	 * the instance under a policy nobody chose.
	 */
	public function testAnUnrecognisedPolicyMarksNothing(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')->willReturn(null);

		$this->watermarkService->expects($this->never())->method('mark');

		$this->listener->handle(new NodeWrittenEvent($this->file()));
	}

	public function testAnUnsupportedTypeIsSkippedBeforeThePolicyIsResolved(): void {
		$this->watermarkService->method('isSupported')->willReturn(false);
		$this->watermarkService->expects($this->never())->method('effectiveTrigger');
		$this->watermarkService->expects($this->never())->method('mark');

		$this->listener->handle(new NodeWrittenEvent($this->file('text/plain')));
	}

	public function testAFolderWriteIsIgnored(): void {
		$this->watermarkService->expects($this->never())->method('isSupported');

		$this->listener->handle(new NodeWrittenEvent($this->createMock(Folder::class)));
	}

	/**
	 * An overwrite of an already-marked file leaves the mark standing.
	 *
	 * The mark is a policy on the file id, not a claim about a particular set of bytes, so
	 * `mark()` returning false is the ordinary outcome for every write after the first -
	 * and it must not be treated as a failure or logged as one.
	 */
	public function testAnOverwriteOfAMarkedFileIsQuietlyANoOp(): void {
		$this->loginAlice();
		$this->onUploadInScope();
		$this->watermarkService->method('isMarked')->with(42)->willReturn(true);

		$this->watermarkService->expects($this->never())->method('mark');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle(new NodeWrittenEvent($this->file()));
	}

	/**
	 * An upload the scope does not cover is the policy not being about that file. Under a
	 * folder tag that is most uploads on the instance, and each one used to log a warning
	 * that the file "could not" be marked.
	 */
	public function testAnUploadOutsideTheScopeIsNeitherMarkedNorLogged(): void {
		$this->loginAlice();
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->watermarkService->method('isInPolicyScope')->willReturn(false);

		$this->watermarkService->expects($this->never())->method('mark');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle(new NodeWrittenEvent($this->file()));
	}

	/**
	 * **An upload that cannot be marked still succeeds**, and the log line is the only
	 * record that a policy did not apply.
	 *
	 * Nothing downstream refuses the file later - it is unmarked, so it downloads exactly
	 * as it was uploaded - so an exception escaping here would fail the user's upload over
	 * a watermark, which is the one outcome that loses their data.
	 */
	public function testAnUnmarkableUploadIsLoggedAndTheWriteStillSucceeds(): void {
		$this->loginAlice();
		$this->onUploadInScope();
		$this->watermarkService->method('mark')
			->willThrowException(new FileTooLargeException('This file is too large to watermark.'));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not mark'), $this->callback(
				static fn (array $context): bool => $context['cause'] === 'upload',
			));

		$this->listener->handle(new NodeWrittenEvent($this->file()));
	}

	/**
	 * A write with no session still marks, attributed to nobody in particular.
	 *
	 * It used to bail out here, because the queued job needed a uid to re-resolve the node
	 * and the burn needed someone to name in the watermark. Neither is true now: the mark
	 * is a row against a file id, and the watermark names whoever *fetches* the file rather
	 * than whoever wrote it. Declining would leave files written by `occ` or by another app
	 * silently outside a policy that says "every supported file".
	 */
	public function testAWriteWithNoSessionIsStillMarked(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->onUploadInScope();

		$this->watermarkService->expects($this->once())
			->method('mark')
			->with($this->anything(), WatermarkService::TRIGGER_ON_UPLOAD, null)
			->willReturn(true);

		$this->listener->handle(new NodeWrittenEvent($this->file()));
	}

	/**
	 * **A file moved into scope is marked**, under the upload trigger.
	 *
	 * A move writes nothing, so `NodeWrittenEvent` never heard it: a PDF dragged into the
	 * tagged folder, or `report.bin` renamed to `report.pdf`, stayed unmarked under a policy
	 * that says every file in scope is. What is marked is the target - the file as it is
	 * now, which is what the scope test reads.
	 */
	public function testAFileMovedIntoScopeIsMarked(): void {
		$target = $this->file();
		$alice = $this->loginAlice();
		$this->onUploadInScope();

		$this->watermarkService->expects($this->once())
			->method('isInPolicyScope')
			->with($target);
		$this->watermarkService->expects($this->once())
			->method('mark')
			->with($target, WatermarkService::TRIGGER_ON_UPLOAD, $alice)
			->willReturn(true);

		$this->listener->handle($this->moved($target));
	}

	/**
	 * Most moves under a folder-tag policy land outside the tagged folder. That is not a file
	 * that could not be marked, and it must not log as one.
	 */
	public function testAMoveOutsideTheScopeIsNeitherMarkedNorLogged(): void {
		$this->loginAlice();
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->watermarkService->method('isInPolicyScope')->willReturn(false);

		$this->watermarkService->expects($this->never())->method('mark');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle($this->moved($this->file()));
	}

	/**
	 * The ordinary move: a file the policy marked when it arrived. The scope is not even
	 * asked - it costs a query over every tagged object, and the answer changes nothing.
	 */
	public function testAMoveOfAMarkedFileAsksNothingFurther(): void {
		$this->loginAlice();
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->watermarkService->method('isMarked')->with(42)->willReturn(true);

		$this->watermarkService->expects($this->never())->method('isInPolicyScope');
		$this->watermarkService->expects($this->never())->method('mark');

		$this->listener->handle($this->moved($this->file()));
	}

	public function testAMoveMarksNothingUnderTheOnDemandPolicy(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_DEMAND);

		$this->watermarkService->expects($this->never())->method('mark');

		$this->listener->handle($this->moved($this->file()));
	}

	/**
	 * A moved folder's files keep their parent and their names, so the move cannot have
	 * brought any of them into scope - and walking every moved tree would make each folder
	 * move on the instance cost a listing per subfolder.
	 */
	/**
	 * **A folder moved inside the tagged folder has its tree marked**, since the tag scopes
	 * every file below it. The walk is the folder marker's, which does part of it here and
	 * queues the rest; this listener only decides that it is needed.
	 */
	public function testAFolderMovedIntoTheTaggedFolderHasItsTreeMarked(): void {
		$alice = $this->loginAlice();
		$this->watermarkService->method('effectiveTrigger')->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$target = $this->folder('/alice/files/Tagged/Q3');
		$this->watermarkService->method('isInsidePolicyFolderTag')->with($target)->willReturn(true);

		$this->folderMarker->expects($this->once())
			->method('markTreeOrQueue')
			->with($target, $alice, 'move');
		$this->watermarkService->expects($this->never())->method('isSupported');

		$this->listener->handle($this->folderMoved('/alice/files/Inbox/Q3', $target));
	}

	public function testAFolderMovedOutsideTheTaggedFolderIsNotWalked(): void {
		$this->watermarkService->method('effectiveTrigger')->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->watermarkService->method('isInsidePolicyFolderTag')->willReturn(false);

		$this->folderMarker->expects($this->never())->method('markTreeOrQueue');

		$this->listener->handle($this->folderMoved('/alice/files/Inbox/Q3', $this->folder('/alice/files/Archive/Q3')));
	}

	/**
	 * A rename in place keeps every ancestor, so nothing below the folder can have come into
	 * scope - and renaming a big folder inside the tagged one must not walk it each time.
	 */
	public function testAFolderRenamedInPlaceIsNotWalked(): void {
		$this->watermarkService->method('effectiveTrigger')->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);

		$this->watermarkService->expects($this->never())->method('isInsidePolicyFolderTag');
		$this->folderMarker->expects($this->never())->method('markTreeOrQueue');

		$this->listener->handle($this->folderMoved('/alice/files/Tagged/Q3', $this->folder('/alice/files/Tagged/Q3-final')));
	}

	public function testAFolderMoveUnderTheOnDemandPolicyIsNotWalked(): void {
		$this->watermarkService->method('effectiveTrigger')->willReturn(WatermarkService::TRIGGER_ON_DEMAND);

		$this->watermarkService->expects($this->never())->method('isInsidePolicyFolderTag');
		$this->folderMarker->expects($this->never())->method('markTreeOrQueue');

		$this->listener->handle($this->folderMoved('/alice/files/Inbox/Q3', $this->folder('/alice/files/Tagged/Q3')));
	}

	/** The move has happened; a walk that fails is a log line, never an error for the mover. */
	public function testAFolderWalkThatFailsIsLoggedAndTheMoveStillSucceeds(): void {
		$this->watermarkService->method('effectiveTrigger')->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->watermarkService->method('isInsidePolicyFolderTag')->willReturn(true);
		$this->folderMarker->method('markTreeOrQueue')->willThrowException(new \RuntimeException('listing failed'));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('moved folder'), $this->anything());

		$this->listener->handle($this->folderMoved('/alice/files/Inbox/Q3', $this->folder('/alice/files/Tagged/Q3')));
	}

	/**
	 * **The move still stands.** It has happened by the time this event fires; throwing would
	 * only turn it into an error. The log line says it was a move, not an upload.
	 */
	public function testAnUnmarkableMoveIsLoggedAndTheMoveStillSucceeds(): void {
		$this->loginAlice();
		$this->onUploadInScope();
		$this->watermarkService->method('mark')
			->willThrowException(new FileTooLargeException('This file is too large to watermark.'));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not mark'), $this->callback(
				static fn (array $context): bool => $context['cause'] === 'move',
			));

		$this->listener->handle($this->moved($this->file()));
	}

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->watermarkService->expects($this->never())->method('isSupported');

		$this->listener->handle(new \OCP\EventDispatcher\Event());
	}
}
