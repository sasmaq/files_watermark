<?php

declare(strict_types=1);

/**
 * `tc-lib-unicode` drops the first character of any string containing a lam-alef pair.
 *
 * Measured on **2.11.0**, the current release — there is no upstream version to upgrade to.
 * Applied by {@see apply.php}; see that file for the idempotency and fail-loudly rules.
 *
 * ## The defect
 *
 * When Arabic shaping fuses lam + alef into a single ligature glyph, the now-redundant lam
 * has to be marked for deletion. `Bidi\Shaping\Arabic::processAlChar()` finds it with:
 *
 *     $deleteIdx = $this->getNewCharIndexBySourceIndex($laaChar['i']);
 *
 * and `getNewCharIndexBySourceIndex()` matches on `$item['i']`. But **nothing ever writes a
 * real value into `'i'`**: `Bidi\StepX::pushChar()` initialises every character with
 * `'i' => -1` and no later step fills it in. The real source index is carried in `'pos'`.
 *
 * So the lookup is always `getNewCharIndexBySourceIndex(-1)`, which matches the *first*
 * element of the array — index 0, the first character of the whole string. The result is
 * that any string containing a lam-alef pair loses its **first character**, while the lam
 * that should have been consumed survives as a stray glyph.
 *
 *     بلا  (beh, lam, alef)  → lam-medial + lam-alef      instead of beh-initial + lam-alef
 *     محمد الاختبار         → "حمد الاختبار" with an extra lam
 *     xلاy                   → the "x" disappears
 *     لا                     → correct, by accident: index 0 *is* the lam
 *
 * Both renderers are affected: {@see \OCA\FilesWatermark\Service\ShapedText::shape()} calls
 * `Bidi` directly for the image path, and `tc-lib-pdf`'s `Text.php` constructs its own
 * `Bidi` inside `getTextCell()` for the PDF path. One fix in the shared library covers both.
 *
 * ## The fix
 *
 * Match on `'pos'` — the field that actually holds the source index — instead of `'i'`.
 *
 * Guarded by `ShapedTextTest::testShapedSequenceIsExact()`, which pins the whole glyph
 * sequence: the counts and ranges the suite checked before this was found are all satisfied
 * by output that is missing a letter.
 */

return [
	'name' => 'tc-lib-unicode lam-alef',
	'file' => 'vendor/tecnickcom/tc-lib-unicode/src/Bidi/Shaping/Arabic.php',
	'replacements' => [
		[
			'from' => "if (\$item['i'] === \$sourceIndex) {",
			'to' => "if (\$item['pos'] === \$sourceIndex) {",
		],
		[
			'from' => "\$this->getNewCharIndexBySourceIndex(\$laaChar['i'])",
			'to' => "\$this->getNewCharIndexBySourceIndex(\$laaChar['pos'])",
		],
	],
];
