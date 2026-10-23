<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\BackgroundJob;

use OCA\FilesWatermark\Service\FolderMarker;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Finishes marking a folder tree that was too large to mark inside the request that brought
 * it into scope - see {@see FolderMarker}.
 *
 * Walks the whole tree again, without a budget: what the request already marked costs one
 * query per folder, and the job has no request timeout to beat.
 *
 * **The policy is asked again, not remembered.** Between the request and cron the trigger may
 * have gone back to `on_demand`, the tag may have come off the folder, or the folder may have
 * been moved out of it; in each case nothing is marked. The mark names the user who tagged or
 * moved the folder, as it would have inline.
 */
class MarkFolderJob extends QueuedJob {

	public function __construct(
		ITimeFactory $time,
		private FolderMarker $folderMarker,
		private WatermarkService $watermarkService,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument `['folderId' => int, 'uid' => ?string, 'cause' => string]`
	 */
	protected function run($argument): void {
		$argument = is_array($argument) ? $argument : [];
		$folderId = (int)($argument['folderId'] ?? 0);
		if ($folderId <= 0 || $this->watermarkService->effectiveTrigger() !== WatermarkService::TRIGGER_ON_UPLOAD) {
			return;
		}

		$uid = is_string($argument['uid'] ?? null) ? $argument['uid'] : null;
		$cause = is_string($argument['cause'] ?? null) ? $argument['cause'] : 'tag';

		try {
			$folder = $this->folderMarker->folderById($folderId);
			if ($folder === null || !$this->watermarkService->isInsidePolicyFolderTag($folder)) {
				return;
			}

			$this->folderMarker->markTree($folder, $uid === null ? null : $this->userManager->get($uid), $cause);
		} catch (\Throwable $e) {
			$this->logger->warning('files_watermark: could not finish marking folder {fileId}: {reason}', [
				'fileId' => $folderId,
				'reason' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}
}
