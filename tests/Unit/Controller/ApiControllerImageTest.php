<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiControllerImageTest extends TestCase {

	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private WatermarkImageStore&MockObject $imageStore;
	private WatermarkConfigMapper&MockObject $configMapper;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->imageStore = $this->createMock(WatermarkImageStore::class);
		$this->configMapper = $this->createMock(WatermarkConfigMapper::class);

		$this->controller = new ApiController(
			'files_watermark',
			$this->request,
			$this->configMapper,
			$this->createMock(WatermarkLogMapper::class),
			$this->createMock(WatermarkService::class),
			$this->createMock(IRootFolder::class),
			$this->userSession,
			$this->groupManager,
			$this->imageStore,
		);
	}

	private function login(string $uid, bool $isAdmin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);
	}

	public function testUploadRejectsNonAdmins(): void {
		// The image is a server-wide asset, so a regular account must not be able to
		// write one even though it can save its own config.
		$this->login('bob', isAdmin: false);
		$this->imageStore->expects($this->never())->method('store');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->uploadImage()->getStatus(),
		);
	}

	public function testUploadRejectsAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->imageStore->expects($this->never())->method('store');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->uploadImage()->getStatus(),
		);
	}

	public function testUploadReturnsBadRequestWhenNoFileSent(): void {
		$this->login('admin', isAdmin: true);
		$this->request->method('getUploadedFile')->with('image')->willReturn(null);

		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller->uploadImage()->getStatus(),
		);
	}

	public function testUploadReportsPhpLevelSizeRejection(): void {
		// upload_max_filesize / post_max_size reject the file before our own check runs.
		$this->login('admin', isAdmin: true);
		$this->request->method('getUploadedFile')->willReturn([
			'tmp_name' => '',
			'error' => UPLOAD_ERR_INI_SIZE,
		]);
		$this->imageStore->expects($this->never())->method('store');

		$response = $this->controller->uploadImage();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'The image is too large.'], $response->getData());
	}

	public function testUploadStoresAndReturnsReference(): void {
		$this->login('admin', isAdmin: true);
		$this->request->method('getUploadedFile')->willReturn([
			'tmp_name' => '/tmp/php-upload',
			'error' => UPLOAD_ERR_OK,
		]);

		$reference = str_repeat('a', 32) . '.png';
		$this->imageStore->expects($this->once())
			->method('store')
			->with('/tmp/php-upload')
			->willReturn($reference);

		$response = $this->controller->uploadImage();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['imagePath' => $reference], $response->getData());
	}

	public function testUploadSurfacesValidationFailure(): void {
		$this->login('admin', isAdmin: true);
		$this->request->method('getUploadedFile')->willReturn([
			'tmp_name' => '/tmp/php-upload',
			'error' => UPLOAD_ERR_OK,
		]);
		$this->imageStore->method('store')
			->willThrowException(new \RuntimeException('The image must be a PNG or JPEG file.'));

		$response = $this->controller->uploadImage();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'The image must be a PNG or JPEG file.'], $response->getData());
	}

	public function testSaveConfigRejectsAnArbitraryServerPath(): void {
		// The regression this change exists for: a non-admin used to be able to persist
		// any server path here and have the renderers read it.
		$this->login('bob', isAdmin: false);
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->controller->saveConfig(
			type: 'image',
			textTemplate: null,
			imagePath: '/var/www/html/core/img/logo/logo.png',
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Upload an image', $response->getData()['error']);
	}

	public function testSaveConfigAcceptsAnUploadedReference(): void {
		$this->login('admin', isAdmin: true);
		$this->configMapper->method('insert')->willReturnCallback(
			static fn (WatermarkConfig $config): WatermarkConfig => $config,
		);

		$response = $this->controller->saveConfig(
			type: 'image',
			textTemplate: null,
			imagePath: str_repeat('a', 32) . '.png',
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSaveConfigAcceptsNoImage(): void {
		$this->login('admin', isAdmin: true);
		$this->configMapper->method('insert')->willReturnCallback(
			static fn (WatermarkConfig $config): WatermarkConfig => $config,
		);

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller->saveConfig(type: 'text', textTemplate: '{username}', imagePath: '')->getStatus(),
		);
	}
}
