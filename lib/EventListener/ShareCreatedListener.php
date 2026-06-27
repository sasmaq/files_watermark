<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\IUserSession;
use OCP\Share\Events\ShareCreatedEvent;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<ShareCreatedEvent> */
class ShareCreatedListener implements IEventListener {

    public function __construct(
        private WatermarkService $watermarkService,
        private IUserSession     $userSession,
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

        if (!$this->watermarkService->isSupported($node->getMimeType())) {
            return;
        }

        try {
            $config = $this->watermarkService->resolveConfig(
                $this->userSession->getUser()?->getUID()
            );
        } catch (\Throwable) {
            return;
        }

        if ($config->getTrigger() !== 'on_share') {
            return;
        }

        try {
            $tmpPath = $this->watermarkService->watermarkFile($node, 'on_share', $config);
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: failed to watermark on share: ' . $e->getMessage(), [
                'exception' => $e,
                'path'      => $node->getPath(),
            ]);
            return;
        }

        try {
            $base    = pathinfo($node->getName(), PATHINFO_FILENAME);
            $ext     = pathinfo($node->getName(), PATHINFO_EXTENSION);
            $newName = $base . '_shared' . ($ext !== '' ? '.' . $ext : '');
            $parent  = $node->getParent();
            $content = file_get_contents($tmpPath);

            if ($parent->nodeExists($newName)) {
                /** @var File $existing */
                $existing = $parent->get($newName);
                $existing->putContent($content);
            } else {
                $parent->newFile($newName, $content);
            }
        } catch (\Throwable $e) {
            $this->logger->error('files_watermark: failed to save shared copy: ' . $e->getMessage(), [
                'exception' => $e,
                'path'      => $node->getPath(),
            ]);
        } finally {
            @unlink($tmpPath);
            @rmdir(dirname($tmpPath));
        }
    }
}
