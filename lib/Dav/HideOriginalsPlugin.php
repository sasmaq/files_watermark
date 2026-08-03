<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\FilesWatermark\Service\OriginalStore;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;

/**
 * Takes the app's preserved originals off WebDAV entirely.
 *
 * {@see OriginalStore} keeps the pre-watermark copy of a file in the owner's own storage,
 * because that is the only place server-side encryption reaches. The price is that the
 * folder is an ordinary part of the user's tree: sync clients list it, the web UI lists
 * it, and anything that can address a path can fetch it. This plugin is what takes that
 * back.
 *
 * Two hooks, because hiding a path from listings does nothing about someone who already
 * knows it:
 *
 * - **`beforeMultiStatus`** hands over the response's property list *by reference*
 *   (`Sabre\DAV\Server::generateMultiStatus()`), so an entry dropped here never reaches
 *   the client. Every listing goes through it - `PROPFIND` at any depth and the Files
 *   app's `REPORT`s - for the whole `/remote.php/dav/` tree rather than the files
 *   endpoint alone, which is why the trashbin and the legacy `/remote.php/webdav/`
 *   endpoint are covered by the same registration.
 * - **`beforeMethod:*`** runs before any request is dispatched, so a `NotFound` thrown
 *   there answers 404 to *every* method rather than leaving the path merely unmentioned.
 *   `Server` uses `WildcardEmitterTrait`, so the `:*` subscription is supported.
 *
 * **This does not touch the app itself.** `OriginalStore` reads and writes through the
 * Files API, which never passes through Sabre - storing, restoring and discarding a
 * preserved original all keep working with the folder sealed.
 *
 * Two things it deliberately does not do. It is not an access-control boundary: the
 * copies hold the same bytes as the user's own file, which that user can read anyway, and
 * `occ` and server-side code still see the folder because the restore path needs to. And
 * it cannot help on the *public* endpoint, where a share is re-rooted so that its path no
 * longer names the folder - {@see \OCA\FilesWatermark\EventListener\ShareGuardListener}
 * is what closes that, by refusing the share in the first place.
 */
class HideOriginalsPlugin extends ServerPlugin {

	public function initialize(Server $server): void {
		// Priority 1: ahead of anything else that might act on the request.
		$server->on('beforeMethod:*', [$this, 'refuseRequest'], 1);
		$server->on('beforeMultiStatus', [$this, 'filterListing']);
	}

	/**
	 * @throws NotFound when the request addresses a preserved original
	 */
	public function refuseRequest(RequestInterface $request): void {
		if ($this->isOriginalsPath($request->getPath())) {
			throw new NotFound();
		}
	}

	/**
	 * Drop preserved originals from a multistatus response before it is written.
	 *
	 * @param array<int, array{href?: string}> $fileProperties by reference - see the class docblock
	 */
	public function filterListing(&$fileProperties): void {
		$filtered = [];
		foreach ($fileProperties as $entry) {
			if ($this->isOriginalsPath((string)($entry['href'] ?? ''))) {
				continue;
			}
			$filtered[] = $entry;
		}

		$fileProperties = $filtered;
	}

	/**
	 * Whether any segment of $path is the originals folder.
	 *
	 * **Segment-wise, not a substring test.** Matching `/.files_watermark/` with its
	 * trailing slash leaves the folder *itself* addressable - the request path for
	 * `DELETE …/.files_watermark/` normalises to `files/alice/.files_watermark`, which has
	 * no trailing slash to match, so the whole set of preserved originals could be deleted
	 * through a hole in the guard that was supposed to protect them.
	 */
	private function isOriginalsPath(string $path): bool {
		foreach (explode('/', trim($path, '/')) as $segment) {
			$segment = rawurldecode($segment);
			// The folder itself, anything below it, and the `.files_watermark.d1785710850`
			// the trashbin renames it to on the way in.
			if (
				$segment === OriginalStore::HOME_FOLDER
				|| str_starts_with($segment, OriginalStore::HOME_FOLDER . '.d')
			) {
				return true;
			}
		}

		return false;
	}
}
