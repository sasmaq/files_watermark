<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\Service\FileTooLargeException;
use OCA\FilesWatermark\Service\WatermarkService;
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
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private NodeWrittenListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new NodeWrittenListener(
			$this->watermarkService,
			$this->userSession,
			$this->logger,
		);
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

	public function testMarksTheFileWhenTheTriggerIsOnUpload(): void {
		$file = $this->file();
		$alice = $this->loginAlice();
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);

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
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->watermarkService->method('mark')->willReturn(false);

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
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->watermarkService->method('mark')
			->willThrowException(new FileTooLargeException('This file is too large to watermark.'));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not mark'), $this->anything());

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
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('effectiveTrigger')
			->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);

		$this->watermarkService->expects($this->once())
			->method('mark')
			->with($this->anything(), WatermarkService::TRIGGER_ON_UPLOAD, null)
			->willReturn(true);

		$this->listener->handle(new NodeWrittenEvent($this->file()));
	}

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->watermarkService->expects($this->never())->method('isSupported');

		$this->listener->handle(new \OCP\EventDispatcher\Event());
	}
}
