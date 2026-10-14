<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\BackgroundJob;

use OCA\FilesWatermark\BackgroundJob\CleanupJob;
use OCA\FilesWatermark\Db\InList;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCA\FilesWatermark\Db\WatermarkRenditionMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Marks, reservations and logos only ever grew. These pin what the nightly sweep removes
 * and that it stops: in batches, and never more of them than one run is allowed.
 *
 * @covers \OCA\FilesWatermark\BackgroundJob\CleanupJob
 */
class CleanupJobTest extends TestCase {

	private WatermarkMarkMapper&MockObject $marks;
	private WatermarkRenditionMapper&MockObject $renditions;
	private WatermarkConfigMapper&MockObject $configs;
	private WatermarkImageStore&MockObject $images;
	private ITimeFactory&MockObject $time;

	protected function setUp(): void {
		parent::setUp();
		$this->marks = $this->createMock(WatermarkMarkMapper::class);
		$this->renditions = $this->createMock(WatermarkRenditionMapper::class);
		$this->configs = $this->createMock(WatermarkConfigMapper::class);
		$this->images = $this->createMock(WatermarkImageStore::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_800_000_000);
	}

	private function runJob(): void {
		$job = new CleanupJob(
			$this->time,
			$this->marks,
			$this->renditions,
			$this->configs,
			$this->images,
			$this->createMock(LoggerInterface::class),
		);

		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}

	/** A full batch means there may be more; a short one means the backlog is done. */
	public function testOrphansAreDeletedInBatchesUntilOneComesBackShort(): void {
		$this->marks->expects($this->exactly(3))
			->method('deleteOrphans')
			->willReturnOnConsecutiveCalls(InList::MAX, InList::MAX, 12);
		$this->renditions->expects($this->once())->method('deleteOrphans')->willReturn(0);
		$this->configs->method('findAll')->willReturn([]);

		$this->runJob();
	}

	/** A backlog bigger than one night is worked off across nights, not in one long run. */
	public function testOneRunStopsAtItsCeiling(): void {
		$this->marks->expects($this->exactly(100))->method('deleteOrphans')->willReturn(InList::MAX);
		$this->configs->method('findAll')->willReturn([]);

		$this->runJob();
	}

	/** The policy's own logo is kept, and so is anything uploaded in the last day. */
	public function testLogosArePrunedAroundTheOneInUse(): void {
		$config = new WatermarkConfig();
		$config->setImagePath(str_repeat('a', 32) . '.png');
		$textOnly = new WatermarkConfig();
		$this->configs->method('findAll')->willReturn([$config, $textOnly]);

		$this->images->expects($this->once())
			->method('deleteUnreferenced')
			->with([str_repeat('a', 32) . '.png'], 1_800_000_000 - 86400);

		$this->runJob();
	}
}
