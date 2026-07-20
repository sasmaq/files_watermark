<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Keeps a copy of a file's pre-watermark content so an in-place watermark can be undone.
 *
 * `watermarkInPlace` burns the watermark into the stored bytes — there is no way to strip
 * it back out of a rendered PDF or image — so "remove watermark" can only mean restoring a
 * copy taken before the burn. That copy lives in the app's own appdata (outside any user's
 * storage, so it is not itself browsable, shareable or watermarkable) keyed by file id.
 *
 * Nextcloud's file versions were the obvious alternative and were rejected: the versions
 * app can be disabled, and version expiry would silently delete the only route back to the
 * original. A backup this app owns is durable on its own terms.
 *
 * Every method degrades to a no-op / null on storage errors rather than throwing: a failure
 * here must never take down the watermark operation it accompanies. The cost of a lost
 * backup is an un-removable watermark, which {@see WatermarkService::removeWatermark}
 * reports honestly instead of failing loudly mid-apply.
 */
class OriginalStore {

	private const FOLDER = 'originals';

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether a pre-watermark original is held for this file id.
	 */
	public function has(int $fileId): bool {
		$folder = $this->folder(false);
		return $folder !== null && $folder->fileExists($this->name($fileId));
	}

	/**
	 * Preserve $content as the pre-watermark original for $fileId.
	 *
	 * An existing backup is never overwritten — re-watermarking an already-watermarked file
	 * would otherwise replace the true original with the watermarked bytes, quietly making
	 * the file impossible to restore.
	 *
	 * @return bool true when a backup is in place (already existed or was just written)
	 */
	public function store(int $fileId, string $content): bool {
		if ($this->has($fileId)) {
			return true;
		}

		$folder = $this->folder(true);
		if ($folder === null) {
			return false;
		}

		try {
			$folder->newFile($this->name($fileId), $content);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: could not preserve original for file {fileId}', [
				'fileId' => $fileId,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * The preserved original's content, or null when none is held.
	 */
	public function read(int $fileId): ?string {
		$folder = $this->folder(false);
		if ($folder === null) {
			return null;
		}

		try {
			return $folder->getFile($this->name($fileId))->getContent();
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: could not read preserved original for file {fileId}', [
				'fileId' => $fileId,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Drop the preserved original once it has been restored (or is no longer wanted).
	 */
	public function discard(int $fileId): void {
		$folder = $this->folder(false);
		if ($folder === null) {
			return;
		}

		try {
			$folder->getFile($this->name($fileId))->delete();
		} catch (NotFoundException) {
			// Nothing to discard.
		} catch (\Throwable $e) {
			$this->logger->warning('files_watermark: could not discard preserved original for file {fileId}', [
				'fileId' => $fileId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * @param bool $create create the backing folder when it does not exist yet
	 */
	private function folder(bool $create): ?ISimpleFolder {
		try {
			$appData = $this->appDataFactory->get('files_watermark');
			try {
				return $appData->getFolder(self::FOLDER);
			} catch (NotFoundException) {
				return $create ? $appData->newFolder(self::FOLDER) : null;
			}
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: appdata unavailable for original backups', [
				'exception' => $e,
			]);
			return null;
		}
	}

	private function name(int $fileId): string {
		return (string)$fileId;
	}
}
