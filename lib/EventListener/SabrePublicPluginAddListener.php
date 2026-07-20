<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Dav\DownloadInterceptorPlugin;
use OCA\FilesWatermark\Dav\ZipInterceptorPlugin;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\IDateTimeZone;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers {@see DownloadInterceptorPlugin} on the *public-link* WebDAV server, so a
 * public share is watermarked exactly like an internal one.
 *
 * Public links do not go through the authenticated Files DAV server that
 * {@see SabrePluginAddListener} hooks. `/s/{token}/download` redirects to
 * `/public.php/dav/files/{token}/`, a separate Sabre server built in
 * `apps/dav/appinfo/v2/publicremote.php` that announces itself with
 * BeforeSabrePubliclyLoadedEvent instead of SabrePluginAddEvent. Without this listener
 * the interceptor is simply absent there and every public-link download — including the
 * inline fetch the viewer makes on the share page — serves the clean original.
 *
 * The plugin is built by hand rather than pulled from the container because this
 * instance must carry $publicContext = true: the public endpoint mounts the file from
 * the owner's own storage, so share access is not detectable from the mount.
 *
 * PropFindPlugin is deliberately not registered here — the `is-watermarked` property
 * only feeds the logged-in Files list, which no public visitor sees.
 *
 * @template-implements IEventListener<BeforeSabrePubliclyLoadedEvent>
 */
class SabrePublicPluginAddListener implements IEventListener {

	public function __construct(
		private ContainerInterface $container,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeSabrePubliclyLoadedEvent)) {
			return;
		}

		// getServer() is nullable on the base SabrePluginEvent.
		$server = $event->getServer();
		if ($server === null) {
			return;
		}

		$server->addPlugin(new DownloadInterceptorPlugin(
			$this->container->get(WatermarkService::class),
			true,
		));

		// Folder shares are downloaded as an archive, which the single-file interceptor
		// never sees — same gap as on the authenticated server.
		$server->addPlugin(new ZipInterceptorPlugin(
			$this->container->get(WatermarkService::class),
			$this->container->get(IDateTimeZone::class),
			$this->container->get(IEventDispatcher::class),
			$this->container->get(LoggerInterface::class),
			true,
		));
	}
}
