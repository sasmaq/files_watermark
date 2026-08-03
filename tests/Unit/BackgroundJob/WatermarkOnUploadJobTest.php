<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\BackgroundJob;

use OCA\FilesWatermark\BackgroundJob\WatermarkOnUploadJob;
use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\Service\OriginalStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The out-of-band half of on-upload watermarking.
 *
 * This job runs under cron with **no session and no request**, against a file that was
 * written some time ago, so everything it needs has to survive in two ints' worth of
 * argument: a file id and a uid. Both can have gone stale by the time cron gets here - the
 * account deleted, the file deleted, moved or replaced - and neither may take the cron run
 * down with it. `Job::start()` swallows throwables, but only after logging them as a cron
 * failure; a job that routinely throws would bury real failures in that noise.
 *
 * `run()` is exercised directly rather than through `start()`, which reaches
 * `\OCP\Server::get(LoggerInterface::class)` for its own logging and needs a real server
 * container. What is under test here is the job body, which is where all of the above lives.
 */
class WatermarkOnUploadJobTest extends TestCase {

	private IRootFolder&MockObject $rootFolder;
	private IUserManager&MockObject $userManager;
	private WatermarkService&MockObject $watermarkService;
	private LoggerInterface&MockObject $logger;
	private WatermarkOnUploadJob $job;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new WatermarkOnUploadJob(
			$this->createMock(ITimeFactory::class),
			$this->rootFolder,
			$this->userManager,
			$this->watermarkService,
			$this->logger,
		);
	}

	/** @param array<string, mixed> $argument */
	private function runJob(array $argument = ['fileId' => 42, 'uid' => 'alice']): void {
		(new \ReflectionMethod(WatermarkOnUploadJob::class, 'run'))->invoke($this->job, $argument);
	}

	private function user(string $uid = 'alice'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	/** The uploader exists and the file is still there, which is the ordinary case. */
	private function expectResolvable(int $fileId = 42, string $uid = 'alice'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getPath')->willReturn("/$uid/files/report.pdf");

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with($fileId)->willReturn([$file]);

		$this->userManager->method('get')->with($uid)->willReturn($this->user($uid));
		// Through the *uploader's* folder, not the root: that is what brings their mounts up
		// and returns the node on the storage they wrote to.
		$this->rootFolder->expects($this->once())
			->method('getUserFolder')
			->with($uid)
			->willReturn($userFolder);

		return $file;
	}

	public function testWatermarksTheFileAsTheUploader(): void {
		$file = $this->expectResolvable();

		$this->watermarkService->expects($this->once())
			->method('watermarkInPlace')
			// The acting user is passed explicitly because there is no session here: without
			// it {username} renders as "Unknown" and the audit row is attributed to "system".
			// The null config means "resolve the current policy", not "no policy".
			->with($file, 'on_upload', null, $this->isInstanceOf(IUser::class))
			->willReturn(true);

		$this->runJob();
	}

	/**
	 * The account was deleted between the upload and cron. `getUserFolder()` on an unknown
	 * uid throws deep in the mount setup, so the check has to come first.
	 */
	public function testUnknownUserIsSkippedWithoutTouchingTheFilesystem(): void {
		$this->userManager->method('get')->willReturn(null);

		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->watermarkService->expects($this->never())->method('watermarkInPlace');
		$this->logger->expects($this->once())->method('warning');

		$this->runJob();
	}

	/**
	 * A job argument that is missing its keys resolves to uid '' and file id 0, and must take
	 * the same skip rather than fatal on a null argument.
	 */
	public function testMalformedArgumentIsSkippedRatherThanFatal(): void {
		$this->userManager->expects($this->once())->method('get')->with('')->willReturn(null);
		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$this->runJob([]);
	}

	/** Deleted, or moved out of the uploader's reach, between the upload and the job. */
	public function testDeletedFileIsSkipped(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);
		$this->userManager->method('get')->willReturn($this->user());
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->watermarkService->expects($this->never())->method('watermarkInPlace');
		$this->logger->expects($this->once())->method('info');

		$this->runJob();
	}

	/**
	 * File ids are unique across nodes, so a stale id can come back as a directory - a file
	 * deleted and a folder created in its place. `watermarkInPlace()` takes a File.
	 */
	public function testAFolderUnderTheSameIdIsSkipped(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$this->createMock(Folder::class)]);
		$this->userManager->method('get')->willReturn($this->user());
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$this->runJob();
	}

	/**
	 * A render that fails is logged and swallowed. Letting it out would land in cron's own
	 * error path, where one unreadable PDF reads like a broken job class.
	 */
	public function testAFailedWatermarkIsLoggedAndDoesNotEscape(): void {
		$this->expectResolvable();
		$this->watermarkService->method('watermarkInPlace')
			->willThrowException(new \RuntimeException('GD could not decode this image/png file'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('GD could not decode'),
				// The context is what makes the line actionable: which file, whose.
				$this->callback(static fn (array $context): bool
				=> $context['fileId'] === 42 && $context['uid'] === 'alice'),
			);

		$this->runJob();
	}

	/**
	 * The burn writes the file, which fires `NodeWrittenEvent` again. Without the suppression
	 * window that second write queues another job for the file being watermarked right now,
	 * and that job's burn queues a third - this is the loop `suppressFor()` exists to break.
	 *
	 * Asserted by driving a real {@see NodeWrittenListener} from inside the write, which is
	 * the only way to observe the window from outside: the suppression list is private
	 * static state with no reader.
	 */
	public function testTheBurnRunsInsideTheSuppressionWindow(): void {
		$file = $this->expectResolvable();
		$file->method('getMimeType')->willReturn('application/pdf');

		$listenerService = $this->createMock(WatermarkService::class);
		$listenerService->method('isSupported')->willReturn(true);
		$originalStore = $this->createMock(OriginalStore::class);
		$originalStore->method('isBackup')->willReturn(false);
		$jobList = $this->createMock(IJobList::class);

		$listener = new NodeWrittenListener(
			$listenerService,
			$originalStore,
			$this->createMock(IUserSession::class),
			$jobList,
			$this->createMock(LoggerInterface::class),
		);

		// Nothing may be queued for this file while its own watermark is being written, and
		// the listener must bail at the suppression check - before it decides the user has
		// replaced the content, which is what noteContentReplaced() would record.
		$jobList->expects($this->never())->method('add');
		$listenerService->expects($this->never())->method('noteContentReplaced');

		$this->watermarkService->method('watermarkInPlace')
			->willReturnCallback(static function () use ($listener, $file): bool {
				$listener->handle(new NodeWrittenEvent($file));
				return true;
			});

		$this->runJob();
	}

	/**
	 * ...and the window closes. A later write to the same file - the user overwriting it
	 * themselves - has to be seen, or the app stops noticing that its watermark is gone.
	 */
	public function testTheWindowClosesWhenTheJobIsDone(): void {
		$file = $this->expectResolvable();
		$file->method('getMimeType')->willReturn('application/pdf');
		$this->watermarkService->method('watermarkInPlace')->willReturn(true);

		$this->runJob();

		$listenerService = $this->createMock(WatermarkService::class);
		$listenerService->method('isSupported')->willReturn(true);
		$originalStore = $this->createMock(OriginalStore::class);
		$originalStore->method('isBackup')->willReturn(false);

		$listener = new NodeWrittenListener(
			$listenerService,
			$originalStore,
			$this->createMock(IUserSession::class),
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class),
		);

		$listenerService->expects($this->once())->method('noteContentReplaced')->with($file);

		$listener->handle(new NodeWrittenEvent($file));
	}
}
