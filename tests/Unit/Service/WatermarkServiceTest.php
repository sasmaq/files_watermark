<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLog;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\ImageWatermarker;
use OCA\FilesWatermark\Service\OriginalStore;
use OCA\FilesWatermark\Service\PdfWatermarker;
use OCA\FilesWatermark\Service\WatermarkImageStore;
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
    private OriginalStore&MockObject          $originalStore;
    private WatermarkImageStore&MockObject    $imageStore;
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
        $this->originalStore    = $this->createMock(OriginalStore::class);
        $this->imageStore       = $this->createMock(WatermarkImageStore::class);

        $this->service = new WatermarkService(
            $this->configMapper,
            $this->logMapper,
            $this->pdfWatermarker,
            $this->imageWatermarker,
            $this->rootFolder,
            $this->userSession,
            $this->tagObjectMapper,
            $this->logger,
            $this->originalStore,
            $this->imageStore,
        );
    }

    /**
     * A storage mock that reports whether it is a received-share storage, so
     * WatermarkService::isReceivedShare() can distinguish recipient from owner access.
     */
    private function storage(bool $shared): \OCP\Files\Storage\IStorage&MockObject {
        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('instanceOfStorage')->willReturnCallback(
            fn (string $class): bool => $shared && $class === \OCP\Files\Storage\ISharedStorage::class,
        );
        return $storage;
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

    public function testWatermarkFileCleansUpTempFilesWhenRenderFails(): void {
        // A failed render must not leave the plaintext source copy behind: the caller
        // only receives an exception, so it has no path it could clean up itself.
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username}');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getDisplayName')->willReturn('Alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getName')->willReturn('broken.pdf');
        $file->method('getContent')->willReturn('not really a pdf');

        $captured = null;
        $this->pdfWatermarker->method('apply')->willReturnCallback(
            function (string $src) use (&$captured): void {
                $captured = $src;
                throw new \RuntimeException('cannot parse PDF');
            },
        );
        $this->logMapper->expects($this->never())->method('insertLog');

        try {
            $this->service->watermarkFile($file, 'on_demand');
            $this->fail('Expected the render failure to propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNotNull($captured, 'renderer should have been handed a source path');
        $this->assertFileDoesNotExist($captured, 'plaintext source copy leaked');
        $this->assertDirectoryDoesNotExist(dirname($captured), 'temp dir leaked');
    }

    public function testWatermarkFileHandsRendererTheResolvedLogoPath(): void {
        // The config stores an opaque reference; the renderer must receive a real local
        // path, and the temp copy must not survive the render.
        $config = new WatermarkConfig();
        $config->setType('image');
        $config->setImagePath(str_repeat('a', 32) . '.png');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getDisplayName')->willReturn('Alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->configMapper->method('findByUser')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getName')->willReturn('doc.pdf');
        $file->method('getId')->willReturn(21);
        $file->method('getPath')->willReturn('/alice/files/doc.pdf');
        $file->method('getContent')->willReturn('%PDF-fake');

        $logoTmp = sys_get_temp_dir() . '/wm_logo_' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents($logoTmp, 'logo-bytes');
        $this->imageStore->method('localPath')
            ->with(str_repeat('a', 32) . '.png')
            ->willReturn($logoTmp);

        $seen = null;
        $this->pdfWatermarker->method('apply')->willReturnCallback(
            function (string $src, string $dest, WatermarkConfig $c) use (&$seen): void {
                $seen = $c->getImagePath();
                file_put_contents($dest, 'out');
            },
        );

        $tmpPath = $this->service->watermarkFile($file, 'on_demand');

        $this->assertSame($logoTmp, $seen, 'renderer should get the materialised path');
        $this->assertFileDoesNotExist($logoTmp, 'logo temp copy leaked');
        // The stored config keeps its reference — only the render-time copy was rewritten.
        $this->assertSame(str_repeat('a', 32) . '.png', $config->getImagePath());

        @unlink($tmpPath);
        @rmdir(dirname($tmpPath));
    }

    public function testWatermarkFileNeverPassesAnUnresolvableImageToRenderer(): void {
        // A config left over from the free-text-path era: the store refuses it, and the
        // renderer must be told there is no image rather than the raw path.
        $config = new WatermarkConfig();
        $config->setType('combined');
        $config->setTextTemplate('{username}');
        $config->setImagePath('/var/www/html/core/img/logo/logo.png');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getDisplayName')->willReturn('Alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->configMapper->method('findByUser')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getName')->willReturn('doc.pdf');
        $file->method('getId')->willReturn(22);
        $file->method('getPath')->willReturn('/alice/files/doc.pdf');
        $file->method('getContent')->willReturn('%PDF-fake');

        $this->imageStore->method('localPath')->willReturn(null);

        $seen = 'unset';
        $this->pdfWatermarker->method('apply')->willReturnCallback(
            function (string $src, string $dest, WatermarkConfig $c) use (&$seen): void {
                $seen = $c->getImagePath();
                file_put_contents($dest, 'out');
            },
        );

        $tmpPath = $this->service->watermarkFile($file, 'on_demand');

        $this->assertNull($seen, 'legacy server path must not reach the renderer');

        @unlink($tmpPath);
        @rmdir(dirname($tmpPath));
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

    public function testWatermarkForDownloadReturnsNullForUnsupportedType(): void {
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('text/plain');

        // Gated out before any config lookup or rendering.
        $this->configMapper->expects($this->never())->method('findByUser');
        $this->pdfWatermarker->expects($this->never())->method('apply');
        $this->imageWatermarker->expects($this->never())->method('apply');

        $this->assertNull($this->service->watermarkForDownload($file));
    }

    public function testWatermarkForDownloadReturnsNullWhenTriggerIsNotOnDownload(): void {
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTrigger('on_demand');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');

        // Trigger mismatch: the original is served, nothing is rendered or logged.
        $this->pdfWatermarker->expects($this->never())->method('apply');
        $this->imageWatermarker->expects($this->never())->method('apply');
        $this->logMapper->expects($this->never())->method('insertLog');

        $this->assertNull($this->service->watermarkForDownload($file));
    }

    public function testWatermarkForDownloadRendersWatermarkedCopyWhenOnDownload(): void {
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username}');
        $config->setOpacity(80);
        $config->setFontSize(24);
        $config->setColor('#cccccc');
        $config->setRotation(45);
        $config->setTrigger('on_download');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getDisplayName')->willReturn('Alice');
        $user->method('getEMailAddress')->willReturn('alice@example.com');
        $this->userSession->method('getUser')->willReturn($user);
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getName')->willReturn('doc.pdf');
        $file->method('getId')->willReturn(7);
        $file->method('getPath')->willReturn('/alice/files/doc.pdf');
        $file->method('getContent')->willReturn('%PDF-fake');

        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);

        // The clean original is never modified — only a temp copy is rendered ...
        $file->expects($this->never())->method('putContent');
        $this->pdfWatermarker->expects($this->once())->method('apply');
        // ... and every download is audited.
        $this->logMapper->expects($this->once())->method('insertLog');

        $tmpPath = $this->service->watermarkForDownload($file);

        $this->assertNotNull($tmpPath);
        $this->assertStringContainsString('doc.pdf', $tmpPath);

        if (file_exists($tmpPath)) {
            unlink($tmpPath);
            @rmdir(dirname($tmpPath));
        }
    }

    public function testWatermarkForDownloadDegradesToNullOnRenderFailure(): void {
        // on_download applies and the file is a watermark candidate, but the renderer
        // itself fails — the download must degrade to null (and log) rather than throw.
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username}');
        $config->setTrigger('on_download');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getDisplayName')->willReturn('Alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getName')->willReturn('doc.pdf');
        $file->method('getId')->willReturn(3);
        $file->method('getPath')->willReturn('/alice/files/doc.pdf');
        $file->method('getContent')->willReturn('%PDF-fake');
        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);

        // The renderer blows up (e.g. an unparseable PDF).
        $this->pdfWatermarker->method('apply')
            ->willThrowException(new \RuntimeException('Cannot process PDF'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('failed to watermark on delivery'), $this->anything());

        $this->assertNull($this->service->watermarkForDownload($file));
    }

    public function testWatermarkForDownloadExcludedMimeIsNotApplicable(): void {
        // on_download applies but a mime whitelist excludes this file: it is "not
        // applicable" (served untouched, no error logged) — not a watermark failure.
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTrigger('on_download');
        $config->setMimeTypes('application/pdf');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('image/jpeg');

        $this->logger->expects($this->never())->method('error');
        $this->pdfWatermarker->expects($this->never())->method('apply');
        $this->imageWatermarker->expects($this->never())->method('apply');

        $this->assertNull($this->service->watermarkForDownload($file));
        // ... and it is reported as not-applicable, so the interceptor won't deny it.
        $this->assertNull($this->service->deliveryTrigger($file));
    }

    public function testDeliveryTriggerReportsOnShareForRecipientButNotOwner(): void {
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTrigger('on_share');

        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);
        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getOwner')->willReturn($alice);
        // Received-share mount → recipient access, so on_share applies.
        $file->method('getStorage')->willReturn($this->storage(true));

        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bob);
        $this->assertSame('on_share', $this->service->deliveryTrigger($file));
    }

    public function testDeliveryTriggerIsNullForOwnerUnderOnShare(): void {
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTrigger('on_share');

        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($alice);
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getOwner')->willReturn($alice);
        $file->method('getStorage')->willReturn($this->storage(false));

        // Owner reading own file → not applicable, so the interceptor never denies.
        $this->assertNull($this->service->deliveryTrigger($file));
    }

    public function testWatermarkForDownloadWatermarksPublicLinkVisitorWhenOnShare(): void {
        // A public link is served off the *owner's* own storage (public.php/dav resolves
        // the node through the owner's user folder), so the shared-storage test says
        // "owner access". The public interceptor passes $publicContext = true, which is
        // what keeps the anonymous visitor from receiving the clean original.
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username}');
        $config->setOpacity(80);
        $config->setFontSize(24);
        $config->setColor('#cccccc');
        $config->setRotation(45);
        $config->setTrigger('on_share');

        // Anonymous visitor: no session user at all.
        $this->userSession->method('getUser')->willReturn(null);

        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getName')->willReturn('report.pdf');
        $file->method('getId')->willReturn(7);
        $file->method('getPath')->willReturn('/alice/files/report.pdf');
        $file->method('getContent')->willReturn('%PDF-fake');
        $file->method('getOwner')->willReturn($alice);
        // Not a shared mount — the giveaway that the storage test alone is insufficient.
        $file->method('getStorage')->willReturn($this->storage(false));

        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);
        $file->expects($this->never())->method('putContent');
        $this->pdfWatermarker->expects($this->once())->method('apply');
        $this->logMapper->expects($this->once())->method('insertLog');

        $this->assertSame('on_share', $this->service->deliveryTrigger($file, true));

        $tmpPath = $this->service->watermarkForDownload($file, true);

        $this->assertNotNull($tmpPath);
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
            @rmdir(dirname($tmpPath));
        }
    }

    public function testIsShareAccessTreatsAnonymousRequestAsShareAccess(): void {
        // Callers with no context flag to pass (public preview requests) rely on the
        // anonymous signal: no session user can only mean a public-link visitor.
        $this->userSession->method('getUser')->willReturn(null);

        $file = $this->createMock(File::class);
        $file->method('getStorage')->willReturn($this->storage(false));

        $this->assertTrue($this->service->isShareAccess($file));
    }

    public function testIsShareAccessIsFalseForOwnerOnOwnStorage(): void {
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($alice);

        $file = $this->createMock(File::class);
        $file->method('getStorage')->willReturn($this->storage(false));

        $this->assertFalse($this->service->isShareAccess($file));
    }

    public function testWatermarkForDownloadWatermarksSharedAccessWhenOnShare(): void {
        // Owner 'alice' has an on_share policy; recipient 'bob' fetches the shared file.
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username}');
        $config->setOpacity(80);
        $config->setFontSize(24);
        $config->setColor('#cccccc');
        $config->setRotation(45);
        $config->setTrigger('on_share');

        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $bob->method('getDisplayName')->willReturn('Bob');
        $bob->method('getEMailAddress')->willReturn('bob@example.com');
        $this->userSession->method('getUser')->willReturn($bob);

        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        // The owner's policy governs the file, so we resolve alice's config, not bob's.
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getName')->willReturn('report.pdf');
        $file->method('getId')->willReturn(5);
        $file->method('getPath')->willReturn('/alice/files/report.pdf');
        $file->method('getContent')->willReturn('%PDF-fake');
        $file->method('getOwner')->willReturn($alice);
        $file->method('getStorage')->willReturn($this->storage(true));

        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);
        $file->expects($this->never())->method('putContent');
        $this->pdfWatermarker->expects($this->once())->method('apply');
        $this->logMapper->expects($this->once())->method('insertLog');

        $tmpPath = $this->service->watermarkForDownload($file);

        $this->assertNotNull($tmpPath);
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
            @rmdir(dirname($tmpPath));
        }
    }

    public function testWatermarkForDownloadWatermarksImageForSharedRecipient(): void {
        // Images go through the same on_share delivery gate, but render via the image
        // watermarker (GD/Imagick) — which, unlike the PDF path, doesn't fail on
        // real-world files, so a recipient reliably gets a watermarked image.
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username}');
        $config->setOpacity(80);
        $config->setFontSize(24);
        $config->setColor('#cccccc');
        $config->setRotation(45);
        $config->setTrigger('on_share');

        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $bob->method('getDisplayName')->willReturn('Bob');
        $this->userSession->method('getUser')->willReturn($bob);

        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getName')->willReturn('photo.png');
        $file->method('getId')->willReturn(8);
        $file->method('getPath')->willReturn('/alice/files/photo.png');
        $file->method('getContent')->willReturn('img-bytes');
        $file->method('getOwner')->willReturn($alice);
        $file->method('getStorage')->willReturn($this->storage(true));

        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);
        // Image path, never the PDF path.
        $this->imageWatermarker->expects($this->once())->method('apply');
        $this->pdfWatermarker->expects($this->never())->method('apply');
        $this->logMapper->expects($this->once())->method('insertLog');

        $tmpPath = $this->service->watermarkForDownload($file);

        $this->assertNotNull($tmpPath);
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
            @rmdir(dirname($tmpPath));
        }
    }

    public function testWatermarkForDownloadSkipsOwnerAccessWhenOnShare(): void {
        // on_share must NOT watermark when the owner reads their own file — only
        // when a non-owner (share recipient / public visitor) fetches it.
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTrigger('on_share');

        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($alice);
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getOwner')->willReturn($alice);
        // Owner's own copy lives on a normal (non-shared) storage.
        $file->method('getStorage')->willReturn($this->storage(false));

        $this->pdfWatermarker->expects($this->never())->method('apply');
        $this->imageWatermarker->expects($this->never())->method('apply');
        $this->logMapper->expects($this->never())->method('insertLog');

        $this->assertNull($this->service->watermarkForDownload($file));
    }

    public function testWatermarkForDownloadSkipsSharedAccessWhenOnDemand(): void {
        // A shared file whose owner policy is on_demand is not watermarked on delivery.
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTrigger('on_demand');

        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($bob);

        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $this->configMapper->method('findByUser')->with('alice')->willReturn([$config]);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getOwner')->willReturn($alice);

        $this->pdfWatermarker->expects($this->never())->method('apply');
        $this->logMapper->expects($this->never())->method('insertLog');

        $this->assertNull($this->service->watermarkForDownload($file));
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

    public function testWatermarkInPlacePreservesOriginalBeforeOverwriting(): void {
        // The burn is irreversible, so the pre-watermark bytes must be handed to the
        // store — and it has to happen while getContent() still returns the clean file.
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
        $this->logMapper->method('findWatermarkedFileIds')->willReturn([]);
        $this->imageWatermarker->method('apply')
            ->willReturnCallback(function (string $src, string $dest): void {
                file_put_contents($dest, 'watermarked-bytes');
            });

        $order = [];
        $this->originalStore->expects($this->once())
            ->method('store')
            ->with(11, 'original-bytes')
            ->willReturnCallback(function () use (&$order): bool {
                $order[] = 'store';
                return true;
            });
        $file->expects($this->once())
            ->method('putContent')
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'putContent';
            });

        $this->assertTrue($this->service->watermarkInPlace($file, 'on_demand', $config));
        $this->assertSame(['store', 'putContent'], $order, 'original must be preserved before the overwrite');
    }

    /**
     * @return array{0: File&MockObject, 1: WatermarkConfig}
     */
    private function inPlaceFixture(): array {
        $config = new WatermarkConfig();
        $config->setType('image');
        $config->setTextTemplate('{username}');
        $config->setTrigger('on_upload');

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
        $this->logMapper->method('findWatermarkedFileIds')->willReturn([]);

        return [$file, $config];
    }

    public function testWatermarkInPlaceDoesNotLogWhenTheWriteFails(): void {
        // A phantom audit row is worse than a missing one: isAlreadyWatermarked() reads
        // this log, so a row for content that was never written makes every retry skip
        // the file for good.
        [$file, $config] = $this->inPlaceFixture();

        $rendered = null;
        $this->imageWatermarker->method('apply')
            ->willReturnCallback(function (string $src, string $dest) use (&$rendered): void {
                file_put_contents($dest, 'watermarked-bytes');
                $rendered = $dest;
            });

        $file->method('putContent')->willThrowException(new \RuntimeException('is locked'));

        $this->logMapper->expects($this->never())->method('insertLog');

        $this->expectException(\RuntimeException::class);
        try {
            $this->service->watermarkInPlace($file, 'on_upload', $config);
        } finally {
            // ...and the plaintext render must not be left behind in the temp dir.
            $this->assertNotNull($rendered);
            $this->assertFileDoesNotExist($rendered);
            $this->assertDirectoryDoesNotExist(dirname($rendered));
        }
    }

    public function testWatermarkInPlaceLogsAfterTheWriteLands(): void {
        [$file, $config] = $this->inPlaceFixture();

        $this->imageWatermarker->method('apply')
            ->willReturnCallback(function (string $src, string $dest): void {
                file_put_contents($dest, 'watermarked-bytes');
            });

        $order = [];
        $file->method('putContent')->willReturnCallback(function () use (&$order): void {
            $order[] = 'putContent';
        });
        $this->logMapper->expects($this->once())
            ->method('insertLog')
            ->with('alice', 11, '/alice/files/photo.png', 'on_upload', null)
            ->willReturnCallback(function () use (&$order) {
                $order[] = 'insertLog';
                return new WatermarkLog();
            });

        $this->assertTrue($this->service->watermarkInPlace($file, 'on_upload', $config));
        $this->assertSame(['putContent', 'insertLog'], $order, 'the audit row must follow the write');
    }

    public function testWatermarkInPlaceAttributesToAnExplicitActorWithoutASession(): void {
        // How the background job runs: no session, so the uploader is passed in. Without
        // it the watermark would read "Unknown" and the audit row "system".
        $config = new WatermarkConfig();
        $config->setType('text');
        $config->setTextTemplate('{username}');
        $config->setTrigger('on_upload');

        $this->userSession->method('getUser')->willReturn(null);
        $this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([]);

        $actor = $this->createMock(IUser::class);
        $actor->method('getUID')->willReturn('bob');
        $actor->method('getDisplayName')->willReturn('Bob');
        $actor->method('getEMailAddress')->willReturn('bob@example.com');

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getName')->willReturn('photo.png');
        $file->method('getId')->willReturn(11);
        $file->method('getPath')->willReturn('/bob/files/photo.png');
        $file->method('getContent')->willReturn('original-bytes');
        $this->logMapper->method('findWatermarkedFileIds')->willReturn([]);

        $placeholders = null;
        $this->imageWatermarker->method('apply')
            ->willReturnCallback(function (string $src, string $dest, $cfg, array $ph) use (&$placeholders): void {
                file_put_contents($dest, 'watermarked-bytes');
                $placeholders = $ph;
            });

        $this->logMapper->expects($this->once())
            ->method('insertLog')
            ->with('bob', 11, '/bob/files/photo.png', 'on_upload', null);

        $this->assertTrue($this->service->watermarkInPlace($file, 'on_upload', $config, $actor));
        $this->assertSame('Bob', $placeholders['username']);
        $this->assertSame('bob@example.com', $placeholders['email']);
    }

    public function testRemoveWatermarkRestoresPreservedOriginal(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(11);
        $file->method('getPath')->willReturn('/alice/files/photo.png');

        $this->originalStore->method('read')->with(11)->willReturn('original-bytes');
        $file->expects($this->once())->method('putContent')->with('original-bytes');
        // Discarded only after the restore lands, and recorded so the file stops
        // counting as watermarked.
        $this->originalStore->expects($this->once())->method('discard')->with(11);
        $this->logMapper->expects($this->once())
            ->method('insertLog')
            ->with('alice', 11, '/alice/files/photo.png', 'removed', null);

        $this->assertTrue($this->service->removeWatermark($file));
    }

    public function testRemoveWatermarkReportsWhenNoOriginalPreserved(): void {
        // A file watermarked before backups existed: nothing to restore, and the file
        // must be left exactly as it is rather than half-processed.
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(11);
        $file->method('getPath')->willReturn('/alice/files/photo.png');

        $this->originalStore->method('read')->with(11)->willReturn(null);
        $file->expects($this->never())->method('putContent');
        $this->originalStore->expects($this->never())->method('discard');
        $this->logMapper->expects($this->never())->method('insertLog');

        $this->assertFalse($this->service->removeWatermark($file));
    }

    public function testRemoveWatermarkKeepsBackupWhenRestoreFails(): void {
        // If the write throws, the backup is the only copy of the original left — it
        // must survive so the user can try again.
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(11);
        $file->method('getPath')->willReturn('/alice/files/photo.png');

        $this->originalStore->method('read')->with(11)->willReturn('original-bytes');
        $file->method('putContent')->willThrowException(new \RuntimeException('storage full'));
        $this->originalStore->expects($this->never())->method('discard');
        $this->logMapper->expects($this->never())->method('insertLog');

        $this->expectException(\RuntimeException::class);
        $this->service->removeWatermark($file);
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
