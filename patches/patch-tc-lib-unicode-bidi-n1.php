<?php

declare(strict_types=1);

/**
 * `tc-lib-unicode` never runs UAX #9 rules N1 and N2, so Latin words reverse inside an
 * RTL line.
 *
 * Measured on **2.11.0**, the current release. Applied by {@see apply.php}; see that file
 * for the idempotency and fail-loudly rules.
 *
 * ## The defect
 *
 * `Bidi\StepN` gates all three of its neutral-resolution code paths on the character's type
 * being the literal string `'NI'`:
 *
 *     if ($this->getItem($idx)['type'] !== 'NI') {      // N1 entry
 *     while (… && $this->getItem($jdx)['type'] === 'NI') // N1 run scan
 *     if ($this->getItem($idx)['type'] === 'NI') {      // N2
 *
 * **No character is ever given that type.** `NI` is not a bidirectional character type at
 * all - UAX #9 defines it as the *class* of neutral and isolate formatting types, `B`, `S`,
 * `WS`, `ON`, `FSI`, `LRI`, `RLI` and `PDI`. `Bidi\StepX::pushChar()` stores the concrete
 * type (`'WS'` for a space, `'ON'` for punctuation) and uses `'NI'` only as a sentinel in
 * the *directional status stack*, never as a character type. Verified by instrumenting the
 * N1 entry check: it returns early on every character of every string, so **N1 and N2 are
 * dead code** and neutrals are never resolved to a direction.
 *
 * The visible consequence is in rule N1: a neutral between two same-direction characters
 * should take that direction, which is what makes the space inside `John Doe` an `L` and
 * the name a single left-to-right run. Unresolved, that space keeps the paragraph's RTL
 * level while the two words are bumped above it, so L2 reverses each word as its own run:
 *
 *     سري - John Doe   → "Doe John - سري"     the name is backwards
 *     محمد - John Q Public → "Public Q John - محمد"
 *
 * It bites exactly the default template with an Arabic prefix and a multi-word display
 * name. A watermark exists to identify someone, so naming them backwards is not cosmetic.
 *
 * As with the lam-alef defect, both renderers reach this code - `ShapedText::shape()`
 * directly, and `tc-lib-pdf`'s `getTextCell()` through its own `Bidi` instance.
 *
 * ## The fix
 *
 * Test membership of the NI class instead of equality to `'NI'`.
 *
 * N2 comes back to life with the same change. That is intended and is a no-op in effect -
 * it assigns the embedding direction, which leaves the level unchanged under I1/I2 - but it
 * is what makes the remaining neutrals strongly typed, as the rules require.
 *
 * Cross-checked against `python-bidi` on thirteen mixed Arabic/Latin strings: twelve agree
 * exactly, and the thirteenth (`سري - John Doe (Acme)`) differs only because that reference
 * implements no bracket pairs at all - tc-lib's own N0 is right and the reference is wrong.
 * Guarded by `ShapedTextTest::testLatinRunsAreNotReorderedInsideRtl()`.
 */

/** UAX #9 BD: "NI" is the class of neutral and isolate formatting characters. */
$ni = "['B', 'S', 'WS', 'ON', 'FSI', 'LRI', 'RLI', 'PDI']";

return [
	'name' => 'tc-lib-unicode bidi N1/N2',
	'file' => 'vendor/tecnickcom/tc-lib-unicode/src/Bidi/StepN.php',
	'replacements' => [
		// N1 - entry check.
		[
			'from' => "if (\$this->getItem(\$idx)['type'] !== 'NI') {",
			'to' => "if (!\\in_array(\$this->getItem(\$idx)['type'], $ni, true)) {",
		],
		// N1 - scanning to the end of a run of neutrals.
		[
			'from' => "while (\$jdx < \$this->seq['length'] && \$this->getItem(\$jdx)['type'] === 'NI') {",
			'to' => "while (\$jdx < \$this->seq['length'] && \\in_array(\$this->getItem(\$jdx)['type'], $ni, true)) {",
		],
		// N2 - any remaining neutrals take the embedding direction.
		[
			'from' => "if (\$this->getItem(\$idx)['type'] === 'NI') {",
			'to' => "if (\\in_array(\$this->getItem(\$idx)['type'], $ni, true)) {",
		],
	],
];
