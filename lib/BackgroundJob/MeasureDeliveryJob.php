<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\BackgroundJob;

use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Measures a marked file's delivery length for its owner, off the request that marked it.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A JOB AND NOT PART OF MARKING.
 *
 * A measurement is a full render - and, with flattening on, a `pdftoppm` run per page. Done
 * inline it made every upload under `on_upload` render the file it had just written, and it
 * made a folder copy render every marked file in the tree inside the COPY request: a copy of
 * a few hundred protected documents ran long enough to be killed partway through, and the
 * copies after that point were left unmarked. Marking is now an insert, the measurement is
 * queued, and the copy walk cannot be cut short by its own rendering.
 *
 * The on-demand action still measures inline - it is one file, a person is waiting on the
 * answer, and the render doubles as the check that the file can be watermarked at all. See
 * {@see WatermarkService::mark()}.
 * ---------------------------------------------------------------------------
 *
 * Best-effort, like the inline measurement it replaces: a file that was deleted or unmarked
 * before cron reached it is skipped, and a render that fails costs the one self-correcting
 * first download it always did.
 */
class MeasureDeliveryJob extends QueuedJob {

	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private IUserMountCache $mountCache,
		private WatermarkService $watermarkService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument `['fileId' => int]`
	 */
	protected function run($argument): void {
		$fileId = is_array($argument) ? (int)($argument['fileId'] ?? 0) : 0;
		if ($fileId <= 0 || !$this->watermarkService->isMarked($fileId)) {
			return;
		}

		// **Not `IRootFolder::getById()`**, which answers nothing under cron - nobody's
		// filesystem is set up there, so the root cannot see into any home, and every job
		// returned as if its file were gone. The mount cache knows which users have the file,
		// and their own folder resolves it; for a shared file that may be a recipient's mount,
		// and the measurement still reads `getOwner()` for whom to measure.
		try {
			$file = null;
			foreach ($this->mountCache->getMountsForFileId($fileId) as $mount) {
				$node = $this->rootFolder->getUserFolder($mount->getUser()->getUID())->getFirstNodeById($fileId);
				if ($node !== null) {
					$file = $node instanceof File ? $node : null;
					break;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->info('files_watermark: could not resolve file {fileId} to measure it', [
				'fileId' => $fileId,
				'exception' => $e,
			]);
			return;
		}

		if ($file !== null) {
			$this->watermarkService->measure($file);
		}
	}
}
