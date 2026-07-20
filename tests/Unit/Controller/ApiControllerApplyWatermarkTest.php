<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiControllerApplyWatermarkTest extends TestCase {

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
	private function mockFile(bool $readable, bool $updateable): File {
		$node = $this->createMock(File::class);
		$node->method('getMimeType')->willReturn('application/pdf');
		$node->method('isReadable')->willReturn($readable);
		$node->method('isUpdateable')->willReturn($updateable);

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

		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$response = $this->controller->applyWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testReturnsForbiddenWhenNotUpdateable(): void {
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: false);

		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$response = $this->controller->applyWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testWatermarksWhenReadableAndUpdateable(): void {
		$this->loginAlice();
		$node = $this->mockFile(readable: true, updateable: true);

		$this->watermarkService->expects($this->once())
			->method('watermarkInPlace')
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
			->method('watermarkInPlace')
			->with($node, 'on_demand')
			->willReturn(false);

		$response = $this->controller->applyWatermark('doc.pdf');

		// A benign no-op — 200 with a distinct status the UI can branch on, not an error.
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'already_watermarked', 'path' => 'doc.pdf'], $response->getData());
	}
}
