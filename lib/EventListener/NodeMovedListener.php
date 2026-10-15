<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Keeps a protected file protected when it is moved into somebody else's storage.
 *
 * ---------------------------------------------------------------------------
 * A MOVE OUT OF A SHARE IS A COPY THE FILE ID SURVIVES - AND THAT WAS NOT ENOUGH.
 *
 * A recipient with delete permission on a shared folder can move a file out of it and into
 * their own home. Core moves the file-cache row, so the file id - and with it any mark -
 * comes along, which is why this app used to need nothing here. Two things go wrong anyway:
 *
 *  - **A file watermarked only because it was in a share** is now in the recipient's own
 *    storage, so the internal-share switch no longer claims it, and it downloads clean.
 *    A *copy* of such a file was already given a mark ({@see NodeCopiedListener}); a move
 *    was the same escape by a different verb.
 *  - **A move that does not keep the id** - core's fallback when the two caches cannot hand
 *    a row across - leaves the mark behind on an id that no longer exists.
 *
 * (The third thing that went wrong - the recipient, as the new owner, removing the mark -
 * is closed where the mark is placed: it records its owner. See
 * {@see WatermarkService::unmarkVerdict}.)
 * ---------------------------------------------------------------------------
 *
 * **Two events, because neither has both halves.** Before the move the source can still be
 * asked whether it is shared and marked; after it, the source is a path to nothing, and only
 * the target exists to be marked. So the decision is taken on `BeforeNodeRenamedEvent`, kept
 * here keyed by the source path, and carried out on `NodeRenamedEvent`. Registered as one
 * shared instance for exactly that reason.
 *
 * **Only a move between owners is looked at.** Within one storage neither the id nor the
 * owner changes, and a move by the owner within their own files changes nothing they could
 * not already undo by removing the mark. What is left - out of a share, into a share of
 * somebody else's - is rare enough that walking a moved folder is affordable.
 *
 * Never throws: the move has happened (or is about to) whatever this decides, and failing
 * it would only turn a completed move into an error. A failure is logged at warning, as for
 * a copy.
 *
 * @template-implements IEventListener<BeforeNodeRenamedEvent|NodeRenamedEvent>
 */
class NodeMovedListener implements IEventListener {

	/**
	 * Decisions taken before a move, waiting for it to complete: by source path, then by
	 * path relative to the moved node.
	 *
	 * @var array<string, array<string, array{id: int, origin: ?string, marked: bool}>>
	 */
	private array $pending = [];

	public function __construct(
		private WatermarkService $watermarkService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof BeforeNodeRenamedEvent) {
				$this->before($event->getSource(), $event->getTarget());
			} elseif ($event instanceof NodeRenamedEvent) {
				$this->after($event->getSource(), $event->getTarget());
			}
		} catch (\Throwable $e) {
			$this->logger->warning('files_watermark: could not carry the protection across the move of {path}: {reason}', [
				'path' => $this->pathOf($event instanceof BeforeNodeRenamedEvent || $event instanceof NodeRenamedEvent ? $event->getSource() : null),
				'reason' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	private function before(Node $source, Node $target): void {
		if (!($source instanceof File || $source instanceof Folder)) {
			return;
		}

		$sourceOwner = $this->ownerOf($source);
		$targetOwner = $this->ownerOf($target->getParent());
		if ($sourceOwner !== null && $sourceOwner === $targetOwner) {
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
				$carry[$relative] = ['id' => $id, 'origin' => $origins[$id] ?? $sourceOwner, 'marked' => true];
				continue;
			}

			// Asked of the source as the mover reaches it - through the share's mount, which
			// is what the internal-share switch reads.
			if ($this->watermarkService->isForcedByShare($file)) {
				$carry[$relative] = ['id' => $id, 'origin' => $this->ownerOf($file) ?? $sourceOwner, 'marked' => false];
			}
		}

		if ($carry !== []) {
			$this->pending[$source->getPath()] = $carry;
		}
	}

	private function after(Node $source, Node $target): void {
		$key = $source->getPath();
		$carry = $this->pending[$key] ?? null;
		unset($this->pending[$key]);

		if ($carry === null) {
			return;
		}

		foreach ($carry as $relative => $entry) {
			$moved = $relative === '' ? $target : ($target instanceof Folder ? $this->child($target, $relative) : null);
			if (!($moved instanceof File)) {
				continue;
			}

			// The ordinary case for a marked file: the id survived, and the mark with it.
			if ($entry['marked'] && $moved->getId() === $entry['id']) {
				continue;
			}

			$this->watermarkService->carryMark($moved, $entry['id'], $entry['origin']);
		}
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

	private function pathOf(?Node $node): string {
		try {
			return $node?->getPath() ?? '?';
		} catch (\Throwable) {
			return '?';
		}
	}
}
