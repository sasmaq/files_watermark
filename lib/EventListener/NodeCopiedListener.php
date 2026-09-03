<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Carries a file's protection onto copies of it.
 *
 * ---------------------------------------------------------------------------
 * THE HOLE THIS CLOSES.
 *
 * A mark is a row against a **file id**, and this app never writes to storage - so the bytes
 * behind a marked file are the clean original. Copying produces a new cache entry with a new
 * file id (`Cache::copyFromCache` → `put`), and nothing pointed at it. A share recipient
 * could therefore copy the document into their own folder and download it clean, in two
 * clicks, and the copy being theirs meant Remove was offered on it as well.
 *
 * **A move was never this, and needs no listener.** Core's `Updater::renameFromStorage` ends
 * in `Cache::move` / `moveFromCache`, both of which update the existing row in place, file id
 * included - even across storages, and even out of a share. The mark travels with the file
 * already. Only copying makes a new id, so only copying loses it.
 * ---------------------------------------------------------------------------
 *
 * Two reasons to mark the copy, matching the two reasons a fetch is watermarked at all
 * ({@see WatermarkService::isDeliveryCandidate}):
 *
 *  - **the source carries a mark** - the copy inherits it, whoever is copying and wherever
 *    it lands. A user copying their own marked file gets a marked copy, which is the same
 *    answer the app gives everywhere else: the mark is about the document, not the reader.
 *  - **the source has no mark, but this fetch of it would have been watermarked because it
 *    is leaving through a share** ({@see WatermarkService::isForcedByShare}). That switch is
 *    evaluated per fetch against no stored state, so it has nothing to say about a file that
 *    has left the share - and a copy into the recipient's own storage has left it. The mark
 *    placed here is what makes the per-fetch policy survive the boundary it was protecting.
 *    Checked only when the copy actually crosses from one owner to another, so an ordinary
 *    copy inside one user's own files costs nothing beyond the batched mark lookup.
 *
 * **Folders are walked.** `View::copy()` emits one `post_copy` for the top of the tree, not
 * one per file, so a marked file inside a copied folder would otherwise be the same escape
 * with one more click. The walk is over the file cache and is bounded by the size of the
 * copy that just happened - which wrote every one of those files to storage, so this is the
 * cheaper half of the operation by a wide margin.
 *
 * @template-implements IEventListener<NodeCopiedEvent>
 */
class NodeCopiedListener implements IEventListener {

	public function __construct(
		private WatermarkService $watermarkService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeCopiedEvent)) {
			return;
		}

		$source = $event->getSource();
		$target = $event->getTarget();

		try {
			if ($source instanceof File && $target instanceof File) {
				$this->inheritIfProtected($source, $target);
				return;
			}

			if ($source instanceof Folder && $target instanceof Folder) {
				$this->walk($source, $target);
			}
		} catch (\Throwable $e) {
			// **The copy still stands.** It has already happened by the time this event fires -
			// there is nothing here to roll back, and throwing would only turn a completed copy
			// into an error the user cannot act on. Logged at warning because an unmarked copy
			// of a protected file is exactly what this listener exists to prevent, so the line
			// is the whole record that one got away.
			$this->logger->warning('files_watermark: could not carry the mark onto the copy of {path}: {reason}', [
				'path' => $this->pathOf($source),
				'reason' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Mark $target when $source was protected, by either of the two routes.
	 *
	 * `$crossesOwner` is passed in rather than recomputed per file: it is a property of the
	 * copy, and for a folder walk it would otherwise be resolved once per child.
	 */
	private function inheritIfProtected(File $source, File $target, ?bool $crossesOwner = null): void {
		$sourceId = $source->getId();
		if ($sourceId === null) {
			return;
		}

		$protected = $this->watermarkService->isMarked($sourceId)
			|| (($crossesOwner ?? $this->crossesOwner($source, $target))
				&& $this->watermarkService->isForcedByShare($source));

		if (!$protected) {
			return;
		}

		$this->watermarkService->inheritMark($target, $source);
	}

	/**
	 * Pair every file under $source with its copy under $target and mark what needs marking.
	 *
	 * The source side is what is walked, and the target node is resolved from the relative
	 * path: a copy reproduces the tree exactly, so the two are the same shape, and walking the
	 * source is what lets the marked ids be asked for in **one** query per directory rather
	 * than one per file. Only the files that come back marked - usually none - are then
	 * resolved on the target side.
	 */
	private function walk(Folder $source, Folder $target): void {
		$crossesOwner = $this->crossesOwner($source, $target);

		/** @var list<array{Folder, Folder}> $queue */
		$queue = [[$source, $target]];

		while ($queue !== []) {
			[$sourceDir, $targetDir] = array_pop($queue);

			$files = [];
			foreach ($sourceDir->getDirectoryListing() as $child) {
				if ($child instanceof Folder) {
					$targetChild = $this->childFolder($targetDir, $child->getName());
					if ($targetChild !== null) {
						$queue[] = [$child, $targetChild];
					}
					continue;
				}

				if ($child instanceof File && $child->getId() !== null) {
					$files[$child->getId()] = $child;
				}
			}

			if ($files === []) {
				continue;
			}

			$marked = $this->watermarkService->markedFileIds(array_keys($files));

			foreach ($files as $id => $file) {
				// The share-forced branch has to look at every file, because none of them is
				// marked; the marked branch has already been answered by the batch above.
				if (!in_array($id, $marked, true)
					&& !($crossesOwner && $this->watermarkService->isForcedByShare($file))) {
					continue;
				}

				$targetFile = $this->childFile($targetDir, $file->getName());
				if ($targetFile === null) {
					// The copy of this one is not where the tree says it should be. Nothing
					// useful to mark, and the walk carries on: one unresolvable child must not
					// cost the rest of the folder its marks.
					continue;
				}

				$this->watermarkService->inheritMark($targetFile, $file);
			}
		}
	}

	/**
	 * Whether the copy lands under a different owner than it came from.
	 *
	 * The question the share-forced branch actually needs - "has this file left the reach of
	 * the share that was protecting it?" - and answerable without touching storage. A null
	 * owner on either side is treated as "crosses": an owner that will not resolve has not
	 * established that the file stayed put.
	 */
	private function crossesOwner(Node $source, Node $target): bool {
		try {
			$sourceOwner = $source->getOwner()?->getUID();
			$targetOwner = $target->getOwner()?->getUID();
		} catch (\Throwable) {
			return true;
		}

		return $sourceOwner === null || $targetOwner === null || $sourceOwner !== $targetOwner;
	}

	private function childFolder(Folder $parent, string $name): ?Folder {
		$child = $this->child($parent, $name);

		return $child instanceof Folder ? $child : null;
	}

	private function childFile(Folder $parent, string $name): ?File {
		$child = $this->child($parent, $name);

		return $child instanceof File ? $child : null;
	}

	private function child(Folder $parent, string $name): ?Node {
		try {
			return $parent->get($name);
		} catch (\Throwable) {
			return null;
		}
	}

	private function pathOf(Node $node): string {
		try {
			return $node->getPath();
		} catch (\Throwable) {
			return '?';
		}
	}
}
