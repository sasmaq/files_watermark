<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\BackgroundJob;

use OCA\FilesWatermark\BackgroundJob\MeasureDeliveryJob;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
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
	private WatermarkService&MockObject $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->service = $this->createMock(WatermarkService::class);
	}

	private function runJob(mixed $argument): void {
		$job = new MeasureDeliveryJob(
			$this->createMock(ITimeFactory::class),
			$this->rootFolder,
			$this->service,
			$this->createMock(LoggerInterface::class),
		);

		// `run()` is protected; the job list calls it through `start()`, which needs a
		// whole job list to hand. Reflection is the narrower door.
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, $argument);
	}

	public function testAMarkedFileIsMeasured(): void {
		$file = $this->createMock(File::class);
		$this->service->method('isMarked')->with(42)->willReturn(true);
		// A folder of the same id comes first in a mixed answer; only the file is measured.
		$this->rootFolder->method('getById')->with(42)->willReturn([$this->createMock(Folder::class), $file]);

		$this->service->expects($this->once())->method('measure')->with($file);

		$this->runJob(['fileId' => 42]);
	}

	/** Unmarked since it was queued: there is no watermarked download to promise a length for. */
	public function testAFileNoLongerMarkedIsSkipped(): void {
		$this->service->method('isMarked')->willReturn(false);
		$this->rootFolder->expects($this->never())->method('getById');
		$this->service->expects($this->never())->method('measure');

		$this->runJob(['fileId' => 42]);
	}

	public function testAFileThatIsGoneIsSkipped(): void {
		$this->service->method('isMarked')->willReturn(true);
		$this->rootFolder->method('getById')->willReturn([]);
		$this->service->expects($this->never())->method('measure');

		$this->runJob(['fileId' => 42]);
	}

	public function testAMalformedArgumentIsSkipped(): void {
		$this->service->expects($this->never())->method('isMarked');

		$this->runJob(null);
		$this->runJob(['fileId' => 'nope']);
	}
}
