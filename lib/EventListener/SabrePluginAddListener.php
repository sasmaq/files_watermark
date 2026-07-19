<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FilesWatermark\Dav\DownloadInterceptorPlugin;
use OCA\FilesWatermark\Dav\PropFindPlugin;
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
    }
}
