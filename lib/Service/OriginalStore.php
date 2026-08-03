<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Keeps a copy of a file's pre-watermark content so an in-place watermark can be undone.
 *
 * `watermarkInPlace` burns the watermark into the stored bytes - there is no way to strip
 * it back out of a rendered PDF or image - so "remove watermark" can only mean restoring a
 * copy taken before the burn.
 *
 * Nextcloud's file versions were the obvious alternative and were rejected: the versions
 * app can be disabled, and version expiry would silently delete the only route back to the
 * original. A backup this app owns is durable on its own terms.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE COPY LIVES, AND WHY IT MOVED.
 *
 * In the owner's own storage, at `{owner}/files/.files_watermark/originals/{fileId}`.
 * It used to live in the app's appdata, which is invisible, quota-free and not
 * shareable - a better home in every respect but one: **server-side encryption never
 * reaches it**. With SSE enabled the user's own file is written as ciphertext while the
 * app's copy of the *same bytes* sat beside it in the clear, which is the one property a
 * pre-watermark backup must not have.
 *
 * That is not a gap this app can close from appdata. The selected module decides what
 * gets encrypted, and the default module answers `shouldEncrypt()` with false for
 * anything outside `files`, `files_versions` and `files_trashbin`; its key storage
 * throws outright for a path whose first segment is not a real user. Driving the module
 * by hand over an app-owned blob fails too, and not for want of trying: `encrypt()`
 * signs each block with `version + 1` while `decrypt()` verifies with `version`, and
 * that version comes from the file cache entry the storage layer maintains - so every
 * read of a hand-encrypted blob fails with "Bad Signature". Measured against Nextcloud
 * 31.0.14 with the master key enabled, for both a real cached file and a virtual path.
 *
 * Writing through the Files API into the owner's home instead means the storage layer
 * encrypts the copy with whatever module the admin selected, with the server's own keys,
 * versions and signatures. Nothing here knows or cares which module that is.
 *
 * The costs are real and were accepted deliberately: the folder is visible over WebDAV
 * and to desktop sync clients (dot-prefixed, so the web UI hides it by default), it
 * counts against the owner's quota, and a user who deletes it gives up the ability to
 * undo their watermarks. {@see isBackup()} is what keeps the app's own triggers off it.
 *
 * Copies written before the move are still read from appdata, so upgrading does not
 * strand a single one - see {@see read()}. Nothing migrates them: re-encrypting on
 * upgrade would need every owner's storage mounted at once, and a copy that is never
 * restored is one that never needed moving. New copies are always written to the owner.
 * ---------------------------------------------------------------------------
 *
 * Every method degrades to a no-op / null on storage errors rather than throwing: a failure
 * here must never take down the watermark operation it accompanies. The cost of a lost
 * backup is an un-removable watermark, which {@see WatermarkService::removeWatermark}
 * reports honestly instead of failing loudly mid-apply.
 */
class OriginalStore {

	/** The appdata folder copies were written to before the move to owner storage. */
	private const LEGACY_FOLDER = 'originals';

	/**
	 * Dot-prefixed so the web UI hides it by default. It is still a real folder in the
	 * owner's home: sync clients see it, and it counts against quota.
	 */
	public const HOME_FOLDER = '.files_watermark';

	public const HOME_SUBFOLDER = 'originals';

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether $node is one of this app's preserved originals.
	 *
	 * The guard that stops the app watermarking its own backups. Without it, storing a
	 * copy fires `NodeWrittenEvent`, the on-upload trigger queues a job for *that* copy,
	 * watermarking it stores a copy of the copy, and so on - the copies are supported
	 * mime types, so nothing else would stop the recursion.
	 *
	 * Matched on the path rather than on a marker inside the file: the bytes are the
	 * user's own document and carry nothing this app puts there.
	 */
	public function isBackup(Node $node): bool {
		return str_contains($node->getPath(), '/files/' . self::HOME_FOLDER . '/' . self::HOME_SUBFOLDER . '/');
	}

	/**
	 * Whether a pre-watermark original is held for this file.
	 */
	public function has(File $file): bool {
		$folder = $this->homeFolder($file, false);
		if ($folder !== null && $folder->nodeExists($this->name($file))) {
			return true;
		}

		$legacy = $this->legacyFolder(false);
		return $legacy !== null && $legacy->fileExists($this->name($file));
	}

	/**
	 * Preserve $content as the pre-watermark original for $file.
	 *
	 * An existing backup is never overwritten - re-watermarking an already-watermarked file
	 * would otherwise replace the true original with the watermarked bytes, quietly making
	 * the file impossible to restore.
	 *
	 * @return bool true when a backup is in place (already existed or was just written)
	 */
	public function store(File $file, string $content): bool {
		if ($this->has($file)) {
			return true;
		}

		$folder = $this->homeFolder($file, true);
		if ($folder === null) {
			return false;
		}

		try {
			// Through the Files API, which is the whole point: the storage layer applies
			// the selected encryption module on the way down. A raw filesystem write here
			// would land in the clear next to ciphertext.
			$folder->newFile($this->name($file), $content);
			return true;
		} catch (\Throwable $e) {
			// Quota is the expected failure - the copy is the size of the file, in the
			// owner's own storage - and it is reported the same as any other: the
			// watermark still applies, and removeWatermark() says it cannot be undone.
			$this->logger->error('files_watermark: could not preserve original for file {fileId}', [
				'fileId' => $this->fileId($file),
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * The preserved original's content, or null when none is held.
	 *
	 * The owner's storage first, then appdata: a copy written before originals moved is
	 * still restorable, and a copy written since is found without touching appdata at all.
	 */
	public function read(File $file): ?string {
		$folder = $this->homeFolder($file, false);
		if ($folder !== null) {
			try {
				$node = $folder->get($this->name($file));
				if ($node instanceof File) {
					return $node->getContent();
				}
			} catch (NotFoundException) {
				// Fall through to the legacy location.
			} catch (\Throwable $e) {
				$this->logger->error('files_watermark: could not read preserved original for file {fileId}', [
					'fileId' => $this->fileId($file),
					'exception' => $e,
				]);
				return null;
			}
		}

		return $this->readLegacy($file);
	}

	/**
	 * Drop the preserved original once it has been restored (or is no longer wanted).
	 *
	 * Both locations, because a file watermarked before the move and re-watermarked after
	 * it can have a copy in each, and leaving the appdata one behind would keep the older
	 * bytes as the file's apparent original for ever.
	 */
	public function discard(File $file): void {
		$folder = $this->homeFolder($file, false);
		if ($folder !== null) {
			try {
				$folder->get($this->name($file))->delete();
			} catch (NotFoundException) {
				// Nothing here to discard.
			} catch (\Throwable $e) {
				$this->logger->warning('files_watermark: could not discard preserved original for file {fileId}', [
					'fileId' => $this->fileId($file),
					'exception' => $e,
				]);
			}
		}

		$this->discardLegacy($file);
	}

	private function readLegacy(File $file): ?string {
		$folder = $this->legacyFolder(false);
		if ($folder === null) {
			return null;
		}

		try {
			return $folder->getFile($this->name($file))->getContent();
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: could not read preserved original for file {fileId}', [
				'fileId' => $this->fileId($file),
				'exception' => $e,
			]);
			return null;
		}
	}

	private function discardLegacy(File $file): void {
		$folder = $this->legacyFolder(false);
		if ($folder === null) {
			return;
		}

		try {
			$folder->getFile($this->name($file))->delete();
		} catch (NotFoundException) {
			// Nothing to discard.
		} catch (\Throwable $e) {
			$this->logger->warning('files_watermark: could not discard preserved original for file {fileId}', [
				'fileId' => $this->fileId($file),
				'exception' => $e,
			]);
		}
	}

	/**
	 * The originals folder inside the *owner's* home, not the acting user's.
	 *
	 * A shared file's backup belongs to whoever owns the bytes: the recipient may lose
	 * access tomorrow, and their quota is not where the owner's document should sit.
	 *
	 * @param bool $create create the folder when it does not exist yet
	 */
	private function homeFolder(File $file, bool $create): ?Folder {
		$uid = $file->getOwner()?->getUID();
		if ($uid === null || $uid === '') {
			// No owner to attribute the copy to - an external or system-mounted node.
			// Legacy appdata is still consulted by the caller.
			return null;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$path = self::HOME_FOLDER . '/' . self::HOME_SUBFOLDER;

			try {
				$folder = $userFolder->get($path);
				return $folder instanceof Folder ? $folder : null;
			} catch (NotFoundException) {
				if (!$create) {
					return null;
				}
			}

			return $userFolder->newFolder($path);
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: owner storage unavailable for original backups', [
				'uid' => $uid,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * @param bool $create create the backing folder when it does not exist yet
	 */
	private function legacyFolder(bool $create): ?ISimpleFolder {
		try {
			$appData = $this->appDataFactory->get('files_watermark');
			try {
				return $appData->getFolder(self::LEGACY_FOLDER);
			} catch (NotFoundException) {
				return $create ? $appData->newFolder(self::LEGACY_FOLDER) : null;
			}
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: appdata unavailable for original backups', [
				'exception' => $e,
			]);
			return null;
		}
	}

	private function name(File $file): string {
		return (string)$this->fileId($file);
	}

	private function fileId(File $file): int {
		return $file->getId();
	}
}
