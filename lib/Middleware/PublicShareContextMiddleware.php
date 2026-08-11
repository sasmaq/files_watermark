<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Middleware;

use OCA\FilesWatermark\Service\ShareAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\PublicShareController;

/**
 * Tells {@see ShareAccess} that this request is a public-link fetch, on the routes that
 * reach a public link **without** going through the public DAV server.
 *
 * ---------------------------------------------------------------------------
 * THE HOLE THIS CLOSES: A LOGGED-IN VISITOR'S PREVIEW.
 *
 * `ShareAccess` had two signals for "this is a public link", and previews fell between
 * them. {@see \OCA\FilesWatermark\EventListener\SabrePublicPluginAddListener} raises the
 * flag when the public-link *DAV server* is built, which covers every download; and a
 * request with no session user at all can only have arrived through a link, which covers
 * every anonymous visitor.
 *
 * A **logged-in** user opening somebody else's public link is neither. They have a session,
 * so the second signal says nothing, and thumbnails do not go through DAV -
 * `/apps/files_sharing/publicpreview/{token}` is an ordinary app-framework route - so the
 * first never fired either. The result was a file whose *download* through that link was
 * watermarked while its *preview* on the same page was not, which is the shape of leak this
 * app exists to prevent: the first page of a document, legible, to the one reader the
 * policy was written for.
 *
 * (Marked files were never affected - a mark applies to every reader, and needs no share
 * detection. This is only about the two "watermark shared files" switches, which decide
 * per fetch and therefore have to know what kind of fetch this is.)
 * ---------------------------------------------------------------------------
 *
 * **It asks the controller's type, not the request's path.** `PublicShareController` is the
 * OCP base class for "a controller authenticated by a share token and nothing else", and
 * `files_sharing`'s preview and share-page controllers both extend it. A list of preview
 * URLs would have to be kept in step with core across releases, and the same reasoning that
 * put the preview interception on an event rather than a route list applies here: one
 * missed route is not a missing feature, it is an unwatermarked copy of a protected file.
 * Anything core adds under that base class arrives here already handled.
 *
 * Registered **global**, for the same reason the preview middleware is: the controllers it
 * has to see belong to `files_sharing`, and an app's middleware reaches controllers it does
 * not own only with that flag.
 */
class PublicShareContextMiddleware extends Middleware {

	public function __construct(
		private ShareAccess $shareAccess,
	) {
	}

	/**
	 * Before the controller runs, because the flag has to be up before anything reads it:
	 * core's preview controller dispatches `BeforePreviewFetchedEvent` from inside its own
	 * method, and this app's listener answers that event by asking `ShareAccess` whether the
	 * fetch is a share. A flag raised afterwards would be raised for nobody.
	 */
	public function beforeController(Controller $controller, string $methodName): void {
		if ($controller instanceof PublicShareController) {
			$this->shareAccess->notePublicRequest();
		}
	}
}
