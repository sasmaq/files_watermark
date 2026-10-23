<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\EventListener;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\EventListener\TagAssignedListener;
use OCA\FilesWatermark\Service\FolderMarker;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\MapperEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tagging a folder brings everything under it into the upload policy's scope, and it
 * writes nothing, so neither the write nor the move listener hears it. This listener only
 * decides that the tree needs marking; the walk is {@see \OCA\FilesWatermark\Tests\Unit\Service\FolderMarkerTest}'s.
 *
 * @covers \OCA\FilesWatermark\EventListener\TagAssignedListener
 */
class TagAssignedListenerTest extends TestCase {

	private const POLICY_TAG = '3';

	private WatermarkService&MockObject $watermarkService;
	private FolderMarker&MockObject $folderMarker;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private TagAssignedListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->folderMarker = $this->createMock(FolderMarker::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new TagAssignedListener(
			$this->watermarkService,
			$this->folderMarker,
			$this->userSession,
			$this->logger,
		);
	}

	private function policy(string $trigger = WatermarkService::TRIGGER_ON_UPLOAD, ?string $tag = self::POLICY_TAG): void {
		$config = new WatermarkConfig();
		$config->setTrigger($trigger);
		$config->setFolderTag($tag);
		$this->watermarkService->method('resolveConfig')->willReturn($config);
		$this->watermarkService->method('effectiveTrigger')->willReturn($trigger);
	}

	/**
	 * Nextcloud 31's assignment event: one object per event, by name.
	 *
	 * @param list<int|string> $tags
	 */
	private function tagged(array $tags = [3], string $objectId = '7', string $objectType = 'files',
		string $name = MapperEvent::EVENT_ASSIGN): MapperEvent {
		return new MapperEvent($name, $objectType, $objectId, $tags);
	}

	private function loginAlice(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}

	/** **The tagged folder's tree is marked**, by whoever put the tag on. */
	public function testTheTaggedFoldersTreeIsMarked(): void {
		$alice = $this->loginAlice();
		$this->policy();
		$folder = $this->createMock(Folder::class);
		$this->folderMarker->method('folderById')->with(7)->willReturn($folder);

		$this->folderMarker->expects($this->once())
			->method('markTreeOrQueue')
			->with($folder, $alice, 'tag');

		$this->listener->handle($this->tagged());
	}

	/**
	 * A bulk assignment is one event per object on Nextcloud 31; each folder is walked.
	 * `occ` has no session, so the marks name nobody.
	 */
	public function testEveryFolderOfABulkAssignmentIsMarkedEvenWithoutASession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->policy();
		$this->folderMarker->method('folderById')->willReturnCallback(
			fn (int $id): Folder => $this->createMock(Folder::class),
		);

		$this->folderMarker->expects($this->exactly(2))
			->method('markTreeOrQueue')
			->with($this->anything(), null, 'tag');

		$this->listener->handle($this->tagged(objectId: '7'));
		$this->listener->handle($this->tagged(objectId: '8'));
	}

	/** Core sends unassignments as the same class; taking the tag off marks nothing. */
	public function testAnUnassignmentIsIgnored(): void {
		$this->watermarkService->expects($this->never())->method('resolveConfig');

		$this->listener->handle($this->tagged(name: MapperEvent::EVENT_UNASSIGN));
	}

	/** The event's tag ids are integers and the stored policy tag is a string. */
	public function testTheTagIdIsComparedAsAString(): void {
		$this->policy();
		$this->folderMarker->method('folderById')->willReturn($this->createMock(Folder::class));

		$this->folderMarker->expects($this->once())->method('markTreeOrQueue');

		$this->listener->handle($this->tagged(['3']));
	}

	public function testAnotherTagMarksNothingAndResolvesNothing(): void {
		$this->policy();
		$this->folderMarker->expects($this->never())->method('folderById');

		$this->listener->handle($this->tagged([4, 5]));
	}

	public function testAPolicyWithNoFolderTagMarksNothing(): void {
		$this->policy(tag: null);
		$this->folderMarker->expects($this->never())->method('folderById');

		$this->listener->handle($this->tagged());
	}

	public function testATagUnderTheOnDemandPolicyMarksNothing(): void {
		$this->policy(WatermarkService::TRIGGER_ON_DEMAND);
		$this->folderMarker->expects($this->never())->method('folderById');

		$this->listener->handle($this->tagged());
	}

	/** System tags can be put on things other than files; none of them has a scope here. */
	public function testATagOnSomethingOtherThanAFileIsIgnored(): void {
		$this->watermarkService->expects($this->never())->method('resolveConfig');

		$this->listener->handle($this->tagged(objectType: 'calendar'));
	}

	/**
	 * Tagging a file - or anything else that does not resolve to a folder - marks nothing:
	 * the setting scopes folders.
	 */
	public function testAnIdThatIsNotAFolderMarksNothing(): void {
		$this->policy();
		$this->folderMarker->method('folderById')->willReturn(null);

		$this->folderMarker->expects($this->never())->method('markTreeOrQueue');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle($this->tagged());
	}

	/**
	 * Never thrown into the tag request: the assignment has been saved either way. And one
	 * folder that fails does not cost the next one its walk.
	 */
	public function testAFailureIsLoggedAndTheNextFolderIsStillMarked(): void {
		$this->policy();
		$this->folderMarker->method('folderById')->willReturnCallback(function (int $id): Folder {
			if ($id === 7) {
				throw new \RuntimeException('db down');
			}
			return $this->createMock(Folder::class);
		});

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('tagged folder'), $this->anything());
		$this->folderMarker->expects($this->once())->method('markTreeOrQueue');

		$this->listener->handle($this->tagged(objectId: '7'));
		$this->listener->handle($this->tagged(objectId: '8'));
	}

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->watermarkService->expects($this->never())->method('resolveConfig');

		$this->listener->handle(new \OCP\EventDispatcher\Event());
	}
}
