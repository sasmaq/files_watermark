<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\PdfFlattener;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\FilesWatermark\Tests\Unit\InstanceTimeZoneMock;
use OCA\FilesWatermark\Tests\Unit\L10nMock;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiControllerWatermarkedStatusTest extends TestCase {

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
			$this->createMock(PdfFlattener::class),
		);
	}

	private function loginAlice(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testReturnsUnauthorizedWhenNotLoggedIn(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->getWatermarkedStatus('1,2');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testReturnsEmptyWhenNoIdsGiven(): void {
		$this->loginAlice();
		$this->watermarkService->expects($this->never())->method('markedFileIds');

		$response = $this->controller->getWatermarkedStatus('');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['watermarked' => [], 'locked' => []], $response->getData());
	}

	public function testReturnsEmptyWhenIdsAreAllInvalid(): void {
		$this->loginAlice();
		$this->watermarkService->expects($this->never())->method('markedFileIds');

		$response = $this->controller->getWatermarkedStatus('0,-3,abc');

		$this->assertSame(['watermarked' => [], 'locked' => []], $response->getData());
	}

	public function testReturnsWatermarkedIdsScopedToAccessibleFiles(): void {
		$this->loginAlice();

		// Alice can reach 1 and 3, but not 2 (another user's file id).
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			fn (int $id) => in_array($id, [1, 3], true) ? [$this->createMock(\OCP\Files\File::class)] : [],
		);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);

		// Only the accessible ids reach the mapper; id 2 is never queried.
		$this->watermarkService->expects($this->once())
			->method('markedFileIds')
			->with([1, 3])
			->willReturn([3]);

		$response = $this->controller->getWatermarkedStatus('1,2,3');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['watermarked' => [3], 'locked' => []], $response->getData());
	}

	/**
	 * The locked list rides along, scoped the same way.
	 *
	 * It is what the `watermark-locked` DAV property carries, for a listing that arrived
	 * without the properties at all. Reporting "watermarked" without it would put the
	 * Remove button back on exactly the files it is meant to be hidden from - and the
	 * scoping matters for the same reason the first list's does: an id the caller cannot
	 * reach must not be answered about.
	 */
	public function testReportsWhichOfThoseTheUserMayNotUnmark(): void {
		$this->loginAlice();

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$this->createMock(\OCP\Files\File::class)]);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$this->watermarkService->method('markedFileIds')->willReturn([4, 5]);
		$this->watermarkService->expects($this->once())
			->method('lockedFileIds')
			->with([4, 5], 'alice')
			->willReturn([5]);

		$response = $this->controller->getWatermarkedStatus('4,5');

		$this->assertSame(['watermarked' => [4, 5], 'locked' => [5]], $response->getData());
	}

	/**
	 * A trashed file is one the user can see, and the scope test has to agree.
	 *
	 * **This is what made the badge missing in the trash.** `getUserFolder()->getById()`
	 * resolves inside `/{uid}/files`, and a deleted file lives at
	 * `/{uid}/files_trashbin/files` - so every id from a trash listing was dropped as
	 * inaccessible and the endpoint answered "none of these are watermarked" about the
	 * user's own deleted files. The trash is also the one listing where this endpoint is
	 * not a fallback but the only source, because `files_trashbin` freezes its PROPFIND
	 * body before this app can register a property into it.
	 */
	public function testATrashedFileIsInScope(): void {
		$this->loginAlice();

		$files = $this->createMock(Folder::class);
		// Not under /alice/files any more - it has been deleted.
		$files->method('getById')->willReturn([]);

		$trash = $this->createMock(Folder::class);
		$trash->method('getById')->with(9)->willReturn([$this->createMock(\OCP\Files\File::class)]);

		$home = $this->createMock(Folder::class);
		$home->method('get')->with('files_trashbin/files')->willReturn($trash);
		$files->method('getParent')->willReturn($home);

		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($files);

		$this->watermarkService->expects($this->once())
			->method('markedFileIds')
			->with([9])
			->willReturn([9]);

		$response = $this->controller->getWatermarkedStatus('9');

		$this->assertSame(['watermarked' => [9], 'locked' => []], $response->getData());
	}

	/**
	 * No trashbin to look in - the app disabled, or a user who has never deleted anything -
	 * leaves the ordinary scope test in sole charge rather than throwing.
	 */
	public function testAMissingTrashbinLeavesTheOrdinaryScopeTestInCharge(): void {
		$this->loginAlice();

		$files = $this->createMock(Folder::class);
		$files->method('getById')->willReturn([]);
		$home = $this->createMock(Folder::class);
		$home->method('get')->willThrowException(new \OCP\Files\NotFoundException());
		$files->method('getParent')->willReturn($home);
		$this->rootFolder->method('getUserFolder')->willReturn($files);

		$this->watermarkService->expects($this->never())->method('markedFileIds');

		$this->assertSame(
			['watermarked' => [], 'locked' => []],
			$this->controller->getWatermarkedStatus('9')->getData(),
		);
	}

	public function testReturnsEmptyWhenNoIdsAccessible(): void {
		$this->loginAlice();

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$this->watermarkService->expects($this->never())->method('markedFileIds');

		$response = $this->controller->getWatermarkedStatus('1,2');

		$this->assertSame(['watermarked' => [], 'locked' => []], $response->getData());
	}
}
