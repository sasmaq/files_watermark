<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkRendition;
use OCA\FilesWatermark\Db\WatermarkRenditionMapper;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * What length a marked file's download is promised to have, for one reader.
 *
 * ---------------------------------------------------------------------------
 * THE ONE FACT A LISTING CANNOT KNOW BY ITSELF.
 *
 * PROPFIND has to answer with the length of a download that has not happened yet, and for a
 * marked file that download is rendered on the spot - longer than the stored bytes, by an
 * amount that depends on the watermark, the page count and the reader's own name. Nothing
 * short of rendering can predict it.
 *
 * So it is rendered once, its length is written down with slack on top, and that number is
 * what every listing reports from then on. Later downloads render live and are padded up to
 * it ({@see DeliveryPadder}), which is what lets the watermark keep a moving timestamp while
 * the promise stays fixed.
 * ---------------------------------------------------------------------------
 *
 * **Nothing here renders.** Measuring is the caller's job - it already has a rendered file in
 * hand on the one path where a measurement is free - and this only decides what the number
 * means, whether the stored one still applies, and what etag goes with it.
 */
class DeliveryReservation {

	public function __construct(
		private WatermarkRenditionMapper $mapper,
		private DeliveryPadder $padder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * $uid's standing reservation for $file, or null when there is none to trust.
	 *
	 * Null covers three cases the caller treats alike - never measured, measured for a file
	 * that has since changed, measured under a policy that has since changed - because the
	 * response to all three is the same: measure it again.
	 */
	public function current(File $file, string $uid, WatermarkConfig $config): ?WatermarkRendition {
		$fileId = $file->getId();
		if ($fileId === null || $uid === '') {
			return null;
		}

		$rendition = $this->mapper->find($fileId, $uid);
		if ($rendition === null) {
			return null;
		}

		return $rendition->matches($this->signature($file->getEtag(), $config)) ? $rendition : null;
	}

	/**
	 * The same question for a whole listing at once, keyed by file id.
	 *
	 * Stale rows are dropped here rather than returned, so a caller iterating a directory
	 * sees exactly the ids it may advertise a reserved length for.
	 *
	 * @param array<int, File> $filesById
	 * @return array<int, WatermarkRendition>
	 */
	public function currentForListing(array $filesById, string $uid, WatermarkConfig $config): array {
		if ($filesById === [] || $uid === '') {
			return [];
		}

		$found = $this->mapper->findByFileIds(array_keys($filesById), $uid);

		$valid = [];
		foreach ($found as $fileId => $rendition) {
			$file = $filesById[$fileId] ?? null;
			if ($file !== null && $rendition->matches($this->signature($file->getEtag(), $config))) {
				$valid[$fileId] = $rendition;
			}
		}

		return $valid;
	}

	/**
	 * Write down what a render of $renderedSize means for $uid's future downloads.
	 *
	 * Called with a render already in hand, which is why measuring costs nothing extra: the
	 * one path that needs a number is the one that just produced a file to count.
	 *
	 * @return ?int the reserved length, or null when this file cannot be promised one
	 */
	public function record(
		File $file,
		string $uid,
		WatermarkConfig $config,
		int $renderedSize,
		string $mime,
	): ?int {
		$fileId = $file->getId();
		if ($fileId === null || $uid === '' || !$this->padder->canPad($mime)) {
			// A format with no safe place to put filler can never be padded to a promise, so
			// no promise is made for it - the download keeps its old, honest, unpredictable
			// length.
			return null;
		}

		$reserved = $this->padder->reservationFor($renderedSize);

		// **Bump first, then record against what the bump produced.** A sync client only
		// re-reads a listing whose etag moved, so without this the reservation is published
		// to nobody - see {@see bumpEtag}. Recording against the *new* etag is what keeps
		// that from looping: the row this writes matches the file as it will be from here on,
		// so the next download finds a valid reservation and bumps nothing.
		$sourceEtag = $this->bumpEtag($file) ?? $file->getEtag();

		$stored = $this->mapper->reserve(
			$fileId,
			$uid,
			$this->signature($sourceEtag, $config),
			$reserved,
			$this->etagFor($sourceEtag, $reserved),
		);

		if (!$stored) {
			$this->logger->warning('files_watermark: could not record a delivery reservation', [
				'fileId' => $fileId,
				'uid' => $uid,
			]);
			return null;
		}

		return $reserved;
	}

	/**
	 * Forget every reader's reservation for a file.
	 *
	 * Housekeeping rather than correctness: a reservation whose file or policy has moved on
	 * already fails {@see WatermarkRendition::matches} and is re-measured without this. It
	 * exists so rows do not outlive the files they describe.
	 */
	public function forget(int $fileId): void {
		$this->mapper->deleteByFileId($fileId);
	}

	/**
	 * The etag a reserved download is advertised and served under.
	 *
	 * **Not the stored file's, and not a hash of the delivered bytes.** The stored one cannot
	 * be reused because the length the client must be told about changes without it changing;
	 * hashing the delivery is worse still, because a live timestamp makes every render differ
	 * and the client would read each one as a fresh server-side change and download forever.
	 *
	 * A digest of the stored etag and the reserved length moves exactly when one of those
	 * does - when the file is edited, or when the promise about it is revised - which is
	 * precisely when a client should come back and look again.
	 */
	public function etag(File $file, int $reservedSize): string {
		return $this->etagFor($file->getEtag(), $reservedSize);
	}

	/** {@see etag}, for a caller that already holds the source etag. */
	public function etagFor(string $sourceEtag, int $reservedSize): string {
		return md5($sourceEtag . ':' . $reservedSize);
	}

	/**
	 * Give the stored file a new etag, so clients come back and re-read its length.
	 *
	 * ---------------------------------------------------------------------------
	 * A RESERVATION NOBODY RE-READS IS A RESERVATION NOBODY HAS.
	 *
	 * Marking a file writes to this app's tables and nothing else. Nextcloud's file cache is
	 * untouched, so the file's etag does not move, so **its folder's etag does not move
	 * either** - and a sync client's discovery is driven entirely by folder etags. It sees an
	 * unchanged folder, declines to descend, and goes on using the child sizes it cached the
	 * first time it looked. Which are the *stored* sizes.
	 *
	 * The client then hydrates against a length the download will never have. On Windows that
	 * surfaces as `The cloud operation is invalid`: the placeholder was allocated at the
	 * stored size and the transfer does not fill it. It retries forever, because every retry
	 * re-reads the same cached metadata.
	 *
	 * This was diagnosed by changing one file's etag by hand and watching a live client
	 * re-list the folder, publish the reserved length, fetch once and stop.
	 * ---------------------------------------------------------------------------
	 *
	 * **The etag moves and the mtime deliberately does not.** Propagating with the file's
	 * existing modification time still gives every ancestor folder a fresh etag - which is
	 * all the client needs to look again - without dating the file forward. A watermark is
	 * not an edit, and it should not make the file claim it was modified.
	 *
	 * Best-effort: a file whose storage will not take the update is one whose reservation is
	 * simply not published yet, which is the situation that already obtains.
	 *
	 * @return ?string the new etag, or null when it could not be changed
	 */
	private function bumpEtag(File $file): ?string {
		try {
			$storage = $file->getStorage();
			$internalPath = $file->getInternalPath();
			$newEtag = uniqid('', true);

			$storage->getCache()->update($file->getId(), ['etag' => $newEtag]);
			// What carries the change up to the folder the client is actually watching.
			$storage->getPropagator()->propagateChange($internalPath, $file->getMTime());

			return $newEtag;
		} catch (\Throwable $e) {
			$this->logger->warning('files_watermark: could not refresh the etag; clients may not re-read this file', [
				'exception' => $e,
				'fileId' => $file->getId(),
			]);

			return null;
		}
	}

	/**
	 * What makes a reservation stale.
	 *
	 * The stored file's etag, because editing the file changes what a render of it weighs;
	 * and the whole policy, because every field on it - the text, the font size, the
	 * rotation, whether PDFs are flattened - moves the output too. `jsonSerialize()` carries
	 * `updatedAt`, so a config edited back to its old values still counts as a change, which
	 * is the safe direction to be wrong in.
	 *
	 * An unserialisable config digests as the empty string rather than throwing. That makes
	 * every reservation under it stale on sight, which costs a re-measure per download and
	 * never promises a length nothing will honour - the safe direction again.
	 */
	private function signature(string $etag, WatermarkConfig $config): string {
		$policy = json_encode($config->jsonSerialize());

		return md5($etag . '|' . ($policy === false ? '' : $policy));
	}
}
