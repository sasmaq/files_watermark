<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Middleware;

use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\ShareRecipient;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Text\Controller\ISessionAwareController;
use OCA\Text\Controller\PublicSessionController;
use OCA\Text\Controller\SessionController;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

/**
 * Refuses the Text editor's session answers that carry a protected file's stored content.
 *
 * ---------------------------------------------------------------------------
 * A TEXT SESSION OPENS ANY FILE, AND ITS ANSWER IS THE FILE.
 *
 * Opening a document in Text is `PUT /apps/text/session/{id}/create` (or the `public/`
 * twin with a share token), and its JSON answer holds the document: `content` is the file's
 * bytes after a charset conversion that can be undone, `documentState` is the editor state
 * saved from them. Text takes any file id or token - no type check - so a marked JPEG or PDF
 * came back clean, signed in or through a public link, past delivery, the share switches
 * and the anonymous render limit alike. `sync` and `save` answer a conflict with
 * `outsideChange`, which is `getContent()` again, and a `push` carrying a sync query gets the
 * saved `documentState` back.
 * ---------------------------------------------------------------------------
 *
 * **Refused, not watermarked.** Text has nothing it could do with a watermarked PDF or
 * image, and the editor is not a way anybody opens one - so an answer that would hand over
 * a protected file's content is replaced with a 403 and a message for the editor to show.
 *
 * **Decided after Text has answered**, like {@see TextAttachmentMiddleware}: Text's own
 * checks have run, and only an answer that actually carries content costs anything here.
 * `sync` runs every few seconds while a note is open, and almost none of its answers do.
 * For the same reason the document is the one Text answered about - the controller's, which
 * Text's own middleware set from the session, or the one `create` returns - never the
 * `{documentId}` in the URL, which `create` ignores in favour of `fileId` or the token.
 *
 * **Every node the reader has for that id is asked**, and one protected node refuses the
 * answer. Text picks among them by permission; asking all of them cannot pick differently.
 * A request with a share token is a public link, read through that share as Text reads it.
 *
 * **An answer this cannot place is refused**, as an attachment is: content went out for a
 * document this cannot find, so whether it was protected is exactly what is unknown.
 *
 * Registered globally - Text's controllers are reachable no other way.
 */
class TextSessionMiddleware extends Middleware {

	/** The fields of Text's answers that hold the document's content, in any of its forms. */
	private const CONTENT_FIELDS = ['content', 'documentState', 'outsideChange'];

	public function __construct(
		private WatermarkService $watermarkService,
		private ShareAccess $shareAccess,
		private ShareRecipient $shareRecipient,
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private IUserSession $userSession,
		private IRequest $request,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!($controller instanceof SessionController) && !($controller instanceof PublicSessionController)) {
			return $response;
		}

		// Core has already turned Text's DataResponse into a JSONResponse by now; both keep
		// the data as Text built it.
		if (!($response instanceof JSONResponse) && !($response instanceof DataResponse)) {
			return $response;
		}

		$data = $response->getData();
		if (!is_array($data) || !$this->carriesContent($data)) {
			return $response;
		}

		$files = $this->documentFiles($controller, $data);
		if ($files === []) {
			$this->logger->warning('files_watermark: Text answered with the content of a document this app could not resolve; refusing it rather than serving it unchecked', [
				'method' => $methodName,
			]);

			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		foreach ($files as $file) {
			try {
				$protected = $this->watermarkService->isDeliveryCandidate($file);
			} catch (\Throwable $e) {
				$this->logger->error('files_watermark: could not tell whether a document Text opened is protected; refusing it: ' . $e->getMessage(), [
					'exception' => $e,
				]);
				$protected = true;
			}

			if ($protected) {
				return new JSONResponse([
					'error' => $this->l->t('This file is watermarked on download, so it cannot be opened here.'),
				], Http::STATUS_FORBIDDEN);
			}
		}

		return $response;
	}

	/** @param array<array-key, mixed> $data */
	private function carriesContent(array $data): bool {
		foreach (self::CONTENT_FIELDS as $field) {
			if (isset($data[$field]) && $data[$field] !== '') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every node the reader reaches the answered document by, or [] when there is none.
	 *
	 * @param array<array-key, mixed> $data
	 * @return File[]
	 */
	private function documentFiles(ISessionAwareController $controller, array $data): array {
		$documentId = $this->documentId($controller, $data);
		if ($documentId === null) {
			return [];
		}

		$token = $this->request->getParam('token');
		if ($controller instanceof PublicSessionController && is_string($token) && $token !== '') {
			$this->shareAccess->notePublicRequest();
			$this->shareRecipient->noteShareToken($token);

			$file = $this->publicDocument($documentId, $token);

			return $file === null ? [] : [$file];
		}

		$uid = $this->readerUid($controller);
		if ($uid === null) {
			return [];
		}

		try {
			$nodes = $this->rootFolder->getUserFolder($uid)->getById($documentId);
		} catch (\Throwable) {
			return [];
		}

		return array_values(array_filter($nodes, static fn ($node): bool => $node instanceof File));
	}

	/**
	 * The document Text answered about. A text document's id is its file's id.
	 *
	 * The calls that need a session have it on the controller, set by Text's own middleware
	 * from the session; `create` has none yet, and names the document in its answer.
	 *
	 * @param array<array-key, mixed> $data
	 */
	private function documentId(ISessionAwareController $controller, array $data): ?int {
		try {
			return $controller->getDocumentId();
		} catch (\Throwable) {
			// `create` - read it off the answer below.
		}

		$document = $data['document'] ?? null;

		return $document instanceof Entity ? $document->getId() : null;
	}

	/**
	 * Whose files the signed-in route opened: the session's user, which Text's middleware
	 * set on the controller, or for `create` the user signed in.
	 */
	private function readerUid(ISessionAwareController $controller): ?string {
		try {
			return $controller->getUserId();
		} catch (\Throwable) {
			return $this->userSession->getUser()?->getUID();
		}
	}

	/**
	 * The document behind a share token, by Text's own rule (`getFileByShareToken`,
	 * `getFileForSession`): the shared file itself, or the file with that id inside a
	 * shared folder. Any share type - Text does not ask.
	 */
	private function publicDocument(int $documentId, string $token): ?File {
		try {
			$node = $this->shareManager->getShareByToken($token)->getNode();
			if ($node instanceof Folder) {
				$node = $node->getFirstNodeById($documentId);
			}
		} catch (\Throwable) {
			return null;
		}

		return $node instanceof File && $node->getId() === $documentId ? $node : null;
	}
}
