<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\Files_Trashbin\Trashbin;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Carries a protected file's protection onto the copy a share recipient's trash gets of it.
 *
 * ---------------------------------------------------------------------------
 * A DELETE INSIDE A SHARE MAKES A COPY, AND NOTHING ANNOUNCES IT.
 *
 * When a recipient deletes a file inside a folder shared with them, `files_trashbin` moves the
 * original into the **owner's** trash - a move, so the file id and any mark go with it - and
 * then copies it into the recipient's own (`Trashbin::copyFilesToUser`). That copy is made
 * through a view rooted at `/`, outside every user's `files/`, so no copy event fires and
 * {@see NodeCopiedListener} never sees it. It has a new file id, which no mark names: delete a
 * marked document, then download it from your own trash - or restore it - and it comes back
 * clean. A file watermarked only because it was in a share escapes the same way, since the
 * copy is in the recipient's storage and the internal-share switch no longer claims it.
 * ---------------------------------------------------------------------------
 *
 * **Three hooks, because each has one piece of it.**
 *
 *  - `BeforeNodeDeletedEvent` - the last point at which the source is still reached through
 *    the share, which is what the internal-share switch reads, and can still be walked. What
 *    needs carrying is decided here, on the same terms as a move ({@see NodeMovedListener}).
 *  - `post_moveToTrash` - files_trashbin's own legacy hook, and the only thing that says which
 *    trash entry the delete became. It fires between the move and the copy, so the copy does
 *    not exist yet; only its name is taken.
 *  - `NodeDeletedEvent` - after the copy. The marks are placed here.
 *
 * **The legacy hook is connected from here, not from `boot()`**, and only once there is
 * something to carry. `remote.php` boots only the `filesystem` and `logging` apps, which this
 * app is not, so a hook connected at boot is never there for a delete over WebDAV - the one
 * route the Files app deletes by. Event listeners are registered without booting, so the
 * first event is always heard, and it connects this same instance to the second.
 *
 * A delete that never reaches the hook - trash disabled, or the file too big for the owner's
 * trash - was permanent and made no copy, so the decision is dropped. One that reaches it but
 * leaves no copy behind (core skips the copy when it would overrun the recipient's quota)
 * finds nothing to mark, and that is the right answer too.
 *
 * **Only a delete by somebody other than the owner is looked at**, because that is the only
 * delete core makes the copy for. An owner's delete of their own files costs one owner lookup.
 *
 * Registered as one shared instance, so that all three see the same pending decisions. Never
 * throws: the delete has happened (or is about to) whatever this decides.
 *
 * @template-implements IEventListener<BeforeNodeDeletedEvent|NodeDeletedEvent>
 */
class NodeDeletedListener implements IEventListener {

	/** The legacy signal files_trashbin emits once a delete has gone to the trash. */
	public const TRASH_SIGNAL_CLASS = '\OCA\Files_Trashbin\Trashbin';
	public const TRASH_SIGNAL_NAME = 'post_moveToTrash';

	/**
	 * Decisions taken before a delete, waiting for it to reach the trash: by the deleted node's
	 * path below its user's `files/`, which is how files_trashbin's hook names it, then by path
	 * relative to the deleted node.
	 *
	 * @var array<string, array{uid: string, carry: array<string, array{id: int, origin: ?string}>, trash?: string}>
	 */
	private array $pending = [];

	public function __construct(
		private IRootFolder $rootFolder,
		private WatermarkService $watermarkService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof BeforeNodeDeletedEvent) {
				$this->before($event->getNode());
			} elseif ($event instanceof NodeDeletedEvent) {
				$this->after($event->getNode());
			}
		} catch (\Throwable $e) {
			$this->logFailure($event instanceof BeforeNodeDeletedEvent || $event instanceof NodeDeletedEvent ? $this->pathOf($event->getNode()) : '?', $e);
		}
	}

	/**
	 * Note which trash entry a pending delete became. The slot `post_moveToTrash` is connected to.
	 *
	 * `filePath` is the path the deleter deleted, below their `files/`; `trashPath` is the
	 * owner's entry. The recipient's copy shares its timestamp and is named after the
	 * recipient's own path - exactly what `copyFilesToUser` computes.
	 *
	 * @param array<string, mixed> $params
	 */
	public function movedToTrash(array $params): void {
		$path = $params['filePath'] ?? null;
		$trashPath = $params['trashPath'] ?? null;

		try {
			if (!is_string($path) || !isset($this->pending[$path]) || !is_string($trashPath)
				|| preg_match('/\.d(\d+)$/', $trashPath, $matches) !== 1) {
				return;
			}

			$this->pending[$path]['trash'] = Trashbin::getTrashFilename(basename($path), (int)$matches[1]);
		} catch (\Throwable $e) {
			$this->logFailure(is_string($path) ? $path : '?', $e);
		}
	}

	private function before(Node $source): void {
		if (!($source instanceof File || $source instanceof Folder)) {
			return;
		}

		$location = $this->locate($source->getPath());
		if ($location === null) {
			return;
		}
		[$uid, $path] = $location;

		$owner = $this->ownerOf($source);
		if ($owner === null || $owner === $uid) {
			return;
		}

		$files = $source instanceof File ? ['' => $source] : $this->filesUnder($source);
		if ($files === []) {
			return;
		}

		$ids = [];
		foreach ($files as $file) {
			$id = $file->getId();
			if ($id !== null) {
				$ids[] = $id;
			}
		}
		$origins = $this->watermarkService->markOrigins($ids);

		$carry = [];
		foreach ($files as $relative => $file) {
			$id = $file->getId();
			if ($id === null) {
				continue;
			}

			if (array_key_exists($id, $origins)) {
				$carry[$relative] = ['id' => $id, 'origin' => $origins[$id] ?? $owner];
				continue;
			}

			// Asked of the source as the deleter reaches it - through the share's mount, which
			// is what the internal-share switch reads. Their trash copy is not in that mount.
			if ($this->watermarkService->isForcedByShare($file)) {
				$carry[$relative] = ['id' => $id, 'origin' => $this->ownerOf($file) ?? $owner];
			}
		}

		if ($carry === []) {
			return;
		}

		$this->pending[$path] = ['uid' => $uid, 'carry' => $carry];
		// Core will not connect the same instance twice, so a second delete in the request
		// costs nothing here.
		Util::connectHook(self::TRASH_SIGNAL_CLASS, self::TRASH_SIGNAL_NAME, $this, 'movedToTrash');
	}

	private function after(Node $source): void {
		$location = $this->locate($source->getPath());
		if ($location === null) {
			return;
		}
		$path = $location[1];

		$entry = $this->pending[$path] ?? null;
		unset($this->pending[$path]);

		if ($entry === null || !isset($entry['trash'])) {
			return;
		}

		try {
			$copy = $this->rootFolder->get('/' . $entry['uid'] . '/files_trashbin/files/' . $entry['trash']);
		} catch (NotFoundException) {
			return;
		}

		foreach ($entry['carry'] as $relative => $carried) {
			$copied = $relative === '' ? $copy : ($copy instanceof Folder ? $this->child($copy, $relative) : null);
			if (!($copied instanceof File)) {
				continue;
			}

			$this->watermarkService->carryMark($copied, $carried['id'], $carried['origin']);
		}
	}

	/**
	 * The user a node's path belongs to, and the path below their `files/`.
	 *
	 * The user is read off the path rather than the session because it is the path that
	 * decides whose trash the copy goes to: files_trashbin uses the user whose filesystem the
	 * delete went through.
	 *
	 * @return array{string, string}|null
	 */
	private function locate(string $path): ?array {
		if (preg_match('#^/([^/]+)/files(/.+)$#', $path, $matches) !== 1) {
			return null;
		}

		return [$matches[1], $matches[2]];
	}

	/**
	 * Every file under $folder, keyed by its path relative to $folder.
	 *
	 * @return array<string, File>
	 */
	private function filesUnder(Folder $folder): array {
		$files = [];
		$queue = [[$folder, '']];

		while ($queue !== []) {
			[$dir, $prefix] = array_pop($queue);
			foreach ($dir->getDirectoryListing() as $child) {
				$relative = $prefix === '' ? $child->getName() : $prefix . '/' . $child->getName();
				if ($child instanceof Folder) {
					$queue[] = [$child, $relative];
				} elseif ($child instanceof File) {
					$files[$relative] = $child;
				}
			}
		}

		return $files;
	}

	private function child(Folder $folder, string $relative): ?Node {
		try {
			return $folder->get($relative);
		} catch (\Throwable) {
			return null;
		}
	}

	private function ownerOf(Node $node): ?string {
		try {
			return $node->getOwner()?->getUID();
		} catch (\Throwable) {
			return null;
		}
	}

	private function logFailure(string $path, \Throwable $e): void {
		$this->logger->warning('files_watermark: could not carry the protection onto the trash copy of {path}: {reason}', [
			'path' => $path,
			'reason' => $e->getMessage(),
			'exception' => $e,
		]);
	}

	private function pathOf(Node $node): string {
		try {
			return $node->getPath();
		} catch (\Throwable) {
			return '?';
		}
	}
}
