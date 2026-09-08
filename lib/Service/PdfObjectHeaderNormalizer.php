<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Com\Tecnick\Pdf\Parser\Parser;

/**
 * Rewrites the **indirect object headers** of a PDF into the single-space form
 * `N G obj`, so the renderer's parser can find the objects its own cross-reference
 * table points at.
 *
 * The files this rescues are not damaged. PDF 32000-1 §7.2.2 lists LF, CR, tab, form
 * feed and NUL as whitespace, and an object header may use any of them between the
 * object number, the generation number and the `obj` keyword. Producers that write
 * `3\n0\nobj` are emitting a perfectly valid document - Google's exporters do it for
 * every object in the file, which is how this case was found.
 *
 * tc-lib-pdf-parser cannot read them. `Parser::getIndirectObject()` builds the header
 * it is looking for by string concatenation with literal spaces:
 *
 * ```php
 * $objref = $obj[0] . ' ' . $obj[1] . ' obj';
 * $objPos = \strpos($this->pdfdata, $objref, $offset);
 * ```
 *
 * and requires that literal to sit exactly at the cross-reference offset. A header
 * separated by anything but single spaces misses, and the parser takes the miss for
 * "an indirect reference to an undefined object", which §7.3.10 says is the null
 * object. The document then parses *successfully* into a set of nulls - including
 * `/Root`, at which point tc-lib-pdf gives up with a corrupted-source error that
 * blames encryption. Nothing is encrypted; the whitespace is simply not spaces.
 *
 * This is still true in the current releases (checked against tc-lib-pdf 8.73.6 and
 * tc-lib-pdf-parser 3.15.2), so the repair has to happen here.
 *
 * ## Why the byte count is preserved
 *
 * A classic cross-reference table stores a **byte offset** for every object. Shortening
 * `3\n0\nobj` to `3 0 obj` in place would be fine - LF and space are one byte each - but
 * `3\r\n0\r\nobj` would not: every offset after it would shift, and the table that made
 * the file readable would be the thing that broke it. Rewriting the table instead means
 * re-serialising the document, which is a great deal of machinery for a whitespace bug.
 *
 * So each header is rewritten to exactly its original width, padded on the left with
 * **leading zeros**: `3\r\n0\r\nobj` (9 bytes) becomes `003 0 obj` (9 bytes). That is not
 * a trick played on the parser but the same rule it already applies to the offsets it
 * reads - `getIndirectObject()` opens with `$offset += \strspn($this->pdfdata, '0', $offset)`,
 * skipping leading zeros before it looks for the header. Readers that tokenise properly
 * see an ordinary integer with insignificant leading zeros, which §7.3.3 permits.
 *
 * ## Why only the offsets in the table are touched
 *
 * The obvious implementation - a regular expression for `\d+\s+\d+\s+obj` over the whole
 * file - would also match inside stream payloads, where those bytes are image or font
 * data that means nothing of the sort. Rewriting one of those corrupts the stream while
 * leaving the file superficially intact, which is a worse failure than the one being
 * fixed. Instead the cross-reference table is parsed first and only the offsets it
 * declares are examined, each one confirmed to hold the header for the object the table
 * says lives there before a byte is changed.
 *
 * The table itself parses fine on these files: it is the object lookup that fails, not
 * the xref reader, so the offsets are trustworthy even though the objects come back null.
 */
final class PdfObjectHeaderNormalizer {

	/**
	 * Bytes examined at each cross-reference offset.
	 *
	 * Comfortably longer than any real header - a 10-digit object number, a 5-digit
	 * generation and `obj` come to 18 bytes with single-byte separators - while keeping
	 * the match bounded so a pathological file cannot turn this into a whole-file scan.
	 */
	private const WINDOW = 64;

	/** Whitespace bytes PDF 32000-1 §7.2.2 permits between the tokens of a header. */
	private const WHITESPACE = "\x00\t\n\x0c\r ";

	/**
	 * `$raw` with every object header normalised, or `null` when this is not the case -
	 * the file already uses single spaces, or its cross-reference table cannot be read
	 * at all, which is a different failure and not one this class can repair.
	 *
	 * The returned string is always exactly as long as `$raw`.
	 */
	public function normalize(string $raw): ?string {
		$offsets = $this->crossReferenceOffsets($raw);
		if ($offsets === []) {
			return null;
		}

		$rewritten = $raw;
		$repaired = 0;

		foreach ($offsets as $reference => $offset) {
			$header = $this->headerAt($rewritten, $reference, $offset);
			if ($header === null) {
				continue;
			}

			$rewritten = \substr_replace($rewritten, $header, $offset, \strlen($header));
			++$repaired;
		}

		// Nothing to say when the headers were already readable: the import failed for
		// some other reason, and claiming a repair would only replace the real error.
		return $repaired === 0 ? null : $rewritten;
	}

	/**
	 * Byte offsets of the uncompressed objects in `$raw`, keyed by `N_G` reference.
	 *
	 * Empty when the file cannot be parsed far enough to have a table, which is the
	 * signal to decline rather than an error worth reporting - the caller still holds
	 * the import's own exception, which says more than anything this could add.
	 *
	 * @return array<string, int>
	 */
	private function crossReferenceOffsets(string $raw): array {
		try {
			[$xref] = (new Parser([]))->parse($raw);
		} catch (\Throwable) {
			return [];
		}

		$offsets = [];
		foreach ($xref['xref'] ?? [] as $reference => $offset) {
			// Objects inside an object stream are listed too, with a position that is
			// not a file offset. They are filtered out here by shape and again by
			// headerAt(), which will not find a header where there is none.
			if (\is_string($reference) && \is_int($offset) && $offset > 0 && $offset < \strlen($raw)) {
				$offsets[$reference] = $offset;
			}
		}

		return $offsets;
	}

	/**
	 * The normalised header to write at `$offset`, or `null` to leave those bytes alone -
	 * because they already read as `N G obj`, because they are not a header for the
	 * object the table claims is there, or because the normalised form would not fit the
	 * width of the original.
	 */
	private function headerAt(string $raw, string $reference, int $offset): ?string {
		$expected = \explode('_', $reference);
		if (\count($expected) !== 2) {
			return null;
		}

		$window = \substr($raw, $offset, self::WINDOW);
		$pattern = '/^(0*)(\d{1,10})[' . self::WHITESPACE . ']{1,16}(\d{1,5})[' . self::WHITESPACE . ']{1,16}obj/';
		if (\preg_match($pattern, $window, $matches) !== 1) {
			return null;
		}

		// The table is the only reason these bytes are being touched, so they have to be
		// the object it points at. Anything else is a coincidence in a stream payload.
		if ($matches[2] !== $expected[0] || $matches[3] !== $expected[1]) {
			return null;
		}

		$canonical = $matches[2] . ' ' . $matches[3] . ' obj';
		$width = \strlen($matches[0]);

		// Cannot happen with a well-formed match - single spaces are the narrowest legal
		// separator, so the canonical form is never wider than what it replaces - but a
		// silent overrun here would corrupt the following object, so it is checked.
		if (\strlen($canonical) > $width) {
			return null;
		}

		$replacement = \str_repeat('0', $width - \strlen($canonical)) . $canonical;

		// Compared as bytes, not as widths: `3\n0\nobj` is exactly as long as `3 0 obj`,
		// so a length test reads the very headers this class exists to repair as though
		// they were already correct.
		return $replacement === $matches[0] ? null : $replacement;
	}
}
