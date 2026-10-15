<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\DAV\Events\BeforeFileDirectDownloadedEvent;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Sabre\DAV\Exception\Forbidden;

/**
 * Refuses a direct-link download of any file this app would watermark.
 *
 * ---------------------------------------------------------------------------
 * THE ONE DOWNLOAD ROUTE THAT CANNOT BE WATERMARKED, ONLY CLOSED.
 *
 * Any account that can read a file can ask
 * `POST /ocs/v2.php/apps/dav/api/v1/direct` for a `/remote.php/direct/{token}` link to it.
 * That link is served by a separate Sabre server that announces itself to no app - there is
 * no plugin event to hook - and `DirectFile::get()` opens the stored file and hands the
 * stream straight to Sabre. A share recipient could mint one for a marked document and
 * download the clean original, and the client documentation's "never request one for these
 * types" was the only thing standing in the way.
 *
 * What that server *does* do is dispatch `BeforeFileDirectDownloadedEvent` immediately before
 * it opens the file. The event has no veto flag, but nothing between it and Sabre catches an
 * exception, so throwing here is the refusal: the client gets a 403 and no bytes. A
 * watermarked copy is not on offer - the stream is core's to open - so closing the route is
 * the whole of what can be done here, and it is the posture the app takes everywhere else
 * when a watermark cannot be produced.
 * ---------------------------------------------------------------------------
 *
 * **The question is the same one every delivery asks** ({@see WatermarkService::isDeliveryCandidate}).
 * A direct link is fetched with no session, so the share switches see it as what it is - an
 * anonymous bearer URL, the same thing as a public link - and a file the external-share
 * switch covers is refused here as well. Its owner still downloads it through the ordinary
 * routes, which are the ones that watermark.
 *
 * @template-implements IEventListener<BeforeFileDirectDownloadedEvent>
 */
class DirectDownloadListener implements IEventListener {

	public function __construct(
		private WatermarkService $watermarkService,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeFileDirectDownloadedEvent)) {
			return;
		}

		if ($this->watermarkService->isDeliveryCandidate($event->getFile())) {
			throw new Forbidden('This file is watermarked on download, so it cannot be fetched through a direct link. Download it through the Files app or a sync client instead.');
		}
	}
}
