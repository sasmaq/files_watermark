<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Carries protection onto the attachments Text copies along with a Markdown document.
 *
 * ---------------------------------------------------------------------------
 * TEXT COPIES A NOTE'S ATTACHMENTS ITSELF, AND NOT AS A COPY.
 *
 * Text keeps a document's attachments in `.attachments.{documentId}` beside it. When a
 * Markdown file is copied, Text's own `NodeCopiedListener` reads that folder - through the
 * **owner's** folder, whatever the copier can see - and writes each attachment beside the
 * copy with `newFile($name, getContent())`. That is a write: no copy event fires for the
 * attachments, they get new ids, and no mark names them. Copying a note was therefore a way
 * to obtain its marked images clean, even through a single-file share of the note, where the
 * recipient could never list the folder they came from.
 * ---------------------------------------------------------------------------
 *
 * **Registered to run after Text's listener**, at a lower priority, because the copies it
 * pairs do not exist until Text has made them. Each attachment is paired with its copy by
 * name - Text keeps the names - and protected on the same two rules as any copy
 * ({@see NodeCopiedListener}): the copy of a marked attachment inherits the mark, and when
 * the copy crosses from one owner to another, so does the copy of an attachment the share
 * switches would have watermarked for the copier. That is asked of the attachment as the
 * copier reaches it, which is how {@see \OCA\FilesWatermark\Middleware\TextAttachmentMiddleware}
 * serves it to them: through their own folder when a share mounts it there, otherwise as a
 * share the storage cannot see.
 *
 * **A folder copy is not this listener's.** Core copies the whole tree, attachment folders
 * included, so {@see NodeCopiedListener}'s walk pairs them by path. That is why that one runs
 * *before* Text, which afterwards renames each copied `.attachments.{id}` after its new
 * document - a rename keeps the ids, and the marks with them.
 *
 * Never throws: the copy has happened whatever this decides.
 *
 * @template-implements IEventListener<NodeCopiedEvent>
 */
class TextAttachmentCopyListener implements IEventListener {

	/** The only type Text copies attachments for, on both sides of the copy. */
	private const MARKDOWN = 'text/markdown';

	public function __construct(
		private IRootFolder $rootFolder,
		private WatermarkService $watermarkService,
		private ShareAccess $shareAccess,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeCopiedEvent)) {
			return;
		}

		$source = $event->getSource();
		$target = $event->getTarget();

		if (!($source instanceof File) || !($target instanceof File)
			|| $source->getMimeType() !== self::MARKDOWN || $target->getMimeType() !== self::MARKDOWN) {
			return;
		}

		try {
			$this->carry($source, $target);
		} catch (\Throwable $e) {
			// Logged at warning for the reason NodeCopiedListener gives: this line is the whole
			// record that a protected attachment now has an unmarked copy.
			$this->logger->warning('files_watermark: could not carry the marks onto the attachments copied with {path}: {reason}', [
				'path' => $this->pathOf($source),
				'reason' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	private function carry(File $source, File $target): void {
		// Most notes have no attachments, and for those this one lookup is the whole cost.
		$sourceDir = $this->attachmentFolder($source);
		if ($sourceDir === null) {
			return;
		}

		$attachments = [];
		foreach ($sourceDir->getDirectoryListing() as $child) {
			if ($child instanceof File && $child->getId() !== null) {
				$attachments[$child->getId()] = $child;
			}
		}
		if ($attachments === []) {
			return;
		}

		$targetDir = $this->attachmentFolder($target);
		if ($targetDir === null) {
			// Text made no copies - it was not there to, or it failed. Nothing to mark.
			return;
		}

		$marked = $this->watermarkService->markedFileIds(array_keys($attachments));
		$crossesOwner = $this->crossesOwner($source, $target);
		$copier = $this->userOf($source);

		foreach ($attachments as $id => $attachment) {
			if (!in_array($id, $marked, true)
				&& !($crossesOwner && $this->isForcedByShare($attachment, $id, $copier))) {
				continue;
			}

			$copy = $this->child($targetDir, $attachment->getName());
			if (!($copy instanceof File)) {
				// Not copied under its own name. One attachment that cannot be paired must not
				// cost the rest theirs.
				continue;
			}

			$this->watermarkService->inheritMark($copy, $attachment);
		}
	}

	/**
	 * `.attachments.{id}` beside $document in its owner's folder - where Text keeps a
	 * document's attachments and where it reads and writes them when copying
	 * (`AttachmentService::getAttachmentDirectoryForFile`).
	 */
	private function attachmentFolder(File $document): ?Folder {
		try {
			$owner = $document->getOwner()?->getUID();
			$id = $document->getId();
			if ($owner === null || $id === null) {
				return null;
			}

			$ownersDocument = $this->rootFolder->getUserFolder($owner)->getFirstNodeById($id);
			if ($ownersDocument === null) {
				return null;
			}

			$folder = $ownersDocument->getParent()->get('.attachments.' . $id);
		} catch (\Throwable) {
			return null;
		}

		return $folder instanceof Folder ? $folder : null;
	}

	/**
	 * Whether the share switches claim $attachment when $copier fetches it.
	 *
	 * The question {@see \OCA\FilesWatermark\Middleware\TextAttachmentMiddleware} answers
	 * when it serves one, asked the same way: through the copier's own folder when they can
	 * reach the attachment there, so a share mounted for them is what the switch sees, and
	 * otherwise - when it is somebody else's - as a share the storage cannot see. Unlike the
	 * middleware this serves nothing, so the indirect share is noted for this question only.
	 */
	private function isForcedByShare(File $attachment, int $id, ?string $copier): bool {
		if ($copier !== null) {
			try {
				$seen = $this->rootFolder->getUserFolder($copier)->getFirstNodeById($id);
			} catch (\Throwable) {
				$seen = null;
			}

			if ($seen instanceof File) {
				return $this->watermarkService->isForcedByShare($seen);
			}

			if ($this->ownerOf($attachment) === $copier) {
				return $this->watermarkService->isForcedByShare($attachment);
			}
		}

		return $this->shareAccess->asIndirectShareAccess(
			fn (): bool => $this->watermarkService->isForcedByShare($attachment),
		);
	}

	/**
	 * Whether the copy lands under a different owner than it came from - the same test, and
	 * the same reading of an owner that will not resolve, as {@see NodeCopiedListener}.
	 */
	private function crossesOwner(Node $source, Node $target): bool {
		$sourceOwner = $this->ownerOf($source);
		$targetOwner = $this->ownerOf($target);

		return $sourceOwner === null || $targetOwner === null || $sourceOwner !== $targetOwner;
	}

	/**
	 * The user whose files the copy was made from: the one whose path the source is under.
	 *
	 * Read off the path rather than the session, so it is the user the copy went through,
	 * which is the reader the share switches would have been asked about.
	 */
	private function userOf(Node $node): ?string {
		try {
			return preg_match('#^/([^/]+)/files/#', $node->getPath(), $matches) === 1 ? $matches[1] : null;
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
