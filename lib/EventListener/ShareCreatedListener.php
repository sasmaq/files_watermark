<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Share\Events\ShareCreatedEvent;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<ShareCreatedEvent> */
class ShareCreatedListener implements IEventListener {

    private const SUPPORTED_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private WatermarkService $watermarkService,
        private LoggerInterface  $logger,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof ShareCreatedEvent)) {
            return;
        }

        $share = $event->getShare();
        $node  = $share->getNode();

        if (!($node instanceof File)) {
            return;
        }

        if (!in_array($node->getMimeType(), self::SUPPORTED_MIME, true)) {
            return;
        }

        try {
            $config = $this->watermarkService->resolveConfig();
        } catch (\Throwable) {
            return;
        }

        $triggers = array_map('trim', explode(',', $config->getTrigger()));
        if (!in_array('on_share', $triggers, true)) {
            return;
        }

        try {
            $this->watermarkService->watermarkInPlace($node, 'on_share', $config);
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: failed to watermark on share: ' . $e->getMessage(), [
                'exception' => $e,
                'path'      => $node->getPath(),
            ]);
        }
    }
}
