<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\EventListener\ShareCreatedListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShareCreatedListenerTest extends TestCase {

    private WatermarkService&MockObject $watermarkService;
    private IUserSession&MockObject     $userSession;
    private LoggerInterface&MockObject  $logger;
    private ShareCreatedListener        $listener;
    private string                      $tmpPath;

    protected function setUp(): void {
        parent::setUp();
        $this->watermarkService = $this->createMock(WatermarkService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->listener         = new ShareCreatedListener($this->watermarkService, $this->userSession, $this->logger);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // A real temp file standing in for the watermarked copy.
        $this->tmpPath = tempnam(sys_get_temp_dir(), 'wm_share_');
        file_put_contents($this->tmpPath, 'WATERMARKED');
    }

    protected function tearDown(): void {
        @unlink($this->tmpPath);
        parent::tearDown();
    }

    private function event(File $file): ShareCreatedEvent {
        $share = $this->createMock(IShare::class);
        $share->method('getNode')->willReturn($file);
        return new ShareCreatedEvent($share);
    }

    private function file(Folder $parent, string $name = 'report.pdf', string $mime = 'application/pdf'): File&MockObject {
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/alice/files/' . $name);
        $file->method('getParent')->willReturn($parent);
        return $file;
    }

    private function config(string $trigger): WatermarkConfig {
        $config = new WatermarkConfig();
        $config->setTrigger($trigger);
        return $config;
    }

    public function testCreatesSharedCopyWhenTriggerIsOnShare(): void {
        $parent = $this->createMock(Folder::class);
        $parent->method('nodeExists')->with('report_shared.pdf')->willReturn(false);
        $parent->expects($this->once())
            ->method('newFile')
            ->with('report_shared.pdf', 'WATERMARKED');

        $file = $this->file($parent);

        $this->watermarkService->method('isSupported')->willReturn(true);
        $this->watermarkService->method('resolveConfig')->willReturn($this->config('on_share'));
        $this->watermarkService->expects($this->once())
            ->method('watermarkFile')
            ->with($file, 'on_share', $this->isInstanceOf(WatermarkConfig::class))
            ->willReturn($this->tmpPath);

        $this->listener->handle($this->event($file));
    }

    public function testUpdatesExistingSharedCopy(): void {
        $existing = $this->createMock(File::class);
        $existing->expects($this->once())->method('putContent')->with('WATERMARKED');

        $parent = $this->createMock(Folder::class);
        $parent->method('nodeExists')->with('report_shared.pdf')->willReturn(true);
        $parent->method('get')->with('report_shared.pdf')->willReturn($existing);
        $parent->expects($this->never())->method('newFile');

        $file = $this->file($parent);

        $this->watermarkService->method('isSupported')->willReturn(true);
        $this->watermarkService->method('resolveConfig')->willReturn($this->config('on_share'));
        $this->watermarkService->method('watermarkFile')->willReturn($this->tmpPath);

        $this->listener->handle($this->event($file));
    }

    public function testDoesNotActWhenTriggerIsNotOnShare(): void {
        $parent = $this->createMock(Folder::class);
        $file   = $this->file($parent);

        $this->watermarkService->method('isSupported')->willReturn(true);
        $this->watermarkService->method('resolveConfig')->willReturn($this->config('on_demand'));
        $this->watermarkService->expects($this->never())->method('watermarkFile');

        $this->listener->handle($this->event($file));
    }

    public function testSkipsUnsupportedMime(): void {
        $parent = $this->createMock(Folder::class);
        $file   = $this->file($parent, 'notes.txt', 'text/plain');

        $this->watermarkService->method('isSupported')->willReturn(false);
        $this->watermarkService->expects($this->never())->method('watermarkFile');

        $this->listener->handle($this->event($file));
    }
}
