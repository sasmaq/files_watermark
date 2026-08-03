<?php

declare(strict_types=1);

/**
 * Applies every `patches/patch-*.php` to the installed `vendor/` tree.
 *
 * Composer runs this from `post-install-cmd` and `post-update-cmd`, so the patches are
 * re-applied every time dependencies are (re)installed — in CI, in the E2E stage that
 * bind-mounts this workspace into a Nextcloud container, and at packaging time. That is the
 * whole reason this is a script rather than a committed vendor edit: `vendor/` is gitignored,
 * so a hand-edit would be erased by the next `composer install` and would never ship.
 *
 * ## What a patch file looks like
 *
 * Each `patch-*.php` returns a definition and documents the defect in its own docblock:
 *
 * ```php
 * return [
 *     'name' => 'short label for the console line',
 *     'file' => 'vendor/…/Thing.php',       // relative to the repository root
 *     'replacements' => [
 *         ['from' => 'exact source text', 'to' => 'replacement'],
 *     ],
 * ];
 * ```
 *
 * ## The two rules this runner enforces
 *
 * - **Idempotent.** A `from` that is already absent while its `to` is present is the normal
 *   steady state — composer re-runs the hook on every install, and a warm cache can hand us
 *   an already-patched tree. That is reported and skipped, not treated as failure.
 * - **Loud, never silent.** If neither form is present, or an anchor is no longer unique, or
 *   only some replacements in a file apply, this exits non-zero and composer fails with it.
 *   The reasoning is the one in `doc/patch.md`: a patch that applied to shifted context would
 *   be worse than one that refused, and a silent no-op would ship the bug it was written for.
 *
 * Every patch here also has a test that fails if it did not run, so a skipped patch cannot
 * reach a release through a green suite either.
 */

$root = dirname(__DIR__);

$fail = static function (string $patch, string $message): never {
	fwrite(STDERR, "patch [$patch]: $message\n");
	exit(1);
};

$definitions = glob($root . '/patches/patch-*.php');
if ($definitions === false || $definitions === []) {
	fwrite(STDERR, "patch: no patch-*.php files found in patches/.\n");
	exit(1);
}

sort($definitions);

foreach ($definitions as $definitionPath) {
	/** @var array{name: string, file: string, replacements: list<array{from: string, to: string}>} $patch */
	$patch = require $definitionPath;
	$name = $patch['name'];
	$target = $root . '/' . $patch['file'];

	if (!is_file($target)) {
		$fail($name, "{$patch['file']} does not exist. Run composer install first.");
	}

	$source = file_get_contents($target);
	if ($source === false) {
		$fail($name, "could not read {$patch['file']}.");
	}

	$patched = $source;
	$applied = 0;

	foreach ($patch['replacements'] as $replacement) {
		$found = substr_count($patched, $replacement['from']);

		if ($found === 0) {
			if (str_contains($patched, $replacement['to'])) {
				continue;
			}

			$fail(
				$name,
				"neither the original nor the patched form of \"{$replacement['from']}\" is present in "
				. "{$patch['file']}. The dependency has changed upstream — re-read the defect described in "
				. basename($definitionPath) . ' against the new source before trusting Arabic output.',
			);
		}

		if ($found > 1) {
			$fail($name, "\"{$replacement['from']}\" appears $found times in {$patch['file']}; the anchor is no longer unique.");
		}

		$patched = str_replace($replacement['from'], $replacement['to'], $patched);
		++$applied;
	}

	if ($applied === 0) {
		echo "patch [$name]: already applied.\n";
		continue;
	}

	if ($applied !== count($patch['replacements'])) {
		$fail($name, "applied $applied of " . count($patch['replacements']) . " replacements; {$patch['file']} is in a half-patched state.");
	}

	if (file_put_contents($target, $patched) === false) {
		$fail($name, "could not write {$patch['file']}.");
	}

	echo "patch [$name]: applied.\n";
}
