<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\BackgroundJob\WatermarkOnUploadJob;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NodeWrittenListenerTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private IUserSession&MockObject $userSession;
	private IJobList&MockObject $jobList;
	private LoggerInterface&MockObject $logger;
	private NodeWrittenListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new NodeWrittenListener(
			$this->watermarkService,
			$this->userSession,
			$this->jobList,
			$this->logger,
		);

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

	/**
	 * The listener must never watermark inline: the write that fired this event still
	 * holds a lock on the node, so putContent() from here throws LockedException.
	 */
	public function testQueuesJobWhenTriggerIsOnUpload(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
		$this->watermarkService->method('isAlreadyWatermarked')->willReturn(false);

		$this->watermarkService->expects($this->never())->method('watermarkInPlace');
		$this->jobList->expects($this->once())
			->method('add')
			->with(WatermarkOnUploadJob::class, ['fileId' => 1, 'uid' => 'alice']);

		$this->listener->handle($this->fileEvent());
	}

	public function testDoesNotQueueWhenTriggerIsNotOnUpload(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_demand'));

		$this->jobList->expects($this->never())->method('add');

		$this->listener->handle($this->fileEvent());
	}

	public function testSkipsUnsupportedMimeWithoutResolvingConfig(): void {
		$this->watermarkService->method('isSupported')->willReturn(false);

		$this->watermarkService->expects($this->never())->method('resolveConfig');
		$this->jobList->expects($this->never())->method('add');

		$this->listener->handle($this->fileEvent('text/plain'));
	}

	public function testDoesNotQueueForAnAlreadyWatermarkedFile(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
		$this->watermarkService->method('isAlreadyWatermarked')->willReturn(true);

		$this->jobList->expects($this->never())->method('add');

		$this->listener->handle($this->fileEvent());
	}

	public function testDoesNotQueueWithoutASession(): void {
		$listener = new NodeWrittenListener(
			$this->watermarkService,
			$this->createMock(IUserSession::class), // getUser() returns null
			$this->jobList,
			$this->logger,
		);

		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->jobList->expects($this->never())->method('add');

		$listener->handle($this->fileEvent());
	}

	/**
	 * The job's own putContent() fires another NodeWrittenEvent for the file being
	 * watermarked; suppressFor() keeps that from queueing a second job for it.
	 */
	public function testSuppressedFileDoesNotQueue(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
		$this->watermarkService->method('isAlreadyWatermarked')->willReturn(false);

		$event = $this->fileEvent('application/pdf', 99);

		$this->jobList->expects($this->never())->method('add');

		NodeWrittenListener::suppressFor(99, function () use ($event): void {
			$this->listener->handle($event);
		});
	}

	/**
	 * UploadWatermarkPlugin reads watermarkInPlace()'s "applied" boolean back through
	 * suppressFor() to decide whether to drop the queued job, so the value must pass through.
	 */
	public function testSuppressForReturnsTheCallbackResult(): void {
		$this->assertTrue(NodeWrittenListener::suppressFor(1, fn (): bool => true));
		$this->assertFalse(NodeWrittenListener::suppressFor(1, fn (): bool => false));
	}

	public function testSuppressionIsLiftedAfterTheCallback(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
		$this->watermarkService->method('isAlreadyWatermarked')->willReturn(false);

		NodeWrittenListener::suppressFor(99, fn () => null);

		$this->jobList->expects($this->once())->method('add');
		$this->listener->handle($this->fileEvent('application/pdf', 99));
	}

	public function testSuppressionIsLiftedWhenTheCallbackThrows(): void {
		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
		$this->watermarkService->method('isAlreadyWatermarked')->willReturn(false);

		try {
			NodeWrittenListener::suppressFor(99, function (): void {
				throw new \RuntimeException('boom');
			});
		} catch (\RuntimeException) {
			// expected
		}

		$this->jobList->expects($this->once())->method('add');
		$this->listener->handle($this->fileEvent('application/pdf', 99));
	}
}
