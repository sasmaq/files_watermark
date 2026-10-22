<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Middleware;

use OCA\FilesWatermark\Middleware\TextSessionMiddleware;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\ShareRecipient;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Text\Controller\AttachmentController;
use OCA\Text\Controller\PublicSessionController;
use OCA\Text\Controller\SessionController;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Text's session API answers with the opened document's content - `content` and
 * `documentState` from `create`, `outsideChange` from a conflicting `sync` or `save`, the
 * saved `documentState` from a `push` - and took any file id or share token, so it handed a
 * marked file over clean. These pin the refusal of those answers.
 *
 * @covers \OCA\FilesWatermark\Middleware\TextSessionMiddleware
 */
class TextSessionMiddlewareTest extends TestCase {

	private const DOCUMENT_ID = 1542;

	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;
	private IShareManager&MockObject $shareManager;
	private ShareAccess $shareAccess;

	/** @var array<string, mixed> */
	private array $params = [];

	/** @var array<string, Folder&MockObject> user folders by uid */
	private array $userFolders = [];

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')
			->willReturnCallback(fn (string $uid): Folder => $this->userFolders[$uid] ?? $this->userFolder([]));
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->shareAccess = new ShareAccess($this->session('alice'));
	}

	private function session(?string $uid): IUserSession&MockObject {
		$session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}
		return $session;
	}

	private function middleware(?string $signedIn = 'alice'): TextSessionMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new TextSessionMiddleware(
			$this->watermarkService,
			$this->shareAccess,
			new ShareRecipient($this->shareManager),
			$this->rootFolder,
			$this->shareManager,
			$this->session($signedIn),
			$request,
			$l,
			new NullLogger(),
		);
	}

	/**
	 * `create`: no session yet, so Text's middleware has set neither getter - Text throws
	 * `InvalidSessionException` from both.
	 */
	private function creating(string $class = SessionController::class): SessionController|PublicSessionController {
		$controller = $this->createMock($class);
		$controller->method('getDocumentId')->willThrowException(new \RuntimeException('no session'));
		$controller->method('getUserId')->willThrowException(new \RuntimeException('no session'));
		return $controller;
	}

	/** `sync`, `push`, `save`: Text's middleware has set both from the session. */
	private function inSession(string $uid = 'alice', string $class = SessionController::class): SessionController|PublicSessionController {
		$controller = $this->createMock($class);
		$controller->method('getDocumentId')->willReturn(self::DOCUMENT_ID);
		$controller->method('getUserId')->willReturn($uid);
		return $controller;
	}

	/** Stands in for `OCA\Text\Db\Document`, whose id is its file's id. */
	private function document(int $id = self::DOCUMENT_ID): Entity {
		$document = new class extends Entity {
		};
		$document->setId($id);
		return $document;
	}

	/** @param array<int, \OCP\Files\Node[]> $byId */
	private function userFolder(array $byId): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')
			->willReturnCallback(static fn (int $id): array => $byId[$id] ?? []);
		return $folder;
	}

	private function file(int $id = self::DOCUMENT_ID): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	/** What `create` answers for a fresh document: the file's bytes, converted. */
	private function created(): JSONResponse {
		return new JSONResponse([
			'document' => $this->document(),
			'session' => ['id' => 7],
			'readOnly' => false,
			'content' => 'CLEAN-ORIGINAL',
			'documentState' => null,
		]);
	}

	/** @param File[] $protected */
	private function protect(array $protected): void {
		$this->watermarkService->method('isDeliveryCandidate')
			->willReturnCallback(static fn (File $file): bool => in_array($file, $protected, true));
	}

	/**
	 * The bypass this exists for, signed in: `create` on a marked file answered with it.
	 */
	public function testOpeningAProtectedFileIsRefused(): void {
		$file = $this->file();
		$this->userFolders['alice'] = $this->userFolder([self::DOCUMENT_ID => [$file]]);
		$this->protect([$file]);

		$response = $this->middleware()->afterController($this->creating(), 'create', $this->created());

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'This file is watermarked on download, so it cannot be opened here.'], $response->getData());
	}

	public function testOpeningAnUnprotectedFileKeepsTextsAnswer(): void {
		$this->userFolders['alice'] = $this->userFolder([self::DOCUMENT_ID => [$this->file()]]);
		$this->protect([]);
		$original = $this->created();

		$this->assertSame($original, $this->middleware()->afterController($this->creating(), 'create', $original));
	}

	/** A document somebody already opened answers with its saved state instead. */
	public function testTheSavedStateOfAProtectedFileIsRefusedToo(): void {
		$file = $this->file();
		$this->userFolders['alice'] = $this->userFolder([self::DOCUMENT_ID => [$file]]);
		$this->protect([$file]);

		$response = $this->middleware()->afterController($this->creating(), 'create', new JSONResponse([
			'document' => $this->document(),
			'content' => null,
			'documentState' => 'SAVED-STATE',
		]));

		$this->assertSame(403, $response->getStatus());
	}

	/** A `sync` or `save` that finds the file changed underneath answers with it. */
	public function testAConflictCarryingAProtectedFileIsRefused(): void {
		$file = $this->file();
		$this->userFolders['alice'] = $this->userFolder([self::DOCUMENT_ID => [$file]]);
		$this->protect([$file]);
		$conflict = new JSONResponse(['steps' => [], 'outsideChange' => 'CLEAN-ORIGINAL'], 409);

		$middleware = $this->middleware();

		$this->assertSame(403, $middleware->afterController($this->inSession(), 'sync', $conflict)->getStatus());
		$this->assertSame(403, $middleware->afterController($this->inSession(), 'save', $conflict)->getStatus());
	}

	/** A `push` carrying a sync query gets the saved state back. */
	public function testAPushAnsweredWithTheSavedStateIsRefused(): void {
		$file = $this->file();
		$this->userFolders['alice'] = $this->userFolder([self::DOCUMENT_ID => [$file]]);
		$this->protect([$file]);

		$response = $this->middleware()->afterController($this->inSession(), 'push', new JSONResponse([
			'steps' => [],
			'version' => 3,
			'documentState' => 'SAVED-STATE',
		]));

		$this->assertSame(403, $response->getStatus());
	}

	/**
	 * `sync` runs every few seconds while a note is open, and almost none of its answers
	 * carry content. Those cost nothing here.
	 */
	public function testAnAnswerWithoutContentIsNotLookedInto(): void {
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$original = new JSONResponse(['steps' => ['x'], 'sessions' => [], 'document' => $this->document()]);

		$this->assertSame($original, $this->middleware()->afterController($this->inSession(), 'sync', $original));
	}

	/** The session's user, which a `sync` without a signed-in user still has. */
	public function testASessionIsCheckedAsItsOwnUser(): void {
		$file = $this->file();
		$this->userFolders['bob'] = $this->userFolder([self::DOCUMENT_ID => [$file]]);
		$this->protect([$file]);

		$response = $this->middleware(null)->afterController(
			$this->inSession('bob'),
			'sync',
			new JSONResponse(['outsideChange' => 'CLEAN-ORIGINAL'], 409),
		);

		$this->assertSame(403, $response->getStatus());
	}

	/** Text picks one of the reader's nodes by permission; any protected one refuses. */
	public function testOneProtectedNodeAmongTheReadersIsEnough(): void {
		$own = $this->file();
		$throughAShare = $this->file();
		$this->userFolders['alice'] = $this->userFolder([self::DOCUMENT_ID => [$own, $throughAShare]]);
		$this->protect([$throughAShare]);

		$response = $this->middleware()->afterController($this->creating(), 'create', $this->created());

		$this->assertSame(403, $response->getStatus());
	}

	/**
	 * `create` resolves `fileId`, not the `{documentId}` in its URL - so the document is the
	 * one Text answered about.
	 */
	public function testTheDocumentIsTheOneTextAnsweredAbout(): void {
		$this->params = ['documentId' => '99'];
		$file = $this->file();
		$this->userFolders['alice'] = $this->userFolder([
			self::DOCUMENT_ID => [$file],
			99 => [$this->file(99)],
		]);
		$this->protect([$file]);

		$response = $this->middleware()->afterController($this->creating(), 'create', $this->created());

		$this->assertSame(403, $response->getStatus());
	}

	/** Content went out for a document this cannot find: whether it was protected is unknown. */
	public function testAnAnswerThisCannotPlaceIsRefused(): void {
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');

		$response = $this->middleware()->afterController($this->creating(), 'create', $this->created());

		$this->assertSame(404, $response->getStatus());
	}

	public function testACheckThatFailsRefuses(): void {
		$this->userFolders['alice'] = $this->userFolder([self::DOCUMENT_ID => [$this->file()]]);
		$this->watermarkService->method('isDeliveryCandidate')->willThrowException(new \RuntimeException('database'));

		$response = $this->middleware()->afterController($this->creating(), 'create', $this->created());

		$this->assertSame(403, $response->getStatus());
	}

	/** Through a public link: the file inside the shared folder, and an external fetch. */
	public function testAShareTokenIsAPublicLink(): void {
		$this->params = ['token' => 'TOKEN', 'filePath' => '/notes/Vineyard.jpg'];
		$file = $this->file();
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->with(self::DOCUMENT_ID)->willReturn($file);
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($folder);
		$this->shareManager->method('getShareByToken')->with('TOKEN')->willReturn($share);
		$this->protect([$file]);

		$response = $this->middleware(null)->afterController(
			$this->creating(PublicSessionController::class),
			'create',
			$this->created(),
		);

		$this->assertSame(403, $response->getStatus());
		$this->assertTrue($this->shareAccess->isExternalShareAccess());
	}

	public function testASharedFileIsItsOwnDocument(): void {
		$this->params = ['token' => 'TOKEN'];
		$file = $this->file();
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($file);
		$this->shareManager->method('getShareByToken')->willReturn($share);
		$this->protect([]);
		$original = new JSONResponse(['outsideChange' => 'NEW-TEXT'], 409);

		$response = $this->middleware()->afterController(
			$this->inSession('alice', PublicSessionController::class),
			'sync',
			$original,
		);

		$this->assertSame($original, $response);
	}

	/** A token for some other file is not this document. */
	public function testAShareOfAnotherFileIsRefused(): void {
		$this->params = ['token' => 'TOKEN'];
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($this->file(99));
		$this->shareManager->method('getShareByToken')->willReturn($share);

		$response = $this->middleware()->afterController(
			$this->creating(PublicSessionController::class),
			'create',
			$this->created(),
		);

		$this->assertSame(404, $response->getStatus());
	}

	/** Text's DataResponse, should a middleware ever see it before core has built it. */
	public function testAnUnbuiltDataResponseIsReadToo(): void {
		$file = $this->file();
		$this->userFolders['alice'] = $this->userFolder([self::DOCUMENT_ID => [$file]]);
		$this->protect([$file]);

		$response = $this->middleware()->afterController($this->creating(), 'create', new DataResponse([
			'document' => $this->document(),
			'content' => 'CLEAN-ORIGINAL',
		]));

		$this->assertSame(403, $response->getStatus());
	}

	public function testOtherControllersAndResponsesAreLeftAlone(): void {
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');
		$middleware = $this->middleware();
		$created = $this->created();
		$plain = new Response();

		$this->assertSame($created, $middleware->afterController($this->createMock(AttachmentController::class), 'create', $created));
		$this->assertSame($plain, $middleware->afterController($this->inSession(), 'sync', $plain));
	}
}
