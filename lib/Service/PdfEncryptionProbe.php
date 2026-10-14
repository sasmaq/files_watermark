<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use OCP\Files\File;

/**
 * Whether a stored PDF *might* be encrypted, from a few kilobytes of it.
 *
 * ---------------------------------------------------------------------------
 * WHY A HINT RATHER THAN THE ANSWER.
 *
 * Marking on upload has to skip a password-protected PDF (see
 * {@see PasswordProtectedPdfException}), and the only way to know for certain is to parse
 * the document and try the empty password - which reads the whole file. Doing that for
 * every PDF uploaded under `on_upload` would put a full read of each one into the request
 * that just wrote it, for the sake of a case almost none of them are.
 *
 * An encrypted PDF cannot hide the fact: its trailer names an `/Encrypt` dictionary, and the
 * trailer lives in one of three places this class looks at - the head (a linearised file's
 * first-page trailer), the tail (a classic `trailer << >>` sits just ahead of `startxref`),
 * and wherever `startxref` points (a cross-reference *stream*, whose dictionary is the
 * trailer). Not finding it in any of them is a firm "no"; finding it is only a "maybe",
 * which the full parse then settles.
 * ---------------------------------------------------------------------------
 *
 * **Every doubt answers "maybe".** A stream that cannot be opened or sought, or a tail with
 * no `startxref` in it, sends the caller to the full check rather than past it - the cost
 * of a wrong "no" is a locked document marked into a file nobody can download.
 */
final class PdfEncryptionProbe {

	private const HEAD_BYTES = 4096;
	private const TAIL_BYTES = 2048;
	private const XREF_BYTES = 8192;
	private const MARKER = '/Encrypt';

	public static function mayBeEncrypted(File $file): bool {
		try {
			$handle = $file->fopen('rb');
		} catch (\Throwable) {
			return true;
		}
		if (!is_resource($handle)) {
			return true;
		}

		try {
			$head = (string)fread($handle, self::HEAD_BYTES);
			if (str_contains($head, self::MARKER)) {
				return true;
			}

			$size = (int)$file->getSize();
			if ($size <= self::HEAD_BYTES) {
				// The head was the whole file.
				return false;
			}

			if (!(stream_get_meta_data($handle)['seekable'] ?? false)) {
				return true;
			}

			if (fseek($handle, max(0, $size - self::TAIL_BYTES)) !== 0) {
				return true;
			}
			$tail = (string)fread($handle, self::TAIL_BYTES);
			if (str_contains($tail, self::MARKER)) {
				return true;
			}

			if (preg_match_all('/startxref\s+(\d+)/', $tail, $matches) < 1) {
				return true;
			}
			// The last one: an incrementally updated file has several, and only the final
			// trailer describes the document as it stands.
			$offset = (int)end($matches[1]);
			if ($offset >= $size || fseek($handle, $offset) !== 0) {
				return true;
			}

			return str_contains((string)fread($handle, self::XREF_BYTES), self::MARKER);
		} finally {
			fclose($handle);
		}
	}
}
