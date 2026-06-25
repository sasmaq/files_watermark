<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\ImageWatermarker;
use OCA\FilesWatermark\Service\PdfWatermarker;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WatermarkServiceTest extends TestCase {

    private WatermarkConfigMapper&MockObject  $configMapper;
    private WatermarkLogMapper&MockObject     $logMapper;
    private PdfWatermarker&MockObject         $pdfWatermarker;
    private ImageWatermarker&MockObject       $imageWatermarker;
    private IRootFolder&MockObject            $rootFolder;
    private IUserSession&MockObject           $userSession;
    private ISystemTagObjectMapper&MockObject $tagObjectMapper;
    private WatermarkService                  $service;

    protected function setUp(): void {
        parent::setUp();

        $this->configMapper     = $this->createMock(WatermarkConfigMapper::class);
        $this->logMapper        = $this->createMock(WatermarkLogMapper::class);
        $this->pdfWatermarker   = $this->createMock(PdfWatermarker::class);
        $this->imageWatermarker = $this->createMock(ImageWatermarker::class);
        $this->rootFolder       = $this->createMock(IRootFolder::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->tagObjectMapper  = $this->createMock(ISystemTagObjectMapper::class);

        $this->service = new WatermarkService(
            $this->configMapper,
            $this->logMapper,
            $this->pdfWatermarker,
            $this->imageWatermarker,
            $this->rootFolder,
            $this->userSession,
            $this->tagObjectMapper,
        );
    }

    public function testResolveConfigReturnsUserConfigWhenPresent(): void {
        $userConfig = new WatermarkConfig();
        $userConfig->setType('text');
        $userConfig->setUserId('alice');

        $this->configMapper->expects($this->once())
            ->method('findByUser')
            ->with('alice')
            ->willReturn([$userConfig]);

        $result = $this->service->resolveConfig('alice');
        $this->assertSame($userConfig, $result);
    }

    public function testResolveConfigFallsBackToGlobal(): void {
        $globalConfig = new WatermarkConfig();
        $globalConfig->setType('image');

        $this->configMapper->expects($this->once())
            ->method('findByUser')
            ->willReturn([]);

        $this->configMapper->expects($this->once())
            ->method('findGlobal')
            ->willReturn($globalConfig);

        $result = $this->service->resolveConfig('bob');
        $this->assertSame($globalConfig, $result);
    }

    public function testResolveConfigReturnsDefaultWhenNoneExist(): void {
        $this->configMapper->method('findByUser')->willReturn([]);
        $this->configMapper->method('findGlobal')->willThrowException(new DoesNotExistException(''));

        $result = $this->service->resolveConfig('carol');
        $this->assertSame('text', $result->getType());
        $this->assertSame(80, $result->getOpacity());
        $this->assertSame(45, $result->getRotation());
    }

    public function testWatermarkFileThrowsForUnsupportedMime(): void {
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('video/mp4');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported file type');

        $this->service->watermarkFile($file, 'on_demand');
    }

    public function testWatermarkFileThrowsWhenMimeNotInWhitelist(): void {
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username}');
        $config->setMimeTypes('application/pdf');

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('image/jpeg');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MIME type');

        $this->service->watermarkFile($file, 'on_demand', $config);
    }

    public function testWatermarkFileDelegatesImageToImageWatermarker(): void {
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username}');
        $config->setOpacity(80);
        $config->setFontSize(24);
        $config->setColor('#cccccc');
        $config->setRotation(45);
        $config->setTrigger('on_demand');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getDisplayName')->willReturn('Alice');
        $user->method('getEMailAddress')->willReturn('alice@example.com');

        $this->userSession->method('getUser')->willReturn($user);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getName')->willReturn('photo.jpg');
        $file->method('getId')->willReturn(42);
        $file->method('getPath')->willReturn('/alice/files/photo.jpg');
        $file->method('getContent')->willReturn('fake-image-data');

        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);

        $this->imageWatermarker->expects($this->once())
            ->method('apply');

        $this->logMapper->expects($this->once())
            ->method('insertLog');

        $tmpPath = $this->service->watermarkFile($file, 'on_demand', $config);
        $this->assertStringContainsString('photo.jpg', $tmpPath);

        // clean up
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
            @rmdir(dirname($tmpPath));
        }
    }
}
