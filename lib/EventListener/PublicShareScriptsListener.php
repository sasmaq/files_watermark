<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\EventListener;

use OCA\FilesWatermark\AppInfo\Application;
use OCA\FilesWatermark\AppInfo\FilesPageScript;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Loads the watermark badge on a **public share page**, so a link visitor sees which of
 * the files behind the link are watermarked on their way out.
 *
 * ---------------------------------------------------------------------------
 * WHY A SECOND LISTENER, AND A SECOND BUNDLE.
 *
 * {@see LoadAdditionalScriptsListener} hooks `OCA\Files\Event\LoadAdditionalScriptsEvent`,
 * which the *Files app* fires. The public share page is rendered by `files_sharing` and
 * fires its own event, so nothing this app asks for there arrives by that route.
 *
 * The script it asks for is `public`, not `files`: a visitor can read the list and
 * download, and nothing else. Shipping them the Files bundle would hand every visitor of
 * every public link two Vue modals and a pair of file actions that need a session they do
 * not have. See `src/main-public.js`.
 * ---------------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------------
 * **WHY THE STATUS IS ALSO HANDED OVER AS INITIAL STATE.**
 *
 * The badge's normal source is the `is-watermarked` DAV property, which the client asks
 * for only if this app's `registerDavProperty()` has run before the file list builds its
 * PROPFIND. On a public page this app's script is emitted *after* `files-main.js`, and the
 * measured result was a listing with no property on any node.
 *
 * On a folder that is the familiar "first listing is wrong" symptom - navigate into a
 * subfolder and the badges appear. **On a single-file share nothing recovers it**: that
 * view fetches its one node once and never lists anything again, so the badge never
 * appeared at all. That is what this state fixes, and it fixes it by not racing: the
 * server already knows the answer while it is rendering the page, so it says so, and the
 * client needs nothing to have happened in the right order.
 *
 * The DAV property stays the source for everything after the first paint - navigating into
 * a subfolder, where by then the registration has certainly run.
 * ---------------------------------------------------------------------------
 *
 * **This is the one place the app tells an unauthenticated visitor anything.** Everything
 * else it exposes is behind a session. The disclosure is deliberate: a watermark deters
 * best when the person holding the copy knows it is there, and on a public link the mark
 * names the file's *owner* rather than the visitor, so it identifies nobody who was not
 * already accountable for publishing the link. Nothing about the policy itself is
 * disclosed - only which of these files, on their way out, carry a watermark.
 *
 * The event class belongs to `files_sharing`, a shipped app that public links cannot work
 * without; the listener is registered by class-string, so a server with it disabled simply
 * never fires this.
 *
 * @template-implements IEventListener<Event>
 */
class PublicShareScriptsListener implements IEventListener {

	/**
	 * Children of a shared folder to resolve up front.
	 *
	 * The first listing is what the state exists to cover, and a page-sized folder is all
	 * a visitor sees before the client's own PROPFIND takes over. A share of ten thousand
	 * files would otherwise pay for a full directory listing *and* a mark query on every
	 * page load, to answer a question the DAV property answers a moment later anyway.
	 */
	private const MAX_SEEDED_CHILDREN = 200;

	public function __construct(
		private IInitialState $initialState,
		private WatermarkService $watermarkService,
		private ShareAccess $shareAccess,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		// No instanceof check: this listener is registered against exactly one event class
		// and `files_sharing` fires it for the public page and nothing else. Naming the
		// class here as well would make this file fail to load on a server where that app
		// is disabled, which is the one situation where doing nothing is already correct.

		// Without the app's `l10n/<lang>.js` the badge's tooltip falls through to its
		// English source - which looks exactly like a working translation to anything
		// except a reader of it.
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, FilesPageScript::PUBLIC_SCRIPT);

		$this->initialState->provideInitialState('public-watermarked-ids', $this->watermarkedIds($event));
	}

	/**
	 * The ids behind this link that will be watermarked on their way out.
	 *
	 * Reaching the share is duck-typed on purpose: `getShare()` belongs to
	 * `files_sharing`'s event class, and naming that class here would tie this file's
	 * loading to that app. A shape this does not recognise yields an empty list and the
	 * badge falls back to the DAV property, which is where it came from.
	 *
	 * @return list<int>
	 *
	 * Public so it can be tested on its own: {@see handle()} adds a script and a
	 * translation bundle, neither of which a unit test can reach without a server.
	 */
	public function watermarkedIds(Event $event): array {
		try {
			if (!method_exists($event, 'getShare')) {
				return [];
			}

			$share = $event->getShare();
			$node = $share?->getNode();
			if (!($node instanceof Node)) {
				return [];
			}

			// This page *is* the public link, which is the same proof the public DAV
			// server's listener raises - and it is what lets the policy's "watermark public
			// links" switch answer for files nobody has marked. Without it a shared file
			// would be reported clean and then arrive watermarked.
			$this->shareAccess->notePublicRequest();

			return $this->deliveryCandidates($node);
		} catch (\Throwable $e) {
			// A share page must render. The badge is an indicator; the download decides the
			// real answer for itself, and the DAV property still covers every later listing.
			$this->logger->debug('files_watermark: could not resolve public share watermark status', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return [];
		}
	}

	/**
	 * @return list<int> ids of $node itself (a single-file share) or of its immediate
	 *                   children (a folder share) that a fetch would watermark
	 */
	private function deliveryCandidates(Node $node): array {
		if ($node instanceof File) {
			return $this->watermarkService->isDeliveryCandidate($node) ? [$node->getId()] : [];
		}

		if (!($node instanceof Folder)) {
			return [];
		}

		$ids = [];
		foreach (array_slice($node->getDirectoryListing(), 0, self::MAX_SEEDED_CHILDREN) as $child) {
			if ($child instanceof File && $this->watermarkService->isDeliveryCandidate($child)) {
				$ids[] = $child->getId();
			}
		}

		return $ids;
	}
}
