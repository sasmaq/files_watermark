<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\NotFoundException;
use OCP\Preview\BeforePreviewFetchedEvent;

/**
 * Denies file previews to share recipients when the file's policy is `on_share`.
 *
 * On-share watermarking is applied at download time (a streamed watermarked copy),
 * but previews are rendered from the clean original and cached globally — so without
 * this a recipient could read the unwatermarked content straight from its thumbnail,
 * bypassing the watermark entirely.
 *
 * Preview caches are keyed by file + size, never by viewer, so a watermarked preview
 * cannot be shown to recipients alone. Instead we block the preview outright for
 * non-owners (they get the generic file-type icon); the owner's own previews are left
 * untouched. Blocking is the one thing {@see BeforePreviewFetchedEvent} supports — it
 * runs per request, so it can tell owner from recipient, and throwing
 * NotFoundException aborts the preview.
 *
 * @template-implements IEventListener<BeforePreviewFetchedEvent>
 */
class BeforePreviewFetchedListener implements IEventListener {

    public function __construct(
        private WatermarkService $watermarkService,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof BeforePreviewFetchedEvent)) {
            return;
        }

        $node = $event->getNode();

        // Only guard the types we actually watermark; other types never carry a
        // watermark on download, so denying their previews would protect nothing.
        if (!$this->watermarkService->isSupported($node->getMimetype())) {
            return;
        }

        // Only restrict share recipients / public-link visitors — the owner viewing
        // their own file keeps normal previews. Detected from the storage backend, not
        // by comparing user ids (which is unreliable in the preview request context).
        if (!$this->watermarkService->isReceivedShare($node)) {
            return;
        }

        try {
            $config = $this->watermarkService->resolveConfig($node->getOwner()?->getUID());
        } catch (\Throwable) {
            return;
        }

        if ($config->getTrigger() === 'on_share') {
            throw new NotFoundException('Preview blocked: file is watermarked on share.');
        }
    }
}
