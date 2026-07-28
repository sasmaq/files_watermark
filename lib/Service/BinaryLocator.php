<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

/**
 * Finds an external helper binary on `PATH`.
 *
 * Searching `PATH` rather than trusting a distro layout is deliberate: production
 * is RHEL 9 while the dev containers are Debian, so the same binary arrives by a
 * different package manager and occasionally a different prefix. Shared by
 * {@see PdfFlattener} and {@see PdfNormalizer} so both probe identically.
 *
 * Stats only — this never executes anything, which is what makes it cheap enough
 * for the callers to run on an availability check.
 */
final class BinaryLocator {

	/** @return string|null absolute path, or null when this host has no such binary */
	public static function find(string $name): ?string {
		$path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

		foreach (explode(PATH_SEPARATOR, $path) as $dir) {
			if ($dir === '') {
				continue;
			}
			$candidate = rtrim($dir, '/') . '/' . $name;
			if (is_file($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}

		return null;
	}
}
