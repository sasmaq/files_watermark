<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Psr\Log\LoggerInterface;

/**
 * Grows a rendered file to an exact length, without changing what it displays.
 *
 * ---------------------------------------------------------------------------
 * WHY A FILE WOULD EVER BE PADDED.
 *
 * PROPFIND has to advertise the length a download will have, and a watermarked download is
 * rendered fresh each time - so its length is not known when the listing is answered. The
 * app measures one render, reserves that length plus slack
 * ({@see \OCA\FilesWatermark\Db\WatermarkRendition}), and then makes every later render
 * *fit* that reservation by padding it here. The reservation is the promise; this is what
 * keeps it.
 *
 * The slack exists because the watermark carries a live timestamp. Only its digits move
 * between renders, which shifts the compressed output by a few bytes, and the padding
 * absorbs the difference - which is the whole reason the timestamp can stay live at all.
 * ---------------------------------------------------------------------------
 *
 * **Every format is padded through a hole the format itself defines, never by appending
 * bytes wherever it is convenient.** A PDF gets a comment; a PNG gets a private ancillary
 * chunk; JPEG and WebP get trailing bytes past the end marker their own header already
 * declares. Nothing here rewrites image data, so what a reader draws is byte-identical to
 * what the renderer produced.
 *
 * Padding can fail - a render can overshoot its reservation, or land so close under it that
 * the gap is smaller than the format's smallest legal filler. Failure is reported rather
 * than approximated, and the caller re-measures instead of serving a file whose length does
 * not match what was promised.
 */
class DeliveryPadder {

	/**
	 * The smallest gap each format can fill, in bytes.
	 *
	 * A PDF comment is `%` plus its terminator; a PNG chunk is a 4-byte length, a 4-byte
	 * type and a 4-byte CRC before any payload at all; appended filler has no floor beyond
	 * the one byte being written.
	 */
	private const MINIMUM_PAD = [
		'application/pdf' => 2,
		'image/png' => 12,
		'image/jpeg' => 1,
		'image/webp' => 1,
	];

	/** Innocuous in a PDF comment, in a PNG chunk payload, and past an end marker alike. */
	private const FILLER_BYTE = ' ';

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether $mime is one this can pad at all.
	 *
	 * Asked before a reservation is measured, so a format with no safe hole never gets a
	 * promise made on its behalf.
	 */
	public function canPad(string $mime): bool {
		return isset(self::MINIMUM_PAD[$mime]);
	}

	/**
	 * The smallest reservation that can be promised for a render of $renderedSize.
	 *
	 * Slack rather than an exact fit, because the next render of the same file will not be
	 * the same length - see the class docblock. A flat floor covers the timestamp's few
	 * bytes many times over; the proportional term is for large documents, where a text
	 * change ripples through more compressed streams.
	 */
	public function reservationFor(int $renderedSize): int {
		return $renderedSize + max(4096, (int)ceil($renderedSize * 0.02));
	}

	/**
	 * Pad the file at $path up to exactly $target bytes.
	 *
	 * @return bool true when the file is now exactly $target bytes long; false when it could
	 *              not be, in which case the file is left as the renderer wrote it and the
	 *              caller must re-measure rather than serve it
	 */
	public function padTo(string $path, int $target, string $mime): bool {
		$minimum = self::MINIMUM_PAD[$mime] ?? null;
		if ($minimum === null) {
			return false;
		}

		$current = @filesize($path);
		if ($current === false) {
			return false;
		}

		if ($current === $target) {
			// The render landed exactly on its reservation. Nothing to do, and no format
			// needs a zero-length filler written into it to say so.
			return true;
		}

		if ($current > $target) {
			// The render outgrew what was promised for it. Nothing here can shrink a file
			// without changing what it shows, so this is the caller's problem to solve by
			// reserving more.
			$this->logger->info('files_watermark: render of {size} exceeds its reserved {target}, re-measuring', [
				'size' => $current,
				'target' => $target,
				'mime' => $mime,
			]);
			return false;
		}

		$gap = $target - $current;
		if ($gap < $minimum) {
			// Under the reservation, but by less than the format's smallest legal filler -
			// so the promise cannot be met exactly, and approximately is not a thing a
			// Content-Length can be.
			$this->logger->info('files_watermark: gap of {gap} is below the {mime} minimum of {min}, re-measuring', [
				'gap' => $gap,
				'mime' => $mime,
				'min' => $minimum,
			]);
			return false;
		}

		$padded = match ($mime) {
			'application/pdf' => $this->padPdf($path, $gap),
			'image/png' => $this->padPng($path, $gap),
			default => $this->append($path, $gap),
		};

		if (!$padded) {
			return false;
		}

		// The arithmetic above is not the promise; the file on disk is. A format helper that
		// wrote the wrong number of bytes must not reach a Content-Length header.
		clearstatcache(true, $path);
		return @filesize($path) === $target;
	}

	/**
	 * A PDF grows by a comment tucked in front of its final `%%EOF`.
	 *
	 * **Not by appending.** Readers look for `%%EOF` within the last 1024 bytes of the file,
	 * and many refuse a file where it sits further back than that - so filler written past it
	 * would break exactly the documents this is trying to deliver. Put in front of it, the
	 * marker stays where it was: last.
	 *
	 * The comment goes after `startxref` and its offset, which is whitespace as far as the
	 * grammar is concerned, and after every byte position the cross-reference table points
	 * at - so no offset recorded anywhere in the document moves.
	 */
	private function padPdf(string $path, int $gap): bool {
		$size = @filesize($path);
		if ($size === false) {
			return false;
		}

		// `%%EOF` lives at the tail by construction; reading a window rather than the file
		// keeps a large document out of memory for the sake of five bytes.
		$window = min($size, 2048);
		$handle = @fopen($path, 'r+b');
		if ($handle === false) {
			return false;
		}

		try {
			if (fseek($handle, -$window, SEEK_END) !== 0) {
				return false;
			}
			$tail = fread($handle, $window);
			if ($tail === false) {
				return false;
			}

			$marker = strrpos($tail, '%%EOF');
			if ($marker === false) {
				// No end marker to hide behind. Appending would be the only option left and
				// is the one thing this must not do, so the caller is told it failed.
				$this->logger->warning('files_watermark: no %%EOF in the last {window} bytes; cannot pad', [
					'window' => $window,
					'path' => $path,
				]);
				return false;
			}

			// Everything from `%%EOF` to the end of the file, put back after the filler.
			$trailer = substr($tail, $marker);
			$insertAt = $size - $window + $marker;

			// `%` opens a comment and the newline closes it, so the filler between them is
			// the gap less those two bytes.
			$comment = '%' . str_repeat(self::FILLER_BYTE, $gap - 2) . "\n";

			if (fseek($handle, $insertAt, SEEK_SET) !== 0) {
				return false;
			}
			return fwrite($handle, $comment . $trailer) === strlen($comment . $trailer);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * A PNG grows by a private chunk slipped in front of `IEND`.
	 *
	 * PNG is a chunk stream, and a decoder is required to skip any chunk it does not know
	 * whose type marks it ancillary - so this is the format's own extension point rather than
	 * a tolerated abuse of one. `wmPd` is well-formed for the purpose: lower-case first byte
	 * for ancillary, lower-case second for private, upper-case third as the spec reserves,
	 * lower-case fourth for safe-to-copy.
	 *
	 * `IEND` is empty and therefore always exactly the last twelve bytes, which is what makes
	 * the insertion point findable without reading the image.
	 */
	private function padPng(string $path, int $gap): bool {
		$size = @filesize($path);
		if ($size === false || $size < 12) {
			return false;
		}

		$handle = @fopen($path, 'r+b');
		if ($handle === false) {
			return false;
		}

		try {
			if (fseek($handle, -12, SEEK_END) !== 0) {
				return false;
			}
			$iend = fread($handle, 12);
			if ($iend === false || substr($iend, 4, 4) !== 'IEND') {
				$this->logger->warning('files_watermark: no IEND where one must be; cannot pad', [
					'path' => $path,
				]);
				return false;
			}

			// 12 bytes of frame - length, type, CRC - around the payload that does the work.
			$type = 'wmPd';
			$payload = str_repeat(self::FILLER_BYTE, $gap - 12);
			$chunk = pack('N', strlen($payload))
				. $type
				. $payload
				// PNG's CRC-32 is the same polynomial PHP's crc32() computes, over the type
				// and the payload but not the length.
				. pack('N', crc32($type . $payload));

			if (fseek($handle, $size - 12, SEEK_SET) !== 0) {
				return false;
			}
			return fwrite($handle, $chunk . $iend) === strlen($chunk . $iend);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * JPEG and WebP grow past the end their own headers declare.
	 *
	 * Both formats state where their content stops - JPEG with the `FFD9` end-of-image
	 * marker, WebP with the byte count in its RIFF header - and a decoder stops reading
	 * there. Bytes after that point are outside the image by the format's own definition,
	 * which is why this needs no structure of its own and why the RIFF length must be left
	 * exactly as the renderer wrote it: the filler is deliberately *not* part of the file
	 * the header describes.
	 */
	private function append(string $path, int $gap): bool {
		$handle = @fopen($path, 'ab');
		if ($handle === false) {
			return false;
		}

		try {
			$filler = str_repeat(self::FILLER_BYTE, $gap);
			return fwrite($handle, $filler) === $gap;
		} finally {
			fclose($handle);
		}
	}
}
