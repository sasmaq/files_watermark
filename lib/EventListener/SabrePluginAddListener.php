<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FilesWatermark\Dav\PropFindPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;

/**
 * Registers the watermark PROPFIND plugin on the Files WebDAV server so the
 * `{http://nextcloud.org/ns}is-watermarked` property is served for file nodes.
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

        $event->getServer()->addPlugin($this->container->get(PropFindPlugin::class));
    }
}
