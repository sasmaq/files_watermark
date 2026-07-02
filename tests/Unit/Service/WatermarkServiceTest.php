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
use Psr\Log\LoggerInterface;

class WatermarkServiceTest extends TestCase {

    private WatermarkConfigMapper&MockObject  $configMapper;
    private WatermarkLogMapper&MockObject     $logMapper;
    private PdfWatermarker&MockObject         $pdfWatermarker;
    private ImageWatermarker&MockObject       $imageWatermarker;
    private IRootFolder&MockObject            $rootFolder;
    private IUserSession&MockObject           $userSession;
    private ISystemTagObjectMapper&MockObject $tagObjectMapper;
    private LoggerInterface&MockObject         $logger;
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
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->service = new WatermarkService(
            $this->configMapper,
            $this->logMapper,
            $this->pdfWatermarker,
            $this->imageWatermarker,
            $this->rootFolder,
            $this->userSession,
            $this->tagObjectMapper,
            $this->logger,
        );
    }

    public function testIsSupportedMatchesKnownTypes(): void {
        $this->assertTrue($this->service->isSupported('application/pdf'));
        $this->assertTrue($this->service->isSupported('image/jpeg'));
        $this->assertTrue($this->service->isSupported('image/png'));
        $this->assertTrue($this->service->isSupported('image/webp'));
        $this->assertFalse($this->service->isSupported('text/plain'));
        $this->assertFalse($this->service->isSupported('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
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

    public function testWatermarkFileDelegatesPdfToPdfWatermarker(): void {
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
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getName')->willReturn('doc.pdf');
        $file->method('getId')->willReturn(7);
        $file->method('getPath')->willReturn('/alice/files/doc.pdf');
        $file->method('getContent')->willReturn('%PDF-fake');

        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);

        // The PDF must go to the PDF renderer, never the image renderer.
        $this->pdfWatermarker->expects($this->once())->method('apply');
        $this->imageWatermarker->expects($this->never())->method('apply');
        $this->logMapper->expects($this->once())->method('insertLog');

        $tmpPath = $this->service->watermarkFile($file, 'on_demand', $config);
        $this->assertStringContainsString('doc.pdf', $tmpPath);

        if (file_exists($tmpPath)) {
            unlink($tmpPath);
            @rmdir(dirname($tmpPath));
        }
    }

    public function testUnsupportedTypeIsSkippedWithLogEntryAndNoRendering(): void {
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getPath')->willReturn('/alice/files/notes.txt');

        // Audit-log entry is written for the skip ...
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('unsupported'),
                $this->callback(fn(array $ctx): bool => ($ctx['mime'] ?? null) === 'text/plain'),
            );

        // ... and no renderer is invoked.
        $this->pdfWatermarker->expects($this->never())->method('apply');
        $this->imageWatermarker->expects($this->never())->method('apply');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported file type');

        $this->service->watermarkFile($file, 'on_demand');
    }

    public function testWatermarkInPlaceReplacesFileContent(): void {
        $config = new WatermarkConfig();
        $config->setType('image');
        $config->setTextTemplate('{username}');
        $config->setTrigger('on_demand');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getDisplayName')->willReturn('Alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getName')->willReturn('photo.png');
        $file->method('getId')->willReturn(11);
        $file->method('getPath')->willReturn('/alice/files/photo.png');
        $file->method('getContent')->willReturn('original-bytes');

        // Not yet watermarked, so the in-place burn proceeds.
        $this->logMapper->method('findWatermarkedFileIds')->willReturn([]);

        // The renderer writes the watermarked output to the destination temp path.
        $this->imageWatermarker->method('apply')
            ->willReturnCallback(function (string $src, string $dest): void {
                file_put_contents($dest, 'watermarked-bytes');
            });

        // In-place application must push the watermarked bytes back into the file.
        $file->expects($this->once())->method('putContent')->with('watermarked-bytes');

        $this->assertTrue($this->service->watermarkInPlace($file, 'on_demand', $config));
    }

    public function testWatermarkInPlaceSkipsAlreadyWatermarkedFile(): void {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(11);
        $file->method('getPath')->willReturn('/alice/files/photo.png');

        // A prior watermark is on record for this file id.
        $this->logMapper->expects($this->once())
            ->method('findWatermarkedFileIds')
            ->with([11])
            ->willReturn([11]);

        // Nothing is rendered, the content is never rewritten, and no new log row is added.
        $this->imageWatermarker->expects($this->never())->method('apply');
        $this->pdfWatermarker->expects($this->never())->method('apply');
        $file->expects($this->never())->method('putContent');
        $this->logMapper->expects($this->never())->method('insertLog');

        $this->assertFalse($this->service->watermarkInPlace($file, 'on_demand'));
    }

    public function testTextWatermarkPassesAllPlaceholdersToRenderer(): void {
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username} {email} {date} {datetime} {filename}');
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
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getName')->willReturn('report.png');
        $file->method('getId')->willReturn(9);
        $file->method('getPath')->willReturn('/alice/files/report.png');
        $file->method('getContent')->willReturn('fake');

        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);

        $captured = null;
        $this->imageWatermarker->expects($this->once())
            ->method('apply')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $placeholders) use (&$captured): bool {
                    $captured = $placeholders;
                    return true;
                }),
            );

        $tmpPath = $this->service->watermarkFile($file, 'on_demand', $config);

        $this->assertSame('Alice', $captured['username']);
        $this->assertSame('alice@example.com', $captured['email']);
        $this->assertSame(date('Y-m-d'), $captured['date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured['datetime']);
        $this->assertSame('report.png', $captured['filename']);

        if (file_exists($tmpPath)) {
            unlink($tmpPath);
            @rmdir(dirname($tmpPath));
        }
    }
}
