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

class ApiControllerRemoveWatermarkTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new ApiController(
			'files_watermark',
			$this->createMock(IRequest::class),
			$this->createMock(WatermarkConfigMapper::class),
			$this->createMock(WatermarkLogMapper::class),
			$this->watermarkService,
			$this->rootFolder,
			$this->userSession,
			$this->createMock(IGroupManager::class),
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

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->controller->removeWatermark('doc.pdf')->getStatus(),
		);
	}

	public function testReturnsNotFoundWhenFileMissing(): void {
		$this->loginAlice();

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->removeWatermark('missing.pdf')->getStatus(),
		);
	}

	public function testReturnsForbiddenWhenNotUpdateable(): void {
		// Restoring rewrites the file, so read-only access must not be able to trigger it.
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: false);

		$this->watermarkService->expects($this->never())->method('removeWatermark');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->removeWatermark('doc.pdf')->getStatus(),
		);
	}

	public function testReturnsForbiddenWhenNotReadable(): void {
		$this->loginAlice();
		$this->mockFile(readable: false, updateable: true);

		$this->watermarkService->expects($this->never())->method('removeWatermark');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->removeWatermark('doc.pdf')->getStatus(),
		);
	}

	public function testRestoresOriginalWhenPreserved(): void {
		$this->loginAlice();
		$node = $this->mockFile(readable: true, updateable: true);

		$this->watermarkService->expects($this->once())
			->method('removeWatermark')
			->with($node)
			->willReturn(true);

		$response = $this->controller->removeWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'removed', 'path' => 'doc.pdf'], $response->getData());
	}

	public function testReturnsUnprocessableWhenNoOriginalPreserved(): void {
		// A file watermarked before backups existed has nothing to restore — an error
		// the UI shows verbatim, not a silent success.
		$this->loginAlice();
		$node = $this->mockFile(readable: true, updateable: true);

		$this->watermarkService->expects($this->once())
			->method('removeWatermark')
			->with($node)
			->willReturn(false);

		$response = $this->controller->removeWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testReturnsUnprocessableWhenRestoreThrows(): void {
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: true);

		$this->watermarkService->method('removeWatermark')
			->willThrowException(new \RuntimeException('storage full'));

		$response = $this->controller->removeWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'storage full'], $response->getData());
	}
}
