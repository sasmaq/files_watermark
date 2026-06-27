<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NodeWrittenListenerTest extends TestCase {

    private WatermarkService&MockObject $watermarkService;
    private IUserSession&MockObject     $userSession;
    private LoggerInterface&MockObject  $logger;
    private NodeWrittenListener         $listener;

    protected function setUp(): void {
        parent::setUp();
        $this->watermarkService = $this->createMock(WatermarkService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->listener         = new NodeWrittenListener($this->watermarkService, $this->userSession, $this->logger);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function fileEvent(string $mime = 'application/pdf', int $id = 1): NodeWrittenEvent {
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getId')->willReturn($id);
        $file->method('getPath')->willReturn('/alice/files/doc.pdf');
        return new NodeWrittenEvent($file);
    }

    private function config(string $trigger): WatermarkConfig {
        $config = new WatermarkConfig();
        $config->setTrigger($trigger);
        return $config;
    }

    public function testWatermarksWhenTriggerIsOnUpload(): void {
        $this->watermarkService->method('isSupported')->willReturn(true);
        $this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));

        $this->watermarkService->expects($this->once())
            ->method('watermarkInPlace')
            ->with($this->isInstanceOf(File::class), 'on_upload', $this->isInstanceOf(WatermarkConfig::class));

        $this->listener->handle($this->fileEvent());
    }

    public function testDoesNotWatermarkWhenTriggerIsNotOnUpload(): void {
        $this->watermarkService->method('isSupported')->willReturn(true);
        $this->watermarkService->method('resolveConfig')->willReturn($this->config('on_demand'));

        $this->watermarkService->expects($this->never())->method('watermarkInPlace');

        $this->listener->handle($this->fileEvent());
    }

    public function testSkipsUnsupportedMimeWithoutResolvingConfig(): void {
        $this->watermarkService->method('isSupported')->willReturn(false);

        $this->watermarkService->expects($this->never())->method('resolveConfig');
        $this->watermarkService->expects($this->never())->method('watermarkInPlace');

        $this->listener->handle($this->fileEvent('text/plain'));
    }

    public function testReentrancyGuardPreventsInfiniteLoop(): void {
        $event = $this->fileEvent('application/pdf', 99);

        $this->watermarkService->method('isSupported')->willReturn(true);
        $this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));

        // Simulate watermarkInPlace writing the file, which fires another
        // NodeWrittenEvent for the same node. The guard must swallow the nested call.
        $this->watermarkService->expects($this->once())
            ->method('watermarkInPlace')
            ->willReturnCallback(function () use ($event): void {
                $this->listener->handle($event);
            });

        $this->listener->handle($event);
    }

    public function testExceptionIsLoggedAndNotPropagated(): void {
        $this->watermarkService->method('isSupported')->willReturn(true);
        $this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
        $this->watermarkService->method('watermarkInPlace')
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())->method('error');

        // Must not throw.
        $this->listener->handle($this->fileEvent());
    }
}
