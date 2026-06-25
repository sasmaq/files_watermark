<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<NodeWrittenEvent> */
class NodeWrittenListener implements IEventListener {

    private const SUPPORTED_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private WatermarkService      $watermarkService,
        private WatermarkConfigMapper $configMapper,
        private LoggerInterface       $logger,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof NodeWrittenEvent)) {
            return;
        }

        $node = $event->getNode();

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
        if (!in_array('on_upload', $triggers, true)) {
            return;
        }

        try {
            $this->watermarkService->watermarkInPlace($node, 'on_upload', $config);
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: failed to watermark on upload: ' . $e->getMessage(), [
                'exception' => $e,
                'path'      => $node->getPath(),
            ]);
        }
    }
}
