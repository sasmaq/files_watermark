<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\BackgroundJob;

use OCA\FilesWatermark\BackgroundJob\MeasureDeliveryJob;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The measurement marking used to render inline - in the upload, and once per protected
 * file inside a folder COPY. It is queued now; these pin that the job measures what is
 * still marked and quietly skips what is not.
 *
 * @covers \OCA\FilesWatermark\BackgroundJob\MeasureDeliveryJob
 */
class MeasureDeliveryJobTest extends TestCase {

	private IRootFolder&MockObject $rootFolder;
	private IUserMountCache&MockObject $mountCache;
	private WatermarkService&MockObject $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->mountCache = $this->createMock(IUserMountCache::class);
		$this->service = $this->createMock(WatermarkService::class);
	}

	private function mountFor(string $uid): ICachedMountFileInfo {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$mount = $this->createMock(ICachedMountFileInfo::class);
		$mount->method('getUser')->willReturn($user);
		return $mount;
	}

	/** @param array<string, ?\OCP\Files\Node> $nodes what each user's own folder resolves 42 to */
	private function userFolders(array $nodes): void {
		$this->rootFolder->method('getUserFolder')->willReturnCallback(function (string $uid) use ($nodes): Folder {
			$folder = $this->createMock(Folder::class);
			$folder->method('getFirstNodeById')->with(42)->willReturn($nodes[$uid] ?? null);
			return $folder;
		});
	}

	private function runJob(mixed $argument): void {
		$job = new MeasureDeliveryJob(
			$this->createMock(ITimeFactory::class),
			$this->rootFolder,
			$this->mountCache,
			$this->service,
			$this->createMock(LoggerInterface::class),
		);

		// `run()` is protected; the job list calls it through `start()`, which needs a
		// whole job list to hand. Reflection is the narrower door.
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, $argument);
	}

	/**
	 * Under cron nobody's filesystem is set up, so the root's own `getById()` answers nothing
	 * and the job used to skip every file as gone. It has to go through a user who has the
	 * file mounted.
	 */
	public function testAMarkedFileIsResolvedThroughAUserWhoHasItMountedAndMeasured(): void {
		$file = $this->createMock(File::class);
		$this->service->method('isMarked')->with(42)->willReturn(true);
		$this->mountCache->method('getMountsForFileId')->with(42)->willReturn([$this->mountFor('alice')]);
		$this->userFolders(['alice' => $file]);
		$this->rootFolder->expects($this->never())->method('getById');

		$this->service->expects($this->once())->method('measure')->with($file);

		$this->runJob(['fileId' => 42]);
	}

	/** A mount whose folder cannot resolve the id is passed over for the next one. */
	public function testAMountThatCannotResolveTheFileIsPassedOver(): void {
		$file = $this->createMock(File::class);
		$this->service->method('isMarked')->willReturn(true);
		$this->mountCache->method('getMountsForFileId')->willReturn([$this->mountFor('bob'), $this->mountFor('alice')]);
		$this->userFolders(['bob' => null, 'alice' => $file]);

		$this->service->expects($this->once())->method('measure')->with($file);

		$this->runJob(['fileId' => 42]);
	}

	/** Unmarked since it was queued: there is no watermarked download to promise a length for. */
	public function testAFileNoLongerMarkedIsSkipped(): void {
		$this->service->method('isMarked')->willReturn(false);
		$this->mountCache->expects($this->never())->method('getMountsForFileId');
		$this->service->expects($this->never())->method('measure');

		$this->runJob(['fileId' => 42]);
	}

	public function testAFileThatIsGoneIsSkipped(): void {
		$this->service->method('isMarked')->willReturn(true);
		$this->mountCache->method('getMountsForFileId')->willReturn([]);
		$this->service->expects($this->never())->method('measure');

		$this->runJob(['fileId' => 42]);
	}

	public function testAnIdThatResolvesToAFolderIsSkipped(): void {
		$this->service->method('isMarked')->willReturn(true);
		$this->mountCache->method('getMountsForFileId')->willReturn([$this->mountFor('alice')]);
		$this->userFolders(['alice' => $this->createMock(Folder::class)]);
		$this->service->expects($this->never())->method('measure');

		$this->runJob(['fileId' => 42]);
	}

	public function testAResolutionThatThrowsIsSkipped(): void {
		$this->service->method('isMarked')->willReturn(true);
		$this->mountCache->method('getMountsForFileId')->willThrowException(new \RuntimeException('mount offline'));
		$this->service->expects($this->never())->method('measure');

		$this->runJob(['fileId' => 42]);
	}

	public function testAMalformedArgumentIsSkipped(): void {
		$this->service->expects($this->never())->method('isMarked');

		$this->runJob(null);
		$this->runJob(['fileId' => 'nope']);
	}
}
