<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\ShareAccess;
use OCP\Files\FileInfo;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The two share questions, and above all the fact that they are two.
 *
 * An internal share is a mount and a public link is not, so one is answered by the storage
 * and the other by the request. Anything that collapses them - "not the owner" as a single
 * test - is what let public-link visitors read clean originals the last time this app tried
 * it, because a public link is served from the *owner's* own storage.
 */
class ShareAccessTest extends TestCase {

	private IUserSession&MockObject $userSession;

	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
	}

	private function shareAccess(): ShareAccess {
		return new ShareAccess($this->userSession);
	}

	private function signedIn(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}

	/** @param bool|\Throwable $shared what the storage answers, or what it throws */
	private function node(bool|\Throwable $shared): FileInfo&MockObject {
		$node = $this->createMock(FileInfo::class);

		if ($shared instanceof \Throwable) {
			$node->method('getStorage')->willThrowException($shared);
			return $node;
		}

		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')
			->with(ISharedStorage::class)
			->willReturn($shared);
		$node->method('getStorage')->willReturn($storage);

		return $node;
	}

	// -----------------------------------------------------------------------
	// Internal
	// -----------------------------------------------------------------------

	public function testAReceivedShareMountIsInternalShareAccess(): void {
		$this->signedIn();

		$this->assertTrue($this->shareAccess()->isInternalShareAccess($this->node(true)));
	}

	public function testTheOwnersOwnStorageIsNot(): void {
		$this->signedIn();

		$this->assertFalse($this->shareAccess()->isInternalShareAccess($this->node(false)));
	}

	/**
	 * A mount that will not resolve is not evidence of a share.
	 *
	 * The download path reports the broken storage; claiming the fetch here would only make
	 * this switch answer for a failure that is not its own.
	 */
	public function testAStorageThatThrowsIsNotInternalShareAccess(): void {
		$this->signedIn();

		$this->assertFalse(
			$this->shareAccess()->isInternalShareAccess($this->node(new \RuntimeException('no mount'))),
		);
	}

	// -----------------------------------------------------------------------
	// External
	// -----------------------------------------------------------------------

	public function testNoSessionUserIsExternalAccess(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertTrue($this->shareAccess()->isExternalShareAccess());
	}

	public function testASignedInUserIsNotExternalAccessByDefault(): void {
		$this->signedIn();

		$this->assertFalse($this->shareAccess()->isExternalShareAccess());
	}

	/**
	 * The flag is the only thing that can see a signed-in visitor following somebody else's
	 * public link: they have a session, and the node comes off the owner's own storage.
	 */
	public function testThePublicDavServerFlagMakesASignedInRequestExternal(): void {
		$this->signedIn();
		$shareAccess = $this->shareAccess();

		$shareAccess->notePublicRequest();

		$this->assertTrue($shareAccess->isExternalShareAccess());
	}

	// -----------------------------------------------------------------------
	// The two do not overlap
	// -----------------------------------------------------------------------

	/**
	 * External access is never *also* internal, so the two admin switches stay independent:
	 * an instance that watermarks internal shares and leaves public links alone must not
	 * watermark a public link through the internal test.
	 */
	public function testAnExternalRequestIsNeverInternalShareAccess(): void {
		$this->userSession->method('getUser')->willReturn(null);

		// Even on a storage that reports itself shared, which is the shape that would make a
		// naive implementation answer yes to both.
		$this->assertFalse($this->shareAccess()->isInternalShareAccess($this->node(true)));
	}
}
