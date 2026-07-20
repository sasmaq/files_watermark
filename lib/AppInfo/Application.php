<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\AppInfo;

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

	public function __construct() {
		parent::__construct(self::APP_ID);

		// Register the app's Composer autoloader so the bundled third-party
		// libraries (setasign/fpdi, tecnickcom/tcpdf) are loadable at runtime.
		// Nextcloud autoloads OCA\FilesWatermark\ classes from lib/, but not the
		// vendor/ dependencies — without this, using the PDF watermarker throws
		// "Class TCPDF not found" (a fatal Error → HTTP 500).
		$autoload = __DIR__ . '/../../vendor/autoload.php';
		if (file_exists($autoload)) {
			require_once $autoload;
		}
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
