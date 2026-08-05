<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\FilesWatermark\Tests\Unit\L10nMock;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiControllerRemoveWatermarkTest extends TestCase {

	use L10nMock;

	private WatermarkService&MockObject $watermarkService;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new ApiController(
			'files_watermark',
			$this->createMock(IRequest::class),
			$this->createMock(WatermarkConfigMapper::class),
			$this->createMock(WatermarkLogMapper::class),
			$this->watermarkService,
			$this->rootFolder,
			$this->userSession,
			$this->createMock(IGroupManager::class),
			$this->createMock(WatermarkImageStore::class),
			$this->createMock(ISystemTagManager::class),
			$this->l10n(),
		);
	}

	private function loginAlice(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * @param ?string $ownerUid the file's owner; null for a node whose owner cannot be
	 *                          resolved. Defaults to the logged-in user, so a test that says nothing about
	 *                          ownership is testing an ordinary file of the caller's own
	 * @return File&MockObject
	 */
	private function mockFile(bool $readable, bool $updateable, ?string $ownerUid = 'alice'): File {
		$node = $this->createMock(File::class);
		$node->method('getMimeType')->willReturn('application/pdf');
		$node->method('isReadable')->willReturn($readable);
		$node->method('isUpdateable')->willReturn($updateable);

		if ($ownerUid === null) {
			$node->method('getOwner')->willReturn(null);
		} else {
			$owner = $this->createMock(IUser::class);
			$owner->method('getUID')->willReturn($ownerUid);
			$node->method('getOwner')->willReturn($owner);
		}

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($node);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);

		return $node;
	}

	public function testReturnsUnauthorizedWhenNotLoggedIn(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->controller->removeWatermark('doc.pdf')->getStatus(),
		);
	}

	public function testReturnsNotFoundWhenFileMissing(): void {
		$this->loginAlice();

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->removeWatermark('missing.pdf')->getStatus(),
		);
	}

	/**
	 * Write permission is not what governs this, ownership is.
	 *
	 * It used to be a write: the removal rewrote the file with a preserved copy, so
	 * read-only access had to be refused. Nothing is written now, and the check that
	 * replaced it asks a different question - so an owner whose own file is not updateable
	 * (a read-only mount) can still take the watermark off it. Asserted rather than left
	 * implied, because "requires write" is the rule anyone would reintroduce by reflex.
	 */
	public function testWritePermissionIsNotWhatGovernsUnmarking(): void {
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: false);

		$this->watermarkService->expects($this->once())->method('unmark')->willReturn(true);

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller->removeWatermark('doc.pdf')->getStatus(),
		);
	}

	public function testReturnsForbiddenWhenNotReadable(): void {
		$this->loginAlice();
		$this->mockFile(readable: false, updateable: true);

		$this->watermarkService->expects($this->never())->method('unmark');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->removeWatermark('doc.pdf')->getStatus(),
		);
	}

	/**
	 * **A share recipient cannot take the watermark off the document they were given.**
	 *
	 * This is the one rule where marking and unmarking deliberately part company. Marking
	 * asks for write permission, because it is a change to the file's policy and the people
	 * who can change the file are the people who can change that. Applying the same rule to
	 * unmarking would hand the off switch to a recipient with edit permission - and whoever
	 * the shared copy would have named is exactly whoever has an interest in it naming
	 * nobody.
	 *
	 * Note what is asserted: the recipient here has **both** read and write permission, so
	 * nothing but the ownership check can be what refuses them.
	 */
	public function testAShareRecipientCannotUnmarkTheOwnersFile(): void {
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: true, ownerUid: 'bob');

		$this->watermarkService->expects($this->never())->method('unmark');

		$response = $this->controller->removeWatermark('shared.pdf');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		// The message has to name the reason: a recipient who can rename and delete the
		// file will otherwise read this as a bug.
		$this->assertStringContainsString('owner', $response->getData()['error']);
	}

	/**
	 * A node whose owner cannot be resolved - a broken mount, most of all - is refused.
	 *
	 * "Cannot establish who owns this" is not "this user owns it". The failure is rare and
	 * the cost of being wrong is one-directional, so the check fails closed.
	 */
	public function testAnUnresolvableOwnerIsRefused(): void {
		$this->loginAlice();
		$this->mockFile(readable: true, updateable: true, ownerUid: null);

		$this->watermarkService->expects($this->never())->method('unmark');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller->removeWatermark('orphan.pdf')->getStatus(),
		);
	}

	public function testUnmarksTheFile(): void {
		$this->loginAlice();
		$node = $this->mockFile(readable: true, updateable: true);

		$this->watermarkService->expects($this->once())
			->method('unmark')
			->with($node)
			->willReturn(true);

		$response = $this->controller->removeWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'removed', 'path' => 'doc.pdf'], $response->getData());
	}

	/**
	 * A file that was not marked is a no-op, not a failure.
	 *
	 * This used to be a 422: the removal restored a preserved original, and a file with
	 * none had nothing to restore. Nothing is restored now - the stored file was never
	 * changed - so "not marked" is simply the state the caller asked for, and reporting it
	 * as an error would put a red note card in front of a user who got what they wanted.
	 */
	public function testAnUnmarkedFileIsANoOpRatherThanAnError(): void {
		$this->loginAlice();
		$node = $this->mockFile(readable: true, updateable: true);

		$this->watermarkService->expects($this->once())
			->method('unmark')
			->with($node)
			->willReturn(false);

		$response = $this->controller->removeWatermark('doc.pdf');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'not_watermarked', 'path' => 'doc.pdf'], $response->getData());
	}
}
