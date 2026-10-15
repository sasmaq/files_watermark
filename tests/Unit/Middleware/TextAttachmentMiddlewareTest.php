<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Middleware;

use OCA\FilesWatermark\Middleware\TextAttachmentMiddleware;
use OCA\FilesWatermark\Preview\PreviewRequestContext;
use OCA\FilesWatermark\Service\RenderRateLimitedException;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\ShareRecipient;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Text\Controller\AttachmentController;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Text serves a document's attachments with `getContent()` from its own controller, which
 * was a way to fetch a marked file clean: copy it into the attachment folder of your own
 * note, then ask Text for it. These pin the replacement of that response.
 *
 * @covers \OCA\FilesWatermark\Middleware\TextAttachmentMiddleware
 */
class TextAttachmentMiddlewareTest extends TestCase {

	private const DOCUMENT_ID = 1542;
	private const ATTACHMENT_ID = 77;

	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;
	private IShareManager&MockObject $shareManager;
	private ShareAccess $shareAccess;
	private PreviewRequestContext $previews;

	/** @var array<string, mixed> */
	private array $params = ['mediaFileName' => 'report.pdf'];

	/** @var array<string, Folder&MockObject> user folders by uid */
	private array $userFolders = [];

	/** @var string[] */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')
			->willReturnCallback(fn (string $uid): Folder => $this->userFolders[$uid] ?? $this->userFolder([]));
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->previews = new PreviewRequestContext();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->shareAccess = new ShareAccess($session);
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $path) {
			@unlink($path);
			@rmdir(dirname($path));
		}
		parent::tearDown();
	}

	private function middleware(): TextAttachmentMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default);

		return new TextAttachmentMiddleware(
			$this->watermarkService,
			$this->shareAccess,
			new ShareRecipient($this->shareManager),
			$this->previews,
			$this->rootFolder,
			$this->shareManager,
			$request,
			new NullLogger(),
		);
	}

	private function controller(string $uid = 'alice'): AttachmentController&MockObject {
		$controller = $this->createMock(AttachmentController::class);
		$controller->method('getDocumentId')->willReturn(self::DOCUMENT_ID);
		$controller->method('getUserId')->willReturn($uid);
		return $controller;
	}

	private function owner(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	/** @param array<int, ?\OCP\Files\Node> $byId */
	private function userFolder(array $byId): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')
			->willReturnCallback(static fn (int $id): ?\OCP\Files\Node => $byId[$id] ?? null);
		return $folder;
	}

	private function attachment(string $owner = 'bob'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(self::ATTACHMENT_ID);
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getOwner')->willReturn($this->owner($owner));
		return $file;
	}

	/**
	 * The document as its owner holds it, with `.attachments.{id}/report.pdf` beside it.
	 */
	private function ownersDocument(File $attachment, string $owner = 'bob'): File&MockObject {
		$attachments = $this->createMock(Folder::class);
		$attachments->method('get')->with('report.pdf')->willReturn($attachment);

		$parent = $this->createMock(Folder::class);
		$parent->method('get')->with('.attachments.' . self::DOCUMENT_ID)->willReturn($attachments);

		$document = $this->createMock(File::class);
		$document->method('getId')->willReturn(self::DOCUMENT_ID);
		$document->method('getOwner')->willReturn($this->owner($owner));
		$document->method('getParent')->willReturn($parent);
		return $document;
	}

	private function textsResponse(): DataDownloadResponse {
		return new DataDownloadResponse('CLEAN-ORIGINAL', 'report.pdf', 'application/pdf');
	}

	private function renderedCopy(string $contents = 'WATERMARKED'): string {
		$dir = sys_get_temp_dir() . '/nc_watermark_test_' . uniqid('', true);
		mkdir($dir);
		$path = $dir . '/render.pdf';
		file_put_contents($path, $contents);
		$this->tmpFiles[] = $path;
		return $path;
	}

	/** Alice owns the note and the attachment she copied into it. */
	private function aliceOwnsEverything(): File&MockObject {
		$attachment = $this->attachment('alice');
		$document = $this->ownersDocument($attachment, 'alice');
		$this->userFolders['alice'] = $this->userFolder([
			self::DOCUMENT_ID => $document,
			self::ATTACHMENT_ID => $attachment,
		]);
		return $attachment;
	}

	private function onOwnersStorage(): FileInfo {
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);
		$node = $this->createMock(FileInfo::class);
		$node->method('getStorage')->willReturn($storage);
		return $node;
	}

	/**
	 * The bypass this exists for: the copy in the attachment folder is marked, and Text used
	 * to hand it over as stored.
	 */
	public function testAProtectedAttachmentIsReplacedWithTheReadersCopy(): void {
		$attachment = $this->aliceOwnsEverything();
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($attachment)
			->willReturn($this->renderedCopy());

		$response = $this->middleware()->afterController($this->controller(), 'getMediaFile', $this->textsResponse());

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('WATERMARKED', $response->render());
		$this->assertSame('application/pdf', $response->getHeaders()['Content-Type']);
		$this->assertSame('private, no-store', $response->getHeaders()['Cache-Control']);
	}

	public function testTheRawImageRouteIsCoveredToo(): void {
		$this->params = ['imageFileName' => 'report.pdf', 'preferRawImage' => '1'];
		$this->aliceOwnsEverything();
		$this->watermarkService->method('watermarkForDownload')->willReturn($this->renderedCopy());

		$response = $this->middleware()->afterController($this->controller(), 'getImageFile', $this->textsResponse());

		$this->assertSame('WATERMARKED', $response->render());
	}

	public function testAnUnprotectedAttachmentKeepsTextsResponse(): void {
		$this->aliceOwnsEverything();
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);
		$original = $this->textsResponse();

		$this->assertSame($original, $this->middleware()->afterController($this->controller(), 'getMediaFile', $original));
	}

	public function testAProtectedAttachmentThatCannotBeRenderedIsRefused(): void {
		$this->aliceOwnsEverything();
		$this->watermarkService->method('watermarkForDownload')
			->willThrowException(new WatermarkRequiredException('/alice/files/.attachments.1542/report.pdf'));

		$response = $this->middleware()->afterController($this->controller(), 'getMediaFile', $this->textsResponse());

		$this->assertSame(403, $response->getStatus());
	}

	public function testAnAnonymousAddressPastItsRenderLimitIsToldToComeBack(): void {
		$this->aliceOwnsEverything();
		$this->watermarkService->method('watermarkForDownload')
			->willThrowException(new RenderRateLimitedException(600));

		$response = $this->middleware()->afterController($this->controller(), 'getMediaFile', $this->textsResponse());

		$this->assertSame(429, $response->getStatus());
		$this->assertSame('600', $response->getHeaders()['Retry-After']);
	}

	/**
	 * Text answered with something this cannot place. It has to be refused: whether it was a
	 * protected file is exactly what is unknown.
	 */
	public function testAnAttachmentThatCannotBeResolvedIsRefused(): void {
		$this->userFolders['alice'] = $this->userFolder([]);
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$response = $this->middleware()->afterController($this->controller(), 'getMediaFile', $this->textsResponse());

		$this->assertSame(404, $response->getStatus());
	}

	/** `/apps/text/image` normally answers with a preview, which the preview middleware stamps. */
	public function testAPreviewIsLeftToThePreviewMiddleware(): void {
		$this->params = ['imageFileName' => 'pic.png'];
		$this->previews->record($this->createMock(File::class), 1024, 1024);
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');
		$original = $this->textsResponse();

		$this->assertSame($original, $this->middleware()->afterController($this->controller(), 'getImageFile', $original));
	}

	public function testOtherControllersAndMethodsAreLeftAlone(): void {
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');
		$original = $this->textsResponse();
		$middleware = $this->middleware();

		$this->assertSame($original, $middleware->afterController($this->createMock(Controller::class), 'getMediaFile', $original));
		$this->assertSame($original, $middleware->afterController($this->controller(), 'getAttachmentList', $original));
	}

	public function testAFailedResponseIsLeftAlone(): void {
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');
		$notFound = new Response();
		$notFound->setStatus(404);

		$this->assertSame($notFound, $this->middleware()->afterController($this->controller(), 'getMediaFile', $notFound));
	}

	/**
	 * A reader who has the note only as a single-file share cannot see its attachment folder:
	 * Text reads it through the owner's folder. The storage then says "the owner reading their
	 * own file", so the route has to say otherwise.
	 */
	public function testAReaderWhoReachesTheAttachmentOnlyThroughTheDocumentIsAnIndirectShare(): void {
		$attachment = $this->attachment('bob');
		$ownersDocument = $this->ownersDocument($attachment, 'bob');
		$this->userFolders['bob'] = $this->userFolder([self::DOCUMENT_ID => $ownersDocument]);
		// Alice reaches the document, not the attachment.
		$this->userFolders['alice'] = $this->userFolder([self::DOCUMENT_ID => $ownersDocument]);

		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($attachment)
			->willReturn(null);

		$this->middleware()->afterController($this->controller('alice'), 'getMediaFile', $this->textsResponse());

		$this->assertTrue($this->shareAccess->isInternalShareAccess($this->onOwnersStorage()));
	}

	/** Text takes its public route whenever a token is present, and so does this. */
	public function testAShareTokenIsAPublicLink(): void {
		$this->params = ['mediaFileName' => 'report.pdf', 'shareToken' => 'TOKEN'];
		$attachment = $this->attachment('bob');
		$ownersDocument = $this->ownersDocument($attachment, 'bob');
		$this->userFolders['bob'] = $this->userFolder([self::DOCUMENT_ID => $ownersDocument]);

		$sharedFolder = $this->userFolder([self::DOCUMENT_ID => $ownersDocument]);
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$share->method('getNode')->willReturn($sharedFolder);
		$this->shareManager->method('getShareByToken')->with('TOKEN')->willReturn($share);

		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($attachment)
			->willReturn($this->renderedCopy());

		$response = $this->middleware()->afterController($this->controller(), 'getMediaFile', $this->textsResponse());

		$this->assertSame('WATERMARKED', $response->render());
		$this->assertTrue($this->shareAccess->isExternalShareAccess());
	}

	public function testAShareTokenOfAnotherKindIsNotTextsPublicRoute(): void {
		$this->params = ['mediaFileName' => 'report.pdf', 'shareToken' => 'TOKEN'];
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_USER);
		$this->shareManager->method('getShareByToken')->willReturn($share);

		$response = $this->middleware()->afterController($this->controller(), 'getMediaFile', $this->textsResponse());

		$this->assertSame(404, $response->getStatus());
	}
}
