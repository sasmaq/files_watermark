<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\BackgroundJob;

use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Burn the on-upload watermark into a freshly written file, out of band.
 *
 * The watermark cannot be applied from inside {@see \OCA\FilesWatermark\EventListener\NodeWrittenListener}:
 * `NodeWrittenEvent` fires while the write that triggered it still holds a lock on the
 * node, so writing the watermarked bytes back throws `LockedException`. That is not
 * specific to WebDAV — a plain `newFile()` through the Files API fails the same way — so
 * deferring to a job is what gets the write out from under the lock on every upload path.
 *
 * Running here also keeps rendering (which is slow for large PDFs) off the upload request.
 * The trade-off is that on-upload watermarking is eventually consistent: the file is clean
 * until cron picks the job up.
 */
class WatermarkOnUploadJob extends QueuedJob {

    public function __construct(
        ITimeFactory              $time,
        private IRootFolder       $rootFolder,
        private IUserManager      $userManager,
        private WatermarkService  $watermarkService,
        private LoggerInterface   $logger,
    ) {
        parent::__construct($time);
    }

    /**
     * @param array{fileId: int, uid: string} $argument
     */
    protected function run($argument): void {
        $fileId = (int)($argument['fileId'] ?? 0);
        $uid    = (string)($argument['uid'] ?? '');

        $user = $this->userManager->get($uid);
        if ($user === null) {
            $this->logger->warning('files_watermark: on-upload job skipped, unknown user {uid}', ['uid' => $uid]);
            return;
        }

        // Resolve through the user's own folder so the node comes back on the storage the
        // uploader sees, with their mounts set up — getById() on the root folder would not.
        $nodes = $this->rootFolder->getUserFolder($uid)->getById($fileId);
        $node  = $nodes[0] ?? null;

        if (!($node instanceof File)) {
            // Deleted or moved between the upload and the job running. Nothing to do.
            $this->logger->info('files_watermark: on-upload job skipped, file {fileId} is gone', ['fileId' => $fileId]);
            return;
        }

        try {
            // The burn writes the file, which fires NodeWrittenEvent again — suppressed so
            // it does not queue a follow-up job for the file we are already watermarking.
            NodeWrittenListener::suppressFor($fileId, function () use ($node, $user): void {
                // There is no session here, so the acting user is passed explicitly — otherwise
                // {username} renders as "Unknown" and the audit row is attributed to "system".
                $this->watermarkService->watermarkInPlace($node, 'on_upload', null, $user);
            });
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: on-upload watermark failed: ' . $e->getMessage(), [
                'exception' => $e,
                'fileId'    => $fileId,
                'uid'       => $uid,
            ]);
        }
    }
}
