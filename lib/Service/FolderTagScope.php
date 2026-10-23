<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\Files\Cache\IFileAccess;
use OCP\Files\FileInfo;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;

/**
 * Whether a file lies inside a folder carrying a given system tag, at any depth.
 *
 * ---------------------------------------------------------------------------
 * ANSWERED BY WHERE THE FILE IS STORED, NOT BY WHERE A USER'S TREE SHOWS IT.
 *
 * The check used to test the file's parent as the caller reached it, which is two bugs: a
 * file one folder down from the tagged one was outside the policy, and a share recipient's
 * tree stops at the share's mount point - so a file in a share of a subfolder of the
 * owner's tagged folder could never see the tag, and the internal-share switch served the
 * recipient, of all people, a clean copy.
 *
 * So the ancestors are read from the file cache, in the storage the file actually lives in.
 * The answer is the same for the owner, for every recipient and under `occ`. What it does
 * not cross is a mount: a share or an external storage mounted *inside* a tagged folder is
 * stored somewhere else, and is in scope only if its own location is. A recipient cannot
 * pull the owner's files into the policy by tagging a folder of their own that the share
 * happens to sit in.
 * ---------------------------------------------------------------------------
 *
 * **A folder counts as inside itself.** A file carrying the tag does not: the setting is
 * "Limit to a tagged folder", and a tag on a file is some other use of the same tag.
 *
 * Cost: one lookup of the file's own row, one per ancestor not already looked up in this
 * request, and one indexed query for the tag. The ancestors are memoised by storage path,
 * which a move cannot make wrong: a moved folder is at a new path, which is looked up
 * afresh, and its old path is never asked about again. The file's own row and the tag are
 * read every time.
 */
class FolderTagScope {

	/** @var array<int, array<string, ?int>> storage id => path => folder id, or null for no row */
	private array $folderIds = [];

	public function __construct(
		private IFileAccess $fileAccess,
		private ISystemTagObjectMapper $tagObjectMapper,
	) {
	}

	/**
	 * @throws TagNotFoundException|\InvalidArgumentException $tagId is not a usable tag
	 */
	public function covers(int $fileId, string $tagId): bool {
		$entry = $this->fileAccess->getByFileId($fileId);
		if ($entry === null) {
			return false;
		}

		$candidates = $entry->getMimeType() === FileInfo::MIMETYPE_FOLDER ? [$fileId] : [];
		foreach ($this->ancestorPaths($entry->getPath()) as $path) {
			$id = $this->folderId($entry->getStorageId(), $path);
			if ($id !== null) {
				$candidates[] = $id;
			}
		}

		if ($candidates === []) {
			return false;
		}

		return $this->tagObjectMapper->haveTag(array_map('strval', $candidates), 'files', $tagId, false);
	}

	/**
	 * `files/a/b/report.pdf` → `files/a/b`, `files/a`, `files`, and the storage root `''`,
	 * which is what a tagged external storage or Team folder is.
	 *
	 * @return list<string>
	 */
	private function ancestorPaths(string $path): array {
		$paths = [];
		while ($path !== '') {
			$path = dirname($path);
			$path = $path === '.' ? '' : $path;
			$paths[] = $path;
		}

		return $paths;
	}

	private function folderId(int $storageId, string $path): ?int {
		if (!array_key_exists($path, $this->folderIds[$storageId] ?? [])) {
			$this->folderIds[$storageId][$path] = $this->fileAccess->getByPathInStorage($path, $storageId)?->getId();
		}

		return $this->folderIds[$storageId][$path];
	}
}
