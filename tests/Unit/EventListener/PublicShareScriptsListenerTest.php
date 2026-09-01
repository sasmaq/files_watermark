<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\EventListener\PublicShareScriptsListener;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The status the public share page is rendered with.
 *
 * **This state is the badge's primary source on that page, not a nicety.** This app's
 * script is emitted after `files-main.js`, so the DAV property is registered too late for
 * the first listing - and a single-file share never lists anything a second time. Every
 * case here is therefore a case where the badge is either right or absent for good.
 *
 * The event is a stand-in with the one method the listener reaches for. `files_sharing`'s
 * real class cannot be named in this app's code (see the listener), so what is under test
 * is precisely the duck-typing that replaces naming it.
 */
class PublicShareScriptsListenerTest extends TestCase {

	private WatermarkService&MockObject $watermarkService;
	private PublicShareScriptsListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->listener = new PublicShareScriptsListener(
			$this->createMock(IInitialState::class),
			$this->watermarkService,
			new ShareAccess($this->createMock(\OCP\IUserSession::class)),
			$this->createMock(LoggerInterface::class),
		);
	}

	/** An event shaped like `files_sharing`'s, carrying $share. */
	private function event(?IShare $share): Event {
		return new class($share) extends Event {
			public function __construct(
				private ?IShare $share,
			) {
				parent::__construct();
			}

			public function getShare(): ?IShare {
				return $this->share;
			}
		};
	}

	private function share(?object $node): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($node);
		return $share;
	}

	private function file(int $id): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	public function testASingleFileShareReportsItsOwnId(): void {
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(true);

		$ids = $this->listener->watermarkedIds($this->event($this->share($this->file(57))));

		$this->assertSame([57], $ids);
	}

	public function testASingleFileShareThatIsNotWatermarkedReportsNothing(): void {
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(false);

		$ids = $this->listener->watermarkedIds($this->event($this->share($this->file(57))));

		$this->assertSame([], $ids);
	}

	public function testAFolderShareReportsTheChildrenThatWillBeWatermarked(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([
			$this->file(1),
			$this->file(2),
			$this->file(3),
		]);
		$this->watermarkService->method('isDeliveryCandidate')
			->willReturnCallback(static fn (File $file): bool => $file->getId() !== 2);

		$ids = $this->listener->watermarkedIds($this->event($this->share($folder)));

		$this->assertSame([1, 3], $ids);
	}

	/**
	 * A share of ten thousand files must not pay for a full listing plus a status check on
	 * every page load, to answer a question the DAV property answers a moment later. The
	 * first page of rows is what the seed exists for.
	 */
	public function testAHugeFolderIsCappedRatherThanWalkedWhole(): void {
		$children = array_map(fn (int $id): File => $this->file($id), range(1, 500));
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($children);
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(true);

		$ids = $this->listener->watermarkedIds($this->event($this->share($folder)));

		$this->assertCount(200, $ids);
		$this->assertSame(1, $ids[0]);
	}

	/**
	 * The page has to render whatever this resolves to. A share that cannot be read, an
	 * event shape this does not recognise, or a service that throws all fall back to the
	 * DAV property rather than taking the visitor's page down with them.
	 *
	 * @dataProvider degradedProvider
	 */
	public function testTheStateDegradesToEmptyRatherThanThrowing(callable $eventFactory): void {
		$this->watermarkService->method('isDeliveryCandidate')
			->willThrowException(new \RuntimeException('storage is gone'));

		$this->assertSame([], $this->listener->watermarkedIds($eventFactory($this)));
	}

	/** @return array<string, array{callable}> */
	public static function degradedProvider(): array {
		return [
			'an event with no getShare()' => [static fn (self $test): Event => new Event()],
			'a share with no node' => [static fn (self $test): Event => $test->event($test->share(null))],
			'a service that throws' => [static fn (self $test): Event => $test->event($test->share($test->file(9)))],
		];
	}
}
