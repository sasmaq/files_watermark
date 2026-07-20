<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\DownloadController;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DownloadControllerTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private DownloadController $controller;
	private ?string $tmpPath = null;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new DownloadController(
			'files_watermark',
			$this->createMock(IRequest::class),
			$this->watermarkService,
			$this->rootFolder,
			$this->userSession,
		);
	}

	protected function tearDown(): void {
		if ($this->tmpPath !== null && file_exists($this->tmpPath)) {
			@unlink($this->tmpPath);
		}
		parent::tearDown();
	}

	private function loginAlice(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testStreamsWatermarkedCopyWithoutModifyingOriginal(): void {
		$this->loginAlice();

		$node = $this->createMock(File::class);
		$node->method('getName')->willReturn('doc.pdf');
		$node->method('getMimeType')->willReturn('application/pdf');
		// The original must never be written to.
		$node->expects($this->never())->method('putContent');

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->with('doc.pdf')->willReturn($node);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);

		$this->tmpPath = tempnam(sys_get_temp_dir(), 'wm_dl_');
		file_put_contents($this->tmpPath, '%PDF-watermarked');

		$this->watermarkService->expects($this->once())
			->method('watermarkFile')
			->with($node, 'on_download')
			->willReturn($this->tmpPath);

		$response = $this->controller->download('doc.pdf');

		$this->assertInstanceOf(StreamResponse::class, $response);
	}

	public function testReturnsUnauthorizedWhenNotLoggedIn(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->download('doc.pdf');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testReturnsNotFoundWhenFileMissing(): void {
		$this->loginAlice();

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$response = $this->controller->download('missing.pdf');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testReturnsUnprocessableOnWatermarkFailure(): void {
		$this->loginAlice();

		$node = $this->createMock(File::class);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($node);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$this->watermarkService->method('watermarkFile')
			->willThrowException(new \RuntimeException('cannot process'));

		$response = $this->controller->download('doc.pdf');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}
}
