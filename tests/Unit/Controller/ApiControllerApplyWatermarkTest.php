<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\ApplyLimits;
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

class ApiControllerApplyWatermarkTest extends TestCase {

	use L10nMock;

	private WatermarkConfigMapper&MockObject $configMapper;
	private WatermarkLogMapper&MockObject $logMapper;
	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private ApplyLimits&MockObject $applyLimits;
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
		$this->applyLimits = $this->createMock(ApplyLimits::class);
		$this->applyLimits->method('maxBytes')->willReturn(ApplyLimits::DEFAULT_MAX_BYTES);
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
			$this->applyLimits,
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

		// A benign no-op - 200 with a distinct status the UI can branch on, not an error.
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'already_watermarked', 'path' => 'doc.pdf'], $response->getData());
	}

	/**
	 * The size cap, and the assertion that matters is `never()`.
	 *
	 * An on-demand apply renders synchronously inside the request and holds several times
	 * the file's size in memory while doing it. A cap that let the service start and
	 * failed afterwards would have spent exactly what it exists to save, so the refusal
	 * has to happen before `watermarkInPlace()` is reached at all.
	 */
	public function testAFileOverTheCapIsRefusedBeforeAnyWorkIsDone(): void {
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: true, size: ApplyLimits::DEFAULT_MAX_BYTES + 1);

		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$response = $this->controller->applyWatermark('huge.pdf');

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
	}

	public function testAFileExactlyOnTheCapIsAccepted(): void {
		// The comparison is `>`, not `>=`: a cap of N bytes must accept a file of N bytes,
		// or the number an admin sets is not the number they get.
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: true, size: ApplyLimits::DEFAULT_MAX_BYTES);

		$this->watermarkService->expects($this->once())->method('watermarkInPlace')->willReturn(true);

		$this->assertSame(Http::STATUS_OK, $this->controller->applyWatermark('big.pdf')->getStatus());
	}

	public function testTheConfiguredCapIsWhatApplies(): void {
		// Not the shipped default: an admin who lowers the ceiling must see it take effect,
		// which a test pinned only to DEFAULT_MAX_BYTES would not notice.
		$this->applyLimits = $this->createMock(ApplyLimits::class);
		$this->applyLimits->method('maxBytes')->willReturn(2048);
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
			$this->applyLimits,
			$this->l10n(),
		);

		$this->loginAlice();
		// Well under the shipped default, so only the configured value can refuse it.
		$this->mockFile(readable: true, updateable: true, size: 4096);

		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$this->assertSame(
			Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			$this->controller->applyWatermark('doc.pdf')->getStatus(),
		);
	}

	/**
	 * The 413 has to be actionable: it names the file's size and the ceiling, so the admin
	 * knows what to set `apply_max_bytes` to. Raw byte counts do not read as anything.
	 */
	public function testTheRefusalNamesBothSizes(): void {
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: true, size: 210_400_000);

		$data = $this->controller->applyWatermark('huge.pdf')->getData();

		$this->assertStringContainsString('210.4 MB', $data['error']);
		$this->assertStringContainsString('67.1 MB', $data['error']);
	}
}
