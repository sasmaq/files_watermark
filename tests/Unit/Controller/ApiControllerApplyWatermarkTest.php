<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\FileTooLargeException;
use OCA\FilesWatermark\Service\ImageTooLargeException;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\FilesWatermark\Tests\Unit\InstanceTimeZoneMock;
use OCA\FilesWatermark\Tests\Unit\L10nMock;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiControllerApplyWatermarkTest extends TestCase {

	use InstanceTimeZoneMock;
	use L10nMock;

	private WatermarkConfigMapper&MockObject $configMapper;
	private WatermarkLogMapper&MockObject $logMapper;
	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->configMapper = $this->createMock(WatermarkConfigMapper::class);
		$this->logMapper = $this->createMock(WatermarkLogMapper::class);
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		// The shipped default unless a test says otherwise. Left unstubbed a mock answers
		// 0, which every file exceeds - the whole suite would then be testing the 413.
		$this->controller = new ApiController(
			'files_watermark',
			$this->createMock(IRequest::class),
			$this->configMapper,
			$this->logMapper,
			$this->watermarkService,
			$this->rootFolder,
			$this->userSession,
			$this->groupManager,
			$this->createMock(WatermarkImageStore::class),
			$this->createMock(ISystemTagManager::class),
			$this->l10n(),
			$this->timeZone(),
		);
	}

	private function loginAlice(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * @return File&MockObject
	 */
	private function mockFile(bool $readable, bool $updateable, int $size = 1024): File {
		$node = $this->createMock(File::class);
		$node->method('getMimeType')->willReturn('application/pdf');
		$node->method('isReadable')->willReturn($readable);
		$node->method('isUpdateable')->willReturn($updateable);
		$node->method('getSize')->willReturn($size);

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($node);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);

		return $node;
	}

	public function testReturnsUnauthorizedWhenNotLoggedIn(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->applyWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testReturnsNotFoundWhenFileMissing(): void {
		$this->loginAlice();

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$response = $this->controller->applyWatermark('missing.pdf');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testReturnsForbiddenWhenNotReadable(): void {
		$this->loginAlice();
		$this->mockFile(readable: false, updateable: true);

		$this->watermarkService->expects($this->never())->method('mark');

		$response = $this->controller->applyWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testReturnsForbiddenWhenNotUpdateable(): void {
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: false);

		$this->watermarkService->expects($this->never())->method('mark');

		$response = $this->controller->applyWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testWatermarksWhenReadableAndUpdateable(): void {
		$this->loginAlice();
		$node = $this->mockFile(readable: true, updateable: true);

		$this->watermarkService->expects($this->once())
			->method('mark')
			->with($node, 'on_demand')
			->willReturn(true);

		$response = $this->controller->applyWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'watermarked', 'path' => 'doc.pdf'], $response->getData());
	}

	public function testReturnsAlreadyWatermarkedWhenServiceSkips(): void {
		$this->loginAlice();
		$node = $this->mockFile(readable: true, updateable: true);

		// The service reports the file was already watermarked (skipped).
		$this->watermarkService->expects($this->once())
			->method('mark')
			->with($node, 'on_demand')
			->willReturn(false);

		$response = $this->controller->applyWatermark('doc.pdf');

		// A benign no-op - 200 with a distinct status the UI can branch on, not an error.
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'already_watermarked', 'path' => 'doc.pdf'], $response->getData());
	}

	/**
	 * Both ceilings arrive as **413**, and neither is enforced here any more.
	 *
	 * The byte cap used to live in this method, checked against the file cache before the
	 * service was called, because an apply rendered the whole file inside the request. An
	 * apply is a database write now, and the ceilings moved to `mark()` with the reason for
	 * them: a file this app will not render is a file it must not promise a watermark for.
	 * What this endpoint still owes is the status - a refusal on size must not arrive as
	 * the 422 that means "this file is broken".
	 *
	 * `FileTooLargeException` and `ImageTooLargeException` both extend `RuntimeException`,
	 * which the catch below maps to 422, so the order of the two catch blocks is the whole
	 * of this behaviour.
	 *
	 * @dataProvider oversizeProvider
	 */
	public function testEitherCeilingAnswers413(\RuntimeException $refusal, string $expected): void {
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: true);

		$this->watermarkService->method('mark')->willThrowException($refusal);

		$response = $this->controller->applyWatermark('huge.pdf');

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		// The message reaches an end user and has to be actionable: it names the file's
		// size and the ceiling, so an admin knows what to raise.
		$this->assertStringContainsString($expected, $response->getData()['error']);
	}

	/** @return array<string, array{\RuntimeException, string}> */
	public static function oversizeProvider(): array {
		return [
			'too many bytes' => [
				new FileTooLargeException('This file is too large to watermark (210.4 MB; the limit is 67.1 MB).'),
				'210.4 MB',
			],
			// Small on disk, enormous decoded - the byte cap cannot be what refuses this.
			'too many pixels' => [
				new ImageTooLargeException('This image is too large to watermark (400 megapixels; the limit is 40).'),
				'400 megapixels',
			],
		];
	}

	public function testAnOrdinaryRefusalIsStill422(): void {
		// The catch order must not have swallowed the general case: a type the policy
		// excludes is "unprocessable", not "too large".
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: true);

		$this->watermarkService->method('mark')
			->willThrowException(new \RuntimeException('MIME type is not in the configured whitelist.'));

		$this->assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$this->controller->applyWatermark('broken.pdf')->getStatus(),
		);
	}
}
