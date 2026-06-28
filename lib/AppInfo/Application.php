<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesWatermark\EventListener\LoadAdditionalScriptsListener;
use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\EventListener\ShareCreatedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Share\Events\ShareCreatedEvent;

class Application extends App implements IBootstrap {

    public const APP_ID = 'files_watermark';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);
        $context->registerEventListener(ShareCreatedEvent::class, ShareCreatedListener::class);
        $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScriptsListener::class);
    }

    public function boot(IBootContext $context): void {
        // nothing to do at boot time
    }
}
