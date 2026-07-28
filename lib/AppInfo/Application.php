<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\AppInfo;

use Composer\Autoload\ClassLoader;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesWatermark\EventListener\BeforePreviewFetchedListener;
use OCA\FilesWatermark\EventListener\LoadAdditionalScriptsListener;
use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\EventListener\SabrePluginAddListener;
use OCA\FilesWatermark\EventListener\SabrePublicPluginAddListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Preview\BeforePreviewFetchedEvent;

class Application extends App implements IBootstrap {

	public const APP_ID = 'files_watermark';

	/**
	 * The only vendor/ packages the app needs while running inside Nextcloud.
	 * Everything else there is dev-only (phpunit, php-cs-fixer, sabre/dav, ...)
	 * and must stay invisible to the runtime — see registerVendorAutoloader().
	 *
	 * The `tc-lib-*` block is the incoming PDF stack (see the migration plan in
	 * `doc/tasks.md`); FPDI and TCPDF stay until it has fully replaced them. Every
	 * transitive dependency has to be listed, not just the two packages named in
	 * `composer.json`, because this allowlist is what the runtime loader is built
	 * from — a missing entry is a "Class not found" fatal that only appears inside
	 * Nextcloud, never in the test suite, which uses Composer's own autoloader.
	 * `RuntimeVendorPackagesTest` guards exactly that drift.
	 */
	private const RUNTIME_VENDOR_PACKAGES = [
		'setasign/fpdi',
		'tecnickcom/tcpdf',
		'tecnickcom/tc-lib-barcode',
		'tecnickcom/tc-lib-color',
		'tecnickcom/tc-lib-file',
		'tecnickcom/tc-lib-pdf',
		'tecnickcom/tc-lib-pdf-encrypt',
		'tecnickcom/tc-lib-pdf-filter',
		'tecnickcom/tc-lib-pdf-font',
		'tecnickcom/tc-lib-pdf-graph',
		'tecnickcom/tc-lib-pdf-image',
		'tecnickcom/tc-lib-pdf-page',
		'tecnickcom/tc-lib-pdf-parser',
		'tecnickcom/tc-lib-pdf-sign',
		'tecnickcom/tc-lib-unicode',
		'tecnickcom/tc-lib-unicode-data',
	];

	private static bool $vendorAutoloaderRegistered = false;

	public function __construct() {
		parent::__construct(self::APP_ID);

		self::registerVendorAutoloader();
	}

	/**
	 * Make the bundled third-party libraries (setasign/fpdi, tecnickcom/tcpdf)
	 * loadable at runtime. Nextcloud autoloads OCA\FilesWatermark\ classes from
	 * lib/, but not the vendor/ dependencies — without this, using the PDF
	 * watermarker throws "Class TCPDF not found" (a fatal Error → HTTP 500).
	 *
	 * Deliberately *not* `require vendor/autoload.php`: Composer registers that
	 * loader with prepend = true and for *every* installed package, dev ones
	 * included. That put our vendor/sabre/dav ahead of the copy Nextcloud ships
	 * in 3rdparty/, so core resolved e.g. Sabre\DAV\ICopyTarget from our tree.
	 * The two versions' signatures differ (4.7.1 added `int $depth` to
	 * copyInto()), which made core's own OCA\DAV\Connector\Sabre\Directory
	 * violate the interface it implements and logged an error on every DAV
	 * request. Instead: build a loader from Composer's generated maps, keep only
	 * the runtime packages, and *append* it so core's autoloader always wins.
	 */
	private static function registerVendorAutoloader(): void {
		if (self::$vendorAutoloaderRegistered) {
			return;
		}

		$vendorDir = __DIR__ . '/../../vendor';
		$psr4File = $vendorDir . '/composer/autoload_psr4.php';
		$classMapFile = $vendorDir . '/composer/autoload_classmap.php';
		if (!file_exists($psr4File) || !file_exists($classMapFile)) {
			return;
		}

		// Nextcloud's own bootstrap has already declared ClassLoader from
		// 3rdparty/. require_once keys on the file path, not the class name, so
		// including our copy as well would fatal with "Cannot declare class
		// Composer\Autoload\ClassLoader, because the name is already in use".
		if (!class_exists(ClassLoader::class, false)) {
			$classLoaderFile = $vendorDir . '/composer/ClassLoader.php';
			if (!file_exists($classLoaderFile)) {
				return;
			}
			require_once $classLoaderFile;
		}
		self::$vendorAutoloaderRegistered = true;

		// Match on the package sub-path rather than an absolute prefix so this
		// holds however vendor/ is mounted or symlinked.
		$needles = array_map(static fn (string $package): string => '/' . $package . '/', self::RUNTIME_VENDOR_PACKAGES);
		$isRuntimePath = static function (string $path) use ($needles): bool {
			foreach ($needles as $needle) {
				if (str_contains($path, $needle)) {
					return true;
				}
			}
			return false;
		};

		$loader = new ClassLoader();

		// FPDI is PSR-4 (setasign\Fpdi\); TCPDF predates PSR-4 and is a classmap.
		foreach (require $psr4File as $prefix => $paths) {
			$paths = array_values(array_filter($paths, $isRuntimePath));
			if ($paths !== []) {
				$loader->setPsr4($prefix, $paths);
			}
		}
		$loader->addClassMap(array_filter(require $classMapFile, $isRuntimePath));

		// Composer's autoload_files.php is skipped on purpose: neither runtime
		// package has a `files` entry, while the dev ones do (phpunit's global
		// assertion functions, sabre's helpers) and those must not be pulled in.

		// register() appends. Never prepend — that is what caused the shadowing.
		$loader->register();
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScriptsListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, SabrePluginAddListener::class);
		// Public links are served by a *separate* Sabre server that never fires
		// SabrePluginAddEvent — it needs its own registration to be watermarked.
		$context->registerEventListener(BeforeSabrePubliclyLoadedEvent::class, SabrePublicPluginAddListener::class);
		$context->registerEventListener(BeforePreviewFetchedEvent::class, BeforePreviewFetchedListener::class);
	}

	public function boot(IBootContext $context): void {
		// nothing to do at boot time
	}
}
