<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Middleware;

use OCA\FilesWatermark\Middleware\ProtectedFileException;
use OCA\FilesWatermark\Middleware\ThemingBackgroundMiddleware;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Text\Controller\SessionController;
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
 * Theming's custom background is a copy of a file from Files, made with `fopen('r')` and
 * served back by `GET /apps/theming/background` as stored - so a marked image chosen as a
 * background came back clean. These pin the refusal of that copy, before it is made.
 *
 * @covers \OCA\FilesWatermark\Middleware\ThemingBackgroundMiddleware
 * @covers \OCA\FilesWatermark\Middleware\ProtectedFileException
 */
class ThemingBackgroundMiddlewareTest extends TestCase {

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
		$this->params = ['type' => 'custom', 'value' => self::PATH];
	}

	private function middleware(?string $signedIn = 'alice'): ThemingBackgroundMiddleware {
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

		return new ThemingBackgroundMiddleware(
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

	private function controller(): UserThemeController&MockObject {
		return $this->createMock(UserThemeController::class);
	}

	/** @param File[] $protected */
	private function protecting(array $protected): void {
		$this->watermarkService->method('isDeliveryCandidate')
			->willReturnCallback(static fn ($node): bool => in_array($node, $protected, true));
	}

	/** Runs `beforeController` the way core does, through `afterException` when it throws. */
	private function refusal(ThemingBackgroundMiddleware $middleware, string $method = 'setBackground'): ?JSONResponse {
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

	/** The bypass this exists for: a marked image chosen as the background. */
	public function testAProtectedFileIsNotCopied(): void {
		$file = $this->createMock(File::class);
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $file]);
		$this->protecting([$file]);

		$response = $this->refusal($this->middleware());

		$this->assertNotNull($response);
		// Not 403: core's OCSMiddleware would rewrap that into an HTTP 200 OCS envelope.
		$this->assertSame(400, $response->getStatus());
		// The field Theming's own failures carry.
		$this->assertSame(
			['error' => 'This file is watermarked on download, so it cannot be used as a background.'],
			$response->getData(),
		);
	}

	public function testAnUnprotectedFileIsLeftToTheming(): void {
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $this->createMock(File::class)]);
		$this->protecting([]);

		$this->assertNull($this->refusal($this->middleware()));
	}

	/**
	 * The file is the one at that path in the signed-in user's own folder - where Theming
	 * opens it, and where a share mounted for them is seen by the share switches.
	 */
	public function testTheFileIsTheOneThemingWouldOpen(): void {
		$bobs = $this->createMock(File::class);
		$alices = $this->createMock(File::class);
		$this->userFolders['bob'] = $this->userFolder([self::PATH => $bobs]);
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $alices]);
		$this->watermarkService->expects($this->once())->method('isDeliveryCandidate')
			->with($this->identicalTo($alices))
			->willReturn(true);

		$this->assertNotNull($this->refusal($this->middleware('alice')));
	}

	/**
	 * Theming copies before it checks for an image, so a PDF overwrote an existing custom
	 * background and was served back. The type is the delivery check's to judge, not this.
	 */
	public function testAProtectedFileThatIsNoImageIsRefusedToo(): void {
		$this->params['value'] = '/Documents/report.pdf';
		$pdf = $this->createMock(File::class);
		$pdf->method('getMimeType')->willReturn('application/pdf');
		$this->userFolders['alice'] = $this->userFolder(['/Documents/report.pdf' => $pdf]);
		$this->protecting([$pdf]);

		$this->assertNotNull($this->refusal($this->middleware()));
	}

	public function testACheckThatFailsRefuses(): void {
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $this->createMock(File::class)]);
		$this->watermarkService->method('isDeliveryCandidate')
			->willThrowException(new \RuntimeException('database is gone'));

		$response = $this->refusal($this->middleware());

		$this->assertNotNull($response);
		$this->assertSame(400, $response->getStatus());
	}

	/** Theming fails on these itself, before copying anything. */
	public function testAPathThatIsNoFileIsLeftToTheming(): void {
		$this->userFolders['alice'] = $this->userFolder(['/Photos' => $this->createMock(Folder::class)]);
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');

		$this->assertNull($this->refusal($this->middleware()), 'missing');

		$this->params['value'] = '/Photos';
		$this->assertNull($this->refusal($this->middleware()), 'a folder');

		$this->params['value'] = '';
		$this->assertNull($this->refusal($this->middleware()), 'empty');

		$this->params['value'] = self::PATH;
		$this->assertNull($this->refusal($this->middleware(null)), 'nobody signed in');
	}

	/** A shipped background, a colour, the default: `value` is no path, and no file is read. */
	public function testOtherBackgroundTypesAreLeftAlone(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		foreach (['shipped', 'color', 'default', 'Custom'] as $type) {
			$this->params['type'] = $type;
			$this->assertNull($this->refusal($this->middleware()), $type);
		}
	}

	public function testOtherMethodsAndControllersAreLeftAlone(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$middleware = $this->middleware();

		$this->assertNull($this->refusal($middleware, 'getBackground'));
		$this->assertNull($this->refusal($middleware, 'deleteBackground'));

		$middleware->beforeController($this->createMock(SessionController::class), 'setBackground');
	}

	/** Every other exception is somebody else's to answer. */
	public function testOtherExceptionsAreRethrown(): void {
		$other = new \RuntimeException('not ours');

		$this->expectExceptionObject($other);
		$this->middleware()->afterException($this->controller(), 'setBackground', $other);
	}

	public function testTheRefusalIsAProtectedFileException(): void {
		$file = $this->createMock(File::class);
		$this->userFolders['alice'] = $this->userFolder([self::PATH => $file]);
		$this->protecting([$file]);

		$this->expectException(ProtectedFileException::class);
		$this->middleware()->beforeController($this->controller(), 'setBackground');
	}
}
