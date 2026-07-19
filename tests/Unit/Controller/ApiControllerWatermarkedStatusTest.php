<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiControllerWatermarkedStatusTest extends TestCase {

    private WatermarkConfigMapper&MockObject $configMapper;
    private WatermarkLogMapper&MockObject    $logMapper;
    private WatermarkService&MockObject      $watermarkService;
    private IRootFolder&MockObject           $rootFolder;
    private IUserSession&MockObject          $userSession;
    private IGroupManager&MockObject         $groupManager;
    private ApiController                     $controller;

    protected function setUp(): void {
        parent::setUp();
        $this->configMapper     = $this->createMock(WatermarkConfigMapper::class);
        $this->logMapper        = $this->createMock(WatermarkLogMapper::class);
        $this->watermarkService = $this->createMock(WatermarkService::class);
        $this->rootFolder       = $this->createMock(IRootFolder::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->groupManager     = $this->createMock(IGroupManager::class);
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

    public function testReturnsUnauthorizedWhenNotLoggedIn(): void {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->getWatermarkedStatus('1,2');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testReturnsEmptyWhenNoIdsGiven(): void {
        $this->loginAlice();
        $this->logMapper->expects($this->never())->method('findWatermarkedFileIds');

        $response = $this->controller->getWatermarkedStatus('');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['watermarked' => []], $response->getData());
    }

    public function testReturnsEmptyWhenIdsAreAllInvalid(): void {
        $this->loginAlice();
        $this->logMapper->expects($this->never())->method('findWatermarkedFileIds');

        $response = $this->controller->getWatermarkedStatus('0,-3,abc');

        $this->assertSame(['watermarked' => []], $response->getData());
    }

    public function testReturnsWatermarkedIdsScopedToAccessibleFiles(): void {
        $this->loginAlice();

        // Alice can reach 1 and 3, but not 2 (another user's file id).
        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->willReturnCallback(
            fn(int $id) => in_array($id, [1, 3], true) ? [$this->createMock(\OCP\Files\File::class)] : [],
        );
        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);

        // Only the accessible ids reach the mapper; id 2 is never queried.
        $this->logMapper->expects($this->once())
            ->method('findWatermarkedFileIds')
            ->with([1, 3])
            ->willReturn([3]);

        $response = $this->controller->getWatermarkedStatus('1,2,3');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['watermarked' => [3]], $response->getData());
    }

    public function testReturnsEmptyWhenNoIdsAccessible(): void {
        $this->loginAlice();

        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->willReturn([]);
        $this->rootFolder->method('getUserFolder')->willReturn($folder);

        $this->logMapper->expects($this->never())->method('findWatermarkedFileIds');

        $response = $this->controller->getWatermarkedStatus('1,2');

        $this->assertSame(['watermarked' => []], $response->getData());
    }
}
