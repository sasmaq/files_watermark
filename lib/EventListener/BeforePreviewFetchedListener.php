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

		// An old version is previewed through its own node, which has a file id of its own
		// that no mark names - so the question is asked of the file it is a version of,
		// noted by the middleware before the version controller ran. Everything else is its
		// own subject.
		$subject = $this->context->versionSource() ?? $node;

		// Marked files are stamped. Unmarked files of a type this app can watermark are
		// recorded too, unstamped, because they can be marked tomorrow and the middleware
		// has to stop their clean preview being cached past that. Anything else is served
		// by core untouched, which is both correct and the cheap path - this runs on every
		// thumbnail in every folder listing.
		$stamp = $this->watermarkService->isDeliveryCandidate($subject);
		if (!$stamp && !$this->watermarkService->isSupported($node->getMimetype())) {
			return;
		}

		// The dimensions are nullable on the event and are a *request*, not a promise: core
		// clamps them to its configured maxima and to the source's own size. They are
		// recorded together with the crop, the mode and the type, so the middleware asks
		// core for the very preview it just served, and it re-measures the image it gets.
		$this->context->record(
			$node,
			$event->getWidth() ?? 0,
			$event->getHeight() ?? 0,
			$stamp,
			$event->isCrop(),
			$event->getMode(),
			$event->getMimeType(),
			$subject === $node ? null : $subject,
		);
	}
}
