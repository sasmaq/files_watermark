<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Middleware;

use OC\Core\Controller\AvatarController;
use OCA\FilesWatermark\Middleware\AvatarMiddleware;
use OCA\FilesWatermark\Middleware\ProtectedFileException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Theming\Controller\UserThemeController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Core's profile picture from Files reads the whole file and either answers with all of it,
 * re-encoded, as a `data:` URI, or makes it the avatar that `GET /avatar/{userId}/512`
 * serves to anyone - so a marked image chosen as a profile picture came back clean. These
 * pin the refusal of that read, before it is made.
 *
 * @covers \OCA\FilesWatermark\Middleware\AvatarMiddleware
 * @covers \OCA\FilesWatermark\Middleware\ProtectedFileException
 */
class AvatarMiddlewareTest extends TestCase {

	private const PATH = '/Photos/Vineyard.jpg';

	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;

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
		$this->params = ['path' => self::PATH];
	}

	private function middleware(?string $signedIn = 'alice'): AvatarMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default);

		$session = $this->createMock(IUserSession::class);
		if ($signedIn !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($signedIn);
			$session->method('getUser')->willReturn($user);
		}

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new AvatarMiddleware(
			$this->watermarkService,
			$this->rootFolder,
			$session,
			$request,
			$l,
			new NullLogger(),
		);
	}

	/** @param array<string, \OCP\Files\Node> $byPath */
	private function userFolder(array $byPath): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')
			->willReturnCallback(static fn (string $path): \OCP\Files\Node => $byPath[$path] ?? throw new NotFoundException($path));
		return $folder;
	}

	private function controller(): AvatarController&MockObject {
		return $this->createMock(AvatarController::class);
	}

	/** @param File[] $protected */
	private function protecting(array $protected): void {
		$this->watermarkService->method('isDeliveryCandidate')
			->willReturnCallback(static fn ($node): bool => in_array($node, $protected, true));
	}

	/** Runs `beforeController` the way core does, through `afterException` when it throws. */
	private function refusal(AvatarMiddleware $middleware, string $method = 'postAvatar'): ?JSONResponse {
		$controller = $this->controller();
		try {
			$middleware->beforeController($controller, $method);
		} catch (\Exception $e) {
			$response = $middleware->afterException($controller, $method, $e);
			$this->assertInstanceOf(JSONResponse::class, $response);
			return $response;
		}
		return null;
	}

	/** The bypass this exists for: a marked image chosen as the profile picture. */
	public function testAProtectedFileIsNotRead(): void {
		$file = $this->createMock(File::class);
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $file]);
		$this->protecting([$file]);

		$response = $this->refusal($this->middleware());

		$this->assertNotNull($response);
		// The settings page shows `data.message` only from a 2xx answer, as core's own
		// "Please select a file." is given.
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['data' => ['message' => 'This file is watermarked on download, so it cannot be used as a profile picture.']],
			$response->getData(),
		);
	}

	public function testAnUnprotectedFileIsLeftToCore(): void {
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $this->createMock(File::class)]);
		$this->protecting([]);

		$this->assertNull($this->refusal($this->middleware()));
	}

	/**
	 * The file is the one at that path in the signed-in user's own folder - where core reads
	 * it, and where a share mounted for them is seen by the share switches.
	 */
	public function testTheFileIsTheOneCoreWouldRead(): void {
		$bobs = $this->createMock(File::class);
		$alices = $this->createMock(File::class);
		$this->userFolders['bob'] = $this->userFolder([self::PATH => $bobs]);
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $alices]);
		$this->watermarkService->expects($this->once())->method('isDeliveryCandidate')
			->with($this->identicalTo($alices))
			->willReturn(true);

		$this->assertNotNull($this->refusal($this->middleware('alice')));
	}

	/** Core runs the path through `stripslashes()` before it looks it up, so this does too. */
	public function testThePathIsUnescapedAsCoreUnescapesIt(): void {
		$this->params['path'] = '/Photos/O\\\'Brien.jpg';
		$file = $this->createMock(File::class);
		$this->userFolders['alice'] = $this->userFolder(["/Photos/O'Brien.jpg" => $file]);
		$this->protecting([$file]);

		$this->assertNotNull($this->refusal($this->middleware()));
	}

	public function testACheckThatFailsRefuses(): void {
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $this->createMock(File::class)]);
		$this->watermarkService->method('isDeliveryCandidate')
			->willThrowException(new \RuntimeException('database is gone'));

		$response = $this->refusal($this->middleware());

		$this->assertNotNull($response);
		$this->assertArrayHasKey('message', $response->getData()['data']);
	}

	/** Core fails on these itself, before reading anything. */
	public function testAPathThatIsNoFileIsLeftToCore(): void {
		$this->userFolders['alice'] = $this->userFolder(['/Photos' => $this->createMock(Folder::class)]);
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');

		$this->assertNull($this->refusal($this->middleware()), 'missing');

		$this->params['path'] = '/Photos';
		$this->assertNull($this->refusal($this->middleware()), 'a folder');

		$this->params['path'] = self::PATH;
		$this->assertNull($this->refusal($this->middleware(null)), 'nobody signed in');
	}

	/** The cropper's upload comes from the reader's own device: no path, and no file is read. */
	public function testAnUploadIsLeftAlone(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		unset($this->params['path']);

		$this->assertNull($this->refusal($this->middleware()));
	}

	public function testOtherMethodsAndControllersAreLeftAlone(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$middleware = $this->middleware();

		$this->assertNull($this->refusal($middleware, 'getAvatar'));
		$this->assertNull($this->refusal($middleware, 'deleteAvatar'));

		$middleware->beforeController($this->createMock(UserThemeController::class), 'postAvatar');
	}

	/** Every other exception is somebody else's to answer. */
	public function testOtherExceptionsAreRethrown(): void {
		$other = new \RuntimeException('not ours');

		$this->expectExceptionObject($other);
		$this->middleware()->afterException($this->controller(), 'postAvatar', $other);
	}

	public function testTheRefusalIsAProtectedFileException(): void {
		$file = $this->createMock(File::class);
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $file]);
		$this->protecting([$file]);

		$this->expectException(ProtectedFileException::class);
		$this->middleware()->beforeController($this->controller(), 'postAvatar');
	}
}
