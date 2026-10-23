<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\BackgroundJob;

use OCA\FilesWatermark\BackgroundJob\MarkFolderJob;
use OCA\FilesWatermark\Service\FolderMarker;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The rest of a folder tree too large to mark inside the request that brought it into scope.
 *
 * @covers \OCA\FilesWatermark\BackgroundJob\MarkFolderJob
 */
class MarkFolderJobTest extends TestCase {

	private FolderMarker&MockObject $folderMarker;
	private WatermarkService&MockObject $service;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->folderMarker = $this->createMock(FolderMarker::class);
		$this->service = $this->createMock(WatermarkService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function runJob(mixed $argument): void {
		$job = new MarkFolderJob(
			$this->createMock(ITimeFactory::class),
			$this->folderMarker,
			$this->service,
			$this->userManager,
			$this->logger,
		);

		// `run()` is protected; the job list calls it through `start()`, which needs a
		// whole job list to hand. Reflection is the narrower door.
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, $argument);
	}

	private function onUploadWith(?Folder $folder, bool $inside = true): void {
		$this->service->method('effectiveTrigger')->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->folderMarker->method('folderById')->with(7)->willReturn($folder);
		$this->service->method('isInsidePolicyFolderTag')->willReturn($inside);
	}

	/**
	 * **The whole tree, with no budget**, naming the user who tagged or moved the folder -
	 * the request already marked what it reached, and those cost one query per folder.
	 */
	public function testTheWholeTreeIsWalkedForTheUserWhoBroughtItIntoScope(): void {
		$folder = $this->createMock(Folder::class);
		$this->onUploadWith($folder);
		$alice = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($alice);

		$this->folderMarker->expects($this->once())
			->method('markTree')
			->with($folder, $alice, 'move', null);

		$this->runJob(['folderId' => 7, 'uid' => 'alice', 'cause' => 'move']);
	}

	public function testATreeQueuedWithoutASessionNamesNobody(): void {
		$folder = $this->createMock(Folder::class);
		$this->onUploadWith($folder);
		$this->userManager->expects($this->never())->method('get');

		$this->folderMarker->expects($this->once())
			->method('markTree')
			->with($folder, null, 'tag');

		$this->runJob(['folderId' => 7, 'uid' => null, 'cause' => 'tag']);
	}

	/** Back to `on_demand` before cron ran: nothing is marked. */
	public function testAPolicyNoLongerOnUploadMarksNothing(): void {
		$this->service->method('effectiveTrigger')->willReturn(WatermarkService::TRIGGER_ON_DEMAND);
		$this->folderMarker->expects($this->never())->method('folderById');

		$this->runJob(['folderId' => 7, 'uid' => 'alice', 'cause' => 'tag']);
	}

	/** The tag came off, or the folder was moved out of it, before cron ran. */
	public function testAFolderNoLongerInsideTheTagIsNotWalked(): void {
		$this->onUploadWith($this->createMock(Folder::class), inside: false);
		$this->folderMarker->expects($this->never())->method('markTree');

		$this->runJob(['folderId' => 7, 'uid' => 'alice', 'cause' => 'tag']);
	}

	public function testAFolderThatIsGoneIsSkipped(): void {
		$this->onUploadWith(null);
		$this->folderMarker->expects($this->never())->method('markTree');

		$this->runJob(['folderId' => 7, 'uid' => 'alice', 'cause' => 'tag']);
	}

	public function testAMalformedArgumentIsSkipped(): void {
		$this->service->method('effectiveTrigger')->willReturn(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->folderMarker->expects($this->never())->method('folderById');

		$this->runJob('7');
	}

	public function testAWalkThatFailsIsLogged(): void {
		$this->onUploadWith($this->createMock(Folder::class));
		$this->folderMarker->method('markTree')->willThrowException(new \RuntimeException('listing failed'));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not finish marking'), $this->anything());

		$this->runJob(['folderId' => 7, 'uid' => null, 'cause' => 'tag']);
	}
}
