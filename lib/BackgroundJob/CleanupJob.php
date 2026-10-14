<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\BackgroundJob;

use OCA\FilesWatermark\Db\InList;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCA\FilesWatermark\Db\WatermarkRenditionMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Once a day: drop what nothing refers to any more.
 *
 * ---------------------------------------------------------------------------
 * THREE THINGS THAT ONLY EVER GREW.
 *
 * - **Marks** of files deleted for good. Remove is the only thing that deletes a mark, so a
 *   marked file that left the trash left its row behind.
 * - **Reservations** of those same files - one row per reader who ever downloaded one.
 * - **Logos** that were uploaded and then replaced, or uploaded and never saved.
 *
 * None of these is a correctness problem - a mark for a file id nothing has is matched by
 * nothing - but each is a table or a folder that grows for the life of the instance.
 * ---------------------------------------------------------------------------
 *
 * A file in the trash is still in the file cache and keeps its mark; that is deliberate,
 * because a trashed file can still be downloaded. A deleted *user's* reservations against
 * other people's files are dropped as the account goes, by
 * {@see \OCA\FilesWatermark\EventListener\UserDeletedListener}.
 *
 * Each sweep runs in batches of {@see InList::MAX} and stops after {@see MAX_BATCHES}, so a
 * backlog from a large instance is worked off across nights rather than in one long run.
 */
class CleanupJob extends TimedJob {

	private const INTERVAL_SECONDS = 86400;

	/** Batches per sweep per run: up to 100,000 rows of each kind a night. */
	private const MAX_BATCHES = 100;

	/** A logo younger than this may still be waiting for the save that will reference it. */
	private const LOGO_GRACE_SECONDS = 86400;

	public function __construct(
		ITimeFactory $time,
		private WatermarkMarkMapper $marks,
		private WatermarkRenditionMapper $renditions,
		private WatermarkConfigMapper $configs,
		private WatermarkImageStore $images,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument unused
	 */
	protected function run($argument): void {
		$marks = $this->drain($this->marks->deleteOrphans(...));
		$renditions = $this->drain($this->renditions->deleteOrphans(...));

		$keep = array_values(array_filter(array_map(
			static fn (WatermarkConfig $config): ?string => $config->getImagePath(),
			$this->configs->findAll(),
		)));
		$logos = $this->images->deleteUnreferenced($keep, $this->time->getTime() - self::LOGO_GRACE_SECONDS);

		if ($marks + $renditions + $logos > 0) {
			$this->logger->info('files_watermark: removed {marks} mark(s) and {renditions} reservation(s) of deleted files, and {logos} unused logo(s)', [
				'app' => 'files_watermark',
				'marks' => $marks,
				'renditions' => $renditions,
				'logos' => $logos,
			]);
		}
	}

	/**
	 * Run $batch until it comes back short of a full batch, or the per-run ceiling is reached.
	 *
	 * @param callable(): int $batch deletes up to one batch and returns how many it deleted
	 */
	private function drain(callable $batch): int {
		$total = 0;
		for ($i = 0; $i < self::MAX_BATCHES; $i++) {
			$deleted = $batch();
			$total += $deleted;
			if ($deleted < InList::MAX) {
				break;
			}
		}

		return $total;
	}
}
