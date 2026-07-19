<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\EventListener\BeforePreviewFetchedListener;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\NotFoundException;
use OCP\Files\Node;
use OCP\IUser;
use OCP\Preview\BeforePreviewFetchedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BeforePreviewFetchedListenerTest extends TestCase {

    private WatermarkService&MockObject   $watermarkService;
    private BeforePreviewFetchedListener  $listener;

    protected function setUp(): void {
        parent::setUp();
        $this->watermarkService = $this->createMock(WatermarkService::class);
        $this->listener         = new BeforePreviewFetchedListener($this->watermarkService);
    }

    private function owner(string $uid = 'alice'): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    private function node(string $mime = 'application/pdf', ?string $ownerUid = 'alice'): Node&MockObject {
        $node = $this->createMock(Node::class);
        $node->method('getMimetype')->willReturn($mime);
        $node->method('getOwner')->willReturn($ownerUid === null ? null : $this->owner($ownerUid));
        return $node;
    }

    private function event(Node $node): BeforePreviewFetchedEvent {
        return new BeforePreviewFetchedEvent($node, 256, 256, false);
    }

    private function config(string $trigger): WatermarkConfig {
        $config = new WatermarkConfig();
        $config->setTrigger($trigger);
        return $config;
    }

    public function testBlocksPreviewForRecipientWhenOnShare(): void {
        $node = $this->node();
        $this->watermarkService->method('isSupported')->willReturn(true);
        // Received-share mount → recipient access.
        $this->watermarkService->method('isReceivedShare')->with($node)->willReturn(true);
        $this->watermarkService->method('resolveConfig')->with('alice')->willReturn($this->config('on_share'));

        $this->expectException(NotFoundException::class);
        $this->listener->handle($this->event($node));
    }

    public function testAllowsPreviewForOwner(): void {
        $node = $this->node();
        $this->watermarkService->method('isSupported')->willReturn(true);
        // Owner's own file is not on a shared mount.
        $this->watermarkService->method('isReceivedShare')->with($node)->willReturn(false);
        $this->watermarkService->expects($this->never())->method('resolveConfig');

        $this->listener->handle($this->event($node));
        $this->addToAssertionCount(1); // no exception thrown
    }

    public function testAllowsRecipientPreviewWhenNotOnShare(): void {
        $node = $this->node();
        $this->watermarkService->method('isSupported')->willReturn(true);
        $this->watermarkService->method('isReceivedShare')->willReturn(true);
        $this->watermarkService->method('resolveConfig')->with('alice')->willReturn($this->config('on_demand'));

        $this->listener->handle($this->event($node));
        $this->addToAssertionCount(1);
    }

    public function testIgnoresUnsupportedMime(): void {
        $node = $this->node('text/plain');
        $this->watermarkService->method('isSupported')->willReturn(false);
        // Short-circuited before the share/policy checks.
        $this->watermarkService->expects($this->never())->method('isReceivedShare');
        $this->watermarkService->expects($this->never())->method('resolveConfig');

        $this->listener->handle($this->event($node));
        $this->addToAssertionCount(1);
    }
}
