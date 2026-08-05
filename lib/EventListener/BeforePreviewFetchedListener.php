<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\Preview\PreviewRequestContext;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Preview\BeforePreviewFetchedEvent;

/**
 * Notes that this request is fetching a preview of a marked file.
 *
 * It does not block anything and it does not watermark anything. Every preview endpoint in
 * the server passes through this event, so it is the one place that can see *which* file a
 * preview request is for without knowing what route asked - and {@see PreviewRequestContext}
 * explains at length why that matters. The watermarking happens in
 * {@see \OCA\FilesWatermark\Middleware\WatermarkPreviewMiddleware}, once the controller has
 * run.
 *
 * This listener used to throw, denying previews of shared files outright, because a
 * watermarked preview could not be produced safely: core's preview cache is keyed by file
 * and size and never by viewer, so one recipient's stamped thumbnail would have been served
 * to the next with the first one's name on it. Nothing watermarked goes into that cache now
 * - the stamping happens per response, after the cache - so the previews can come back.
 *
 * @template-implements IEventListener<BeforePreviewFetchedEvent>
 */
class BeforePreviewFetchedListener implements IEventListener {

	public function __construct(
		private WatermarkService $watermarkService,
		private PreviewRequestContext $context,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforePreviewFetchedEvent)) {
			return;
		}

		$node = $event->getNode();
		if (!($node instanceof File)) {
			return;
		}

		// Only files this app can watermark, and only marked ones. Anything else is served
		// by core untouched, which is both correct and the cheap path - this runs on every
		// thumbnail in every folder listing.
		if (!$this->watermarkService->isDeliveryCandidate($node)) {
			return;
		}

		// The dimensions are nullable on the event and are a *request*, not a promise: core
		// clamps them to its configured maxima and to the source's own size. They are
		// recorded as a hint for scaling the watermark, and the middleware re-measures the
		// image it actually gets.
		$this->context->record($node, $event->getWidth() ?? 0, $event->getHeight() ?? 0);
	}
}
