<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
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

class ApiControllerRemoveWatermarkTest extends TestCase {

	use L10nMock;

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
			$this->createMock(ISystemTagManager::class),
			$this->l10n(),
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

		$this->watermarkService->expects($this->never())->method('unmark');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->removeWatermark('doc.pdf')->getStatus(),
		);
	}

	public function testReturnsForbiddenWhenNotReadable(): void {
		$this->loginAlice();
		$this->mockFile(readable: false, updateable: true);

		$this->watermarkService->expects($this->never())->method('unmark');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->removeWatermark('doc.pdf')->getStatus(),
		);
	}

	public function testUnmarksTheFile(): void {
		$this->loginAlice();
		$node = $this->mockFile(readable: true, updateable: true);

		$this->watermarkService->expects($this->once())
			->method('unmark')
			->with($node)
			->willReturn(true);

		$response = $this->controller->removeWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'removed', 'path' => 'doc.pdf'], $response->getData());
	}

	/**
	 * A file that was not marked is a no-op, not a failure.
	 *
	 * This used to be a 422: the removal restored a preserved original, and a file with
	 * none had nothing to restore. Nothing is restored now - the stored file was never
	 * changed - so "not marked" is simply the state the caller asked for, and reporting it
	 * as an error would put a red note card in front of a user who got what they wanted.
	 */
	public function testAnUnmarkedFileIsANoOpRatherThanAnError(): void {
		$this->loginAlice();
		$node = $this->mockFile(readable: true, updateable: true);

		$this->watermarkService->expects($this->once())
			->method('unmark')
			->with($node)
			->willReturn(false);

		$response = $this->controller->removeWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'not_watermarked', 'path' => 'doc.pdf'], $response->getData());
	}
}
