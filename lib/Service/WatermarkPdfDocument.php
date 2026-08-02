<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Com\Tecnick\Pdf\Tcpdf;

/**
 * The tc-lib-pdf document this app renders with, with one library defect corrected.
 *
 * **The defect deletes the user's content.** An imported page becomes a Form XObject
 * whose dictionary the library assembles with `sprintf`, and its resource cloner
 * returns an empty *string* — not `<< >>` — for a page that resolves to no resources
 * (`ResourceCloner::cloneResources()`, `Importer::importPage()`). The dictionary then
 * reads
 *
 *     /Resources /Group << /Type /Group /S /Transparency >> /Filter /FlateDecode /Length 96
 *
 * where `/Resources` has swallowed `/Group` as its value, the group dictionary is left
 * standing where a *key* belongs, and every entry after it pairs with the wrong name —
 * `/Filter` included. A reader that trusts the dictionary hands deflate bytes to the
 * content interpreter and draws nothing, so the page arrives **blank with only the
 * watermark on it**, every original byte still in the file and none of it visible.
 *
 * A page resolves to no resources when it declares none and inherits none. That is
 * legal — a page whose content names no font, image or graphics state needs none — and
 * it is also what happens when a `/Resources` object cannot be found.
 *
 * ---------------------------------------------------------------------------
 * Why the repair is here and not on the finished file.
 *
 * `getOutImportedObjects()` is the library's own hook for this block of the body, and
 * {@see \Com\Tecnick\Pdf\Output::getOutPDFString()} derives every cross-reference
 * offset by scanning the assembled body *afterwards*. Repairing here therefore costs
 * five bytes that the xref then accounts for by itself.
 *
 * Repairing the written file instead **corrupts it**: the insertion shifts every byte
 * after it, the xref still points at the old offsets, and the document stops parsing
 * altogether — traded one broken file for another. That was tried first and is what
 * `testAPageWithoutResourcesKeepsAUsableFormDictionary` re-reading the output caught.
 * ---------------------------------------------------------------------------
 *
 * Pinned by `PdfWatermarkerTest::testAPageWithoutResourcesKeepsAUsableFormDictionary`
 * against tc-lib-pdf 8.67.2. When the library fixes this, that test keeps passing and
 * the override becomes a no-op that can be deleted with the class.
 */
class WatermarkPdfDocument extends Tcpdf {

	/**
	 * The imported objects block, with any valueless `/Resources` given the empty
	 * dictionary the library omitted.
	 *
	 * `/Resources` may only hold a dictionary or an indirect reference, so `/Resources`
	 * followed by a *name* is unambiguously the defect and cannot match a well-formed
	 * document. The block is bytes this library serialized a moment ago, not user input.
	 */
	protected function getOutImportedObjects(): string {
		return (string)preg_replace(
			'~/Resources(\s+)(?=/)~',
			'/Resources << >>$1',
			parent::getOutImportedObjects(),
		);
	}
}
