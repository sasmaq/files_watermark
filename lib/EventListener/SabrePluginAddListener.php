<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FilesWatermark\Dav\DownloadInterceptorPlugin;
use OCA\FilesWatermark\Dav\PropFindPlugin;
use OCA\FilesWatermark\Dav\ZipInterceptorPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;

/**
 * Registers the watermark plugins on the Files WebDAV server: {@see PropFindPlugin}
 * serves the `{http://nextcloud.org/ns}is-watermarked` property for file nodes, and
 * {@see DownloadInterceptorPlugin} streams a watermarked copy whenever a marked file is
 * fetched.
 *
 * Two plugins used to be registered here and are not any more, both for the same reason:
 * nothing writes to storage. `HideOriginalsPlugin` hid the preserved copies the burn made,
 * and `UploadWatermarkPlugin` existed to burn the upload watermark in-request rather than
 * leaving the file clean until cron. There are no copies to hide, and marking happens in
 * the write event itself.
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
	}
}
