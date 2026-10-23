<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\BackgroundJob\MarkFolderJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Marks the files a whole folder tree brings into the upload policy's scope at once: a
 * folder that has just been tagged, or moved inside a tagged one.
 *
 * ---------------------------------------------------------------------------
 * PART OF THE TREE IN THE REQUEST, THE REST IN A JOB.
 *
 * Each file costs what its upload did - a probe of up to 256 KiB, a few queries, an insert -
 * which is nothing for a project folder and a great deal for a photo library, on S3 an
 * object read per photo. Walked whole inside the tag or move request, a large tree ran long
 * enough to be killed partway, and what it had not reached stayed unmarked for good: nothing
 * comes back to finish it. So the request walks {@see INLINE_BUDGET} entries, which covers
 * the ordinary folder outright, and a larger tree is handed to {@see MarkFolderJob}, which
 * walks all of it again from the top. Already-marked files cost it one query per folder.
 *
 * The price is a window: the files the request did not reach are unmarked until cron runs.
 * Better than never, and the alternative - the whole tree inline - buys the same window
 * whenever the request is killed, with no job to close it.
 * ---------------------------------------------------------------------------
 *
 * **A mount inside the tree is not walked.** A share or an external storage mounted under the
 * folder is stored somewhere else, so the folder's tag does not reach it
 * ({@see FolderTagScope}); walking it would only spend the budget on files that are all out of
 * scope.
 */
class FolderMarker {

	/**
	 * Entries - files and folders - one request walks before the job takes over. Two hundred
	 * images is about a second on local disk and ten to twenty against S3.
	 */
	public const INLINE_BUDGET = 200;

	public function __construct(
		private WatermarkService $watermarkService,
		private IRootFolder $rootFolder,
		private IUserMountCache $mountCache,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Walk $folder in this request up to the budget, and queue the rest.
	 *
	 * @param string $cause what brought the tree into scope, for the log: `tag` or `move`
	 */
	public function markTreeOrQueue(Folder $folder, ?IUser $actor, string $cause): void {
		if ($this->markTree($folder, $actor, $cause, self::INLINE_BUDGET)) {
			return;
		}

		$this->jobList->add(MarkFolderJob::class, [
			'folderId' => $folder->getId(),
			'uid' => $actor?->getUID(),
			'cause' => $cause,
		]);
	}

	/**
	 * Mark every unmarked file in scope under $folder, at any depth.
	 *
	 * @param ?int $budget entries to visit before stopping; null walks the whole tree
	 * @return bool true when the whole tree was walked, false when the budget ran out first
	 */
	public function markTree(Folder $folder, ?IUser $actor, string $cause, ?int $budget = null): bool {
		/** @var list<Folder> $queue */
		$queue = [$folder];
		$visited = 0;

		while ($queue !== []) {
			$dir = array_pop($queue);
			$files = [];
			$stopped = false;

			foreach ($dir->getDirectoryListing() as $child) {
				if ($budget !== null && ++$visited > $budget) {
					$stopped = true;
					break;
				}

				if ($child instanceof Folder) {
					if ($child->getMountPoint()->getMountPoint() === $dir->getMountPoint()->getMountPoint()) {
						$queue[] = $child;
					}
				} elseif ($child instanceof File && $this->watermarkService->isSupported($child->getMimeType())) {
					$files[$child->getId()] = $child;
				}
			}

			// Marked even when the budget stopped the listing: the job walks it all again, but
			// these are already in hand.
			$this->markFiles($files, $actor, $cause);

			if ($stopped) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve a folder by id without a session.
	 *
	 * Through the mount cache, as {@see \OCA\FilesWatermark\BackgroundJob\MeasureDeliveryJob}
	 * does: `occ` and cron have no filesystem set up, and the root folder finds nothing by id
	 * there.
	 */
	public function folderById(int $id): ?Folder {
		foreach ($this->mountCache->getMountsForFileId($id) as $mount) {
			$node = $this->rootFolder->getUserFolder($mount->getUser()->getUID())->getFirstNodeById($id);
			if ($node !== null) {
				return $node instanceof Folder ? $node : null;
			}
		}

		return null;
	}

	/**
	 * @param array<int, File> $files by id, all of one folder
	 */
	private function markFiles(array $files, ?IUser $actor, string $cause): void {
		if ($files === []) {
			return;
		}

		// One query for the folder rather than one per file. After the first walk, and after
		// every upload the policy marked, this is usually all of them.
		$marked = $this->watermarkService->markedFileIds(array_keys($files));

		foreach ($files as $id => $file) {
			if (in_array($id, $marked, true)) {
				continue;
			}

			// One file that cannot be marked must not cost the rest of the tree theirs.
			try {
				// Still asked per file: the MIME whitelist is the other half of the scope.
				if (!$this->watermarkService->isInPolicyScope($file)) {
					continue;
				}

				$this->watermarkService->mark($file, WatermarkService::TRIGGER_ON_UPLOAD, $actor);
			} catch (\Throwable $e) {
				// Logged as an upload that could not be marked is
				// ({@see \OCA\FilesWatermark\EventListener\NodeWrittenListener}): the file is
				// served as stored, and this line is the whole record of it.
				$this->logger->warning('files_watermark: could not mark {path} on {cause}: {reason}', [
					'path' => $file->getPath(),
					'cause' => $cause,
					'reason' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
	}
}
