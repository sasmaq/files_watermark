<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use Psr\Log\LoggerInterface;

/**
 * Recognises a node that lives in a Team folder, and finds that folder's root.
 *
 * Team folders (the `groupfolders` app, renamed from Group folders in Nextcloud 31) are
 * the one storage shape this app's two central assumptions do not fit:
 *
 *  - **They have no owner.** A Team folder is collective space. `getOwner()` has no
 *    honest answer for a file in one, which matters twice over: {@see OriginalStore}
 *    picks the backup location from the owner, and {@see WatermarkService::isShareAccess}
 *    decides `on_share` by asking whether the reader is someone other than the owner.
 *  - **They are not a share.** The mount is not an `ISharedStorage`, so the storage test
 *    that detects a received share reports "owner access" for every member of the team.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS DOES NOT DEPEND ON THE GROUPFOLDERS APP.
 *
 * `groupfolders` is optional and is not declared in `appinfo/info.xml`, so nothing here
 * may reference its classes, autoload them, or fail when it is absent. Detection goes
 * through `IMountPoint` alone - core API, present whether or not the app is installed:
 *
 *  - `getMountProvider()` returns the class name of the provider that created the mount,
 *    which for a Team folder is groupfolders' own `MountProvider`. Compared as a
 *    **string**, so the class never has to exist here.
 *  - `getMountType()` returns `group` for the same mounts. Core's own mount types are
 *    `shared` and `external`, so this does not collide with them.
 *
 * Either signal is enough. Both are read because `getMountProvider()` returns the empty
 * string for a mount whose provider did not set one, and a mount type is easier for a
 * future groupfolders release to keep stable than an internal class name.
 *
 * **This is written against the documented mount API, not measured against a running
 * Team folder** - `groupfolders` is not installed in this repo's Docker environment. The
 * unit tests below drive `IMountPoint` directly, so they pin the logic but not the
 * premise that a Team folder mount actually reports these two values. That is the one
 * thing an installed instance still has to confirm.
 * ---------------------------------------------------------------------------
 *
 * Every method answers "no / null" on any storage error rather than throwing. A node
 * this class cannot classify is treated as an ordinary one, which is the behaviour the
 * app had before Team folders were considered at all.
 */
class TeamFolder {

	/**
	 * Mount providers that create a Team folder mount, by class name.
	 *
	 * A string, deliberately: see the class docblock. The pre-31 name is listed too, since
	 * the app was renamed but its namespace was not.
	 */
	private const MOUNT_PROVIDERS = [
		'OCA\\GroupFolders\\Mount\\MountProvider',
	];

	/** What `IMountPoint::getMountType()` reports for those mounts. */
	private const MOUNT_TYPE = 'group';

	public function __construct(
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether $node lives inside a Team folder.
	 */
	public function contains(FileInfo $node): bool {
		return $this->mountPath($node) !== null;
	}

	/**
	 * The Team folder's own root as a writable folder, or null when $node is not in one.
	 *
	 * The root, not the file's parent: preserved originals go in one place per Team folder
	 * rather than scattered beside the files they belong to, the same way they sit at the
	 * top of a user's home rather than next to each watermarked document.
	 */
	public function rootOf(FileInfo $node): ?Folder {
		$path = $this->mountPath($node);
		if ($path === null) {
			return null;
		}

		try {
			$root = $this->rootFolder->get($path);
			return $root instanceof Folder ? $root : null;
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: team folder root unavailable at {path}', [
				'path' => $path,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * The mount point path of the Team folder containing $node, or null.
	 *
	 * Returned with no trailing slash, as `IRootFolder::get()` wants it - `getMountPoint()`
	 * reports `/alice/files/Team A/` and asking the root folder for that path with the
	 * slash still on it resolves a different string for the same node.
	 */
	private function mountPath(FileInfo $node): ?string {
		try {
			$mount = $node->getMountPoint();
		} catch (\Throwable) {
			// A node whose mount cannot be resolved is not one this app should reclassify.
			return null;
		}

		if (!$mount instanceof IMountPoint || !$this->isTeamMount($mount)) {
			return null;
		}

		$path = rtrim($mount->getMountPoint(), '/');
		return $path === '' ? null : $path;
	}

	private function isTeamMount(IMountPoint $mount): bool {
		try {
			if (in_array($mount->getMountProvider(), self::MOUNT_PROVIDERS, true)) {
				return true;
			}
		} catch (\Throwable) {
			// Fall through to the mount type - an implementation that cannot name its
			// provider can still name its type.
		}

		try {
			return $mount->getMountType() === self::MOUNT_TYPE;
		} catch (\Throwable) {
			return false;
		}
	}
}
