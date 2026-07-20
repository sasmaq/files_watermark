<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FilesWatermark\Dav\DownloadInterceptorPlugin;
use OCA\FilesWatermark\Dav\PropFindPlugin;
use OCA\FilesWatermark\Dav\UploadWatermarkPlugin;
use OCA\FilesWatermark\Dav\ZipInterceptorPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;

/**
 * Registers the watermark plugins on the Files WebDAV server: {@see PropFindPlugin}
 * serves the `{http://nextcloud.org/ns}is-watermarked` property for file nodes, and
 * {@see DownloadInterceptorPlugin} streams a watermarked copy on download when the
 * effective trigger is `on_download`.
 *
 * @template-implements IEventListener<SabrePluginAddEvent>
 */
class SabrePluginAddListener implements IEventListener {

	public function __construct(
		private ContainerInterface $container,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof SabrePluginAddEvent)) {
			return;
		}

		$server = $event->getServer();
		$server->addPlugin($this->container->get(PropFindPlugin::class));
		$server->addPlugin($this->container->get(DownloadInterceptorPlugin::class));
		// Folder / multi-file downloads are served as an archive by core's
		// ZipFolderPlugin, which the single-file interceptor never sees.
		$server->addPlugin($this->container->get(ZipInterceptorPlugin::class));
		// Burns the on_upload watermark in-request, so an upload does not sit clean until
		// cron runs the queued job.
		$server->addPlugin($this->container->get(UploadWatermarkPlugin::class));
	}
}
