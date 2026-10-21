<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Middleware;

use OCA\FilesWatermark\Preview\PreviewRequestContext;
use OCA\FilesWatermark\Service\RenderRateLimitedException;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\ShareRecipient;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Text\Controller\AttachmentController;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Watermarks the files the Text app serves out of a document's attachment folder.
 *
 * ---------------------------------------------------------------------------
 * TEXT READS FILES FOR ITSELF, AND THAT WAS A WAY OUT.
 *
 * Text keeps a document's attachments in `.attachments.{documentId}` beside it and serves
 * them from its own controller: `/apps/text/media` hands over any of them with
 * `getContent()`, and `/apps/text/image` does the same for an image whenever it is asked
 * for the raw file (`preferRawImage=1`), or for a GIF, or for a type with no preview. None
 * of that goes near DAV, so none of this app's interceptors saw it. A reader who could see a
 * marked file could copy it into the attachment folder of a note of their own - the copy
 * even inherited the mark - and fetch it from Text clean.
 * ---------------------------------------------------------------------------
 *
 * **Replaced after Text has answered**, so Text's own permission checks have already run
 * and passed, and this only has to find the file Text served and ask the ordinary question
 * of it: is it protected, and if so, here is the reader's copy instead. A file that is not
 * protected keeps Text's response untouched.
 *
 * The attachment is resolved exactly as Text resolves it - through the **owner's** folder,
 * whatever the reader can see - and then, for a signed-in reader, through the reader's own
 * folder, so a share mounted for them is seen by the share switches. A reader who reaches
 * it only through the document (a single-file share of the note, say) is reported as an
 * indirect share. A request with a share token is a public link.
 *
 * **A response this cannot place is refused**, with the 404 Text itself answers a missing
 * attachment with. Text has just served a file; if the same resolution cannot find it, this
 * cannot say whether it was protected, and "probably not" is not an answer this app gives.
 *
 * A preview is left alone: `/apps/text/image` normally answers with one, and
 * {@see WatermarkPreviewMiddleware} stamps those.
 *
 * Registered globally - Text's controllers are reachable no other way.
 */
class TextAttachmentMiddleware extends Middleware {

	/** The controller methods that can answer with stored bytes, and their name parameter. */
	private const RAW_METHODS = [
		'getMediaFile' => 'mediaFileName',
		'getImageFile' => 'imageFileName',
	];

	public function __construct(
		private WatermarkService $watermarkService,
		private ShareAccess $shareAccess,
		private ShareRecipient $shareRecipient,
		private PreviewRequestContext $previews,
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}

	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!($controller instanceof AttachmentController) || !isset(self::RAW_METHODS[$methodName])) {
			return $response;
		}

		// A 404 or any other failure: Text served nothing to replace.
		if ($response->getStatus() !== Http::STATUS_OK) {
			return $response;
		}

		if ($this->previews->previewed()) {
			return $response;
		}

		$file = $this->attachment($controller, self::RAW_METHODS[$methodName]);
		if ($file === null) {
			$this->logger->warning('files_watermark: Text served an attachment this app could not resolve; refusing it rather than serving it unchecked', [
				'method' => $methodName,
			]);

			return new DataResponse('', Http::STATUS_NOT_FOUND);
		}

		try {
			$tmpPath = $this->watermarkService->watermarkForDownload($file);
		} catch (WatermarkRequiredException) {
			return new DataResponse('', Http::STATUS_FORBIDDEN);
		} catch (RenderRateLimitedException $e) {
			$refused = new DataResponse('', Http::STATUS_TOO_MANY_REQUESTS);
			$refused->addHeader('Retry-After', (string)$e->getRetryAfter());

			return $refused;
		}

		if ($tmpPath === null) {
			return $this->revalidating($file, $response);
		}

		try {
			$content = @file_get_contents($tmpPath);
		} finally {
			@unlink($tmpPath);
			@rmdir(dirname($tmpPath));
		}

		if ($content === false) {
			return new DataResponse('', Http::STATUS_FORBIDDEN);
		}

		// The type Text chose - it maps anything a browser could run to a safe one - rather
		// than the file's own.
		$contentType = $response->getHeaders()['Content-Type'] ?? null;
		$watermarked = new DataDownloadResponse(
			$content,
			$file->getName(),
			is_string($contentType) ? $contentType : 'application/octet-stream',
		);
		// This copy names its reader.
		$watermarked->addHeader('Cache-Control', 'private, no-store');

		return $watermarked;
	}

	/**
	 * Text's clean copy of an attachment this app could watermark, made unable to outlive a
	 * mark or a share switch.
	 *
	 * Text serves an image `private, max-age=86400, immutable`, at an address with no version
	 * in it - `getImageFile?documentId=…&imageFileName=…`. Neither marking the attachment nor
	 * turning a share switch on changes that address, so a clean copy fetched before either
	 * was reused for a day after it. The same reasoning, and the same header, as
	 * {@see WatermarkPreviewMiddleware::revalidating} gives an unversioned preview.
	 *
	 * A response Text did not allow to be kept carries core's `no-store` and is left so.
	 */
	private function revalidating(File $file, Response $response): Response {
		$cacheControl = $response->getHeaders()['Cache-Control'];
		if (str_contains($cacheControl, 'no-store') || !$this->watermarkService->isSupported($file->getMimeType())) {
			return $response;
		}

		$response->addHeader('Cache-Control', 'private, no-cache');
		/** @psalm-suppress NullArgument - `addHeader(…, null)` is how core deletes a header */
		$response->addHeader('Expires', null);

		return $response;
	}

	/**
	 * The attachment Text just served, as the reader reaches it, or null.
	 */
	private function attachment(AttachmentController $controller, string $nameParameter): ?File {
		$name = $this->request->getParam($nameParameter);
		if (!is_string($name) || $name === '') {
			return null;
		}

		try {
			$documentId = $controller->getDocumentId();
		} catch (\Throwable) {
			return null;
		}

		$token = $this->request->getParam('shareToken', '');
		if (is_string($token) && $token !== '') {
			// Text takes the public route whenever a token is present, signed in or not.
			$this->shareAccess->notePublicRequest();
			$this->shareRecipient->noteShareToken($token);

			$document = $this->publicDocument($documentId, $token);

			return $document === null ? null : $this->ownersAttachment($document, $name);
		}

		try {
			$uid = $controller->getUserId();
			$document = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($documentId);
		} catch (\Throwable) {
			return null;
		}

		if (!($document instanceof File)) {
			return null;
		}

		$attachment = $this->ownersAttachment($document, $name);

		return $attachment === null ? null : $this->asReaderSees($attachment, $uid);
	}

	/**
	 * The document behind a share token, by Text's own rule (`getTextFilePublic`): a link or
	 * mail share of the document itself, or of a folder that contains it.
	 */
	private function publicDocument(int $documentId, string $token): ?File {
		try {
			$share = $this->shareManager->getShareByToken($token);
			if (!in_array($share->getShareType(), [IShare::TYPE_LINK, IShare::TYPE_EMAIL], true)) {
				return null;
			}

			$node = $share->getNode();
			if ($node instanceof Folder) {
				$node = $node->getFirstNodeById($documentId);
			}
		} catch (\Throwable) {
			return null;
		}

		return $node instanceof File && $node->getId() === $documentId ? $node : null;
	}

	/**
	 * `.attachments.{id}/$name` beside the document in its owner's folder - where Text keeps
	 * attachments and reads them from (`getAttachmentDirectoryForFile`).
	 */
	private function ownersAttachment(File $document, string $name): ?File {
		try {
			$owner = $document->getOwner();
			if ($owner === null) {
				return null;
			}

			$ownersDocument = $this->rootFolder->getUserFolder($owner->getUID())->getFirstNodeById($document->getId());
			if ($ownersDocument === null) {
				return null;
			}

			$folder = $ownersDocument->getParent()->get('.attachments.' . $document->getId());
			if (!($folder instanceof Folder)) {
				return null;
			}

			$attachment = $folder->get($name);
		} catch (\Throwable) {
			return null;
		}

		return $attachment instanceof File ? $attachment : null;
	}

	/**
	 * The attachment through the reader's own folder when they can reach it there, so a
	 * share mounted for them is what the share switches see. Otherwise the owner's node, and
	 * - when the reader is somebody else - an indirect share.
	 */
	private function asReaderSees(File $attachment, string $uid): File {
		try {
			$readers = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($attachment->getId());
			if ($readers instanceof File) {
				return $readers;
			}
		} catch (\Throwable) {
			// Not in the reader's folder; decided below.
		}

		$owner = null;
		try {
			$owner = $attachment->getOwner()?->getUID();
		} catch (\Throwable) {
			// An owner that will not resolve is not this reader.
		}
		if ($owner !== $uid) {
			$this->shareAccess->noteIndirectShareAccess();
		}

		return $attachment;
	}
}
