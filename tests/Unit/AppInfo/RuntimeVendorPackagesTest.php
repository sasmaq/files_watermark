<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\AppInfo;

use OCA\FilesWatermark\AppInfo\Application;
use PHPUnit\Framework\TestCase;

/**
 * Guards {@see Application::RUNTIME_VENDOR_PACKAGES} against drift.
 *
 * The app does not `require vendor/autoload.php` — it builds its own loader from
 * Composer's maps and keeps only the packages on that allowlist, so that dev-only
 * packages (notably `sabre/dav`) cannot shadow the copies Nextcloud ships. The cost
 * of that design is a failure mode no other test can see: a runtime dependency that
 * is installed and passes the whole suite, because PHPUnit uses Composer's real
 * autoloader, and then fatals with "Class not found" the first time the app runs
 * inside Nextcloud.
 *
 * Adding `tecnickcom/tc-lib-pdf` made that concrete — one package in `composer.json`
 * pulled in **thirteen** transitive ones, every single one of which has to be listed.
 */
class RuntimeVendorPackagesTest extends TestCase {

	/**
	 * Composer's lock file already draws the line this allowlist needs: `packages`
	 * is exactly the non-dev tree, `packages-dev` everything else. So the invariant
	 * is simply that the two agree — which makes adding a runtime dependency without
	 * registering it a test failure rather than a production 500.
	 */
	public function testEveryRuntimeDependencyIsRegisteredForTheRuntimeAutoloader(): void {
		$allowed = (new \ReflectionClass(Application::class))->getConstant('RUNTIME_VENDOR_PACKAGES');
		$this->assertIsArray($allowed);

		$missing = array_diff($this->lockedRuntimePackages(), $allowed);

		$this->assertSame([], array_values($missing), sprintf(
			'These runtime dependencies are installed but absent from '
			. 'Application::RUNTIME_VENDOR_PACKAGES, so their classes will not load inside '
			. 'Nextcloud even though the test suite passes: %s',
			implode(', ', $missing),
		));
	}

	/**
	 * The reverse direction. A stale entry is harmless at runtime — the loader just
	 * matches nothing — but it is a lie about what the app depends on, and this is
	 * how the FPDI and TCPDF entries are expected to disappear at the end of the
	 * migration rather than lingering as decoration.
	 */
	public function testTheAllowlistHasNoEntriesForUninstalledPackages(): void {
		$allowed = (new \ReflectionClass(Application::class))->getConstant('RUNTIME_VENDOR_PACKAGES');

		$stale = array_diff($allowed, $this->lockedRuntimePackages());

		$this->assertSame([], array_values($stale), sprintf(
			'Application::RUNTIME_VENDOR_PACKAGES names packages that are not runtime '
			. 'dependencies any more: %s',
			implode(', ', $stale),
		));
	}

	/** Every package installed for production, straight from the lock file. */
	private function lockedRuntimePackages(): array {
		$lockFile = __DIR__ . '/../../../composer.lock';
		$this->assertFileExists($lockFile);

		$lock = json_decode((string)file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($lock['packages'] ?? null, 'composer.lock has no packages array');

		return array_column($lock['packages'], 'name');
	}
}
