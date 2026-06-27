<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<NodeWrittenEvent> */
class NodeWrittenListener implements IEventListener {

    // File IDs currently being watermarked — prevents re-entrant loop when
    // watermarkInPlace() writes the file and fires another NodeWrittenEvent.
    private static array $processing = [];

    public function __construct(
        private WatermarkService $watermarkService,
        private IUserSession     $userSession,
        private LoggerInterface  $logger,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof NodeWrittenEvent)) {
            return;
        }

        $node = $event->getNode();

        if (!($node instanceof File)) {
            return;
        }

        if (!in_array($node->getMimeType(), WatermarkService::SUPPORTED_ALL, true)) {
            return;
        }

        $fileId = $node->getId();
        if (isset(self::$processing[$fileId])) {
            return;
        }

        try {
            $config = $this->watermarkService->resolveConfig(
                $this->userSession->getUser()?->getUID()
            );
        } catch (\Throwable) {
            return;
        }

        if ($config->getTrigger() !== 'on_upload') {
            return;
        }

        self::$processing[$fileId] = true;
        try {
            $this->watermarkService->watermarkInPlace($node, 'on_upload', $config);
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: failed to watermark on upload: ' . $e->getMessage(), [
                'exception' => $e,
                'path'      => $node->getPath(),
            ]);
        } finally {
            unset(self::$processing[$fileId]);
        }
    }
}
