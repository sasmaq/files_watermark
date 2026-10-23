<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit;

use OCP\ITempManager;

/**
 * An {@see ITempManager} that makes real files under a directory the test chooses.
 *
 * Real rather than mocked because every caller hands the path on to something that reads or
 * writes it - GD, tc-lib-pdf, `pdftoppm` - and a mocked path is a string with nothing behind
 * it. It copies the two details of core's `OC\TempManager` a caller can trip over: names are
 * `oc_tmp_<random>` with the postfix after a dot, and a folder comes back **with a trailing
 * slash**.
 *
 * It does **not** sweep up after the test the way core's does after a request (`clean()` is a
 * shutdown function there). The app removes its own temp files on purpose - one request can
 * render a whole zip - and a fake that cleaned up behind it would hide a leak rather than fail
 * on one.
 */
class FakeTempManager implements ITempManager {

	public function __construct(
		private string $baseDir,
	) {
	}

	public function getTemporaryFile(string $postFix = ''): string|false {
		$path = $this->generatePath($postFix);
		return touch($path) && chmod($path, 0600) ? $path : false;
	}

	public function getTemporaryFolder(string $postFix = ''): string|false {
		$path = $this->generatePath($postFix);
		return mkdir($path, 0700) ? $path . '/' : false;
	}

	public function clean(): void {
	}

	public function cleanOld(): void {
	}

	public function getTempBaseDir(): string {
		return $this->baseDir;
	}

	private function generatePath(string $postFix): string {
		$path = $this->baseDir . '/oc_tmp_' . bin2hex(random_bytes(16));
		if ($postFix !== '') {
			$path .= '.' . str_replace(['\\', '/'], '', ltrim($postFix, '.'));
		}

		return $path;
	}
}
