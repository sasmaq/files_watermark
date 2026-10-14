<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\Db\WatermarkRenditionMapper;
use OCA\FilesWatermark\EventListener\UserDeletedListener;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\FilesWatermark\EventListener\UserDeletedListener
 */
class UserDeletedListenerTest extends TestCase {

	/** The lengths promised to a reader who no longer exists are dropped with the account. */
	public function testADeletedUsersReservationsGoWithThem(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');

		$mapper = $this->createMock(WatermarkRenditionMapper::class);
		$mapper->expects($this->once())->method('deleteByUid')->with('bob');

		(new UserDeletedListener($mapper))->handle(new UserDeletedEvent($user));
	}

	public function testOtherEventsAreIgnored(): void {
		$mapper = $this->createMock(WatermarkRenditionMapper::class);
		$mapper->expects($this->never())->method('deleteByUid');

		(new UserDeletedListener($mapper))->handle(new Event());
	}
}
