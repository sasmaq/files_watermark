<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\ShareRecipient;
use OCP\Files\FileInfo;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ShareRecipient}, which answers "what address was this copy sent to".
 *
 * The two halves pull in opposite directions and both matter. It has to name the recipient
 * of a share by email, because that is the one anonymous reader this app can name at all -
 * and it has to decline for every other kind of access, because the alternative is a
 * watermark that claims a reader it does not actually know. An internal share is the case
 * to watch: it presents the same {@see ISharedStorage} interface, and its "shared with" is
 * a uid, so a check on the storage type alone would stamp a username into an address.
 */
class ShareRecipientTest extends TestCase {

	private IShareManager&MockObject $shareManager;
	private ShareRecipient $recipient;

	protected function setUp(): void {
		parent::setUp();
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->recipient = new ShareRecipient($this->shareManager);
	}

	/** A node whose storage carries `$share`, as the public DAV server's does. */
	private function nodeOnShare(IShare $share): FileInfo&MockObject {
		$storage = $this->createMock(ISharedStorage::class);
		$storage->method('instanceOfStorage')->willReturn(true);
		$storage->method('getShare')->willReturn($share);

		$node = $this->createMock(FileInfo::class);
		$node->method('getStorage')->willReturn($storage);

		return $node;
	}

	/** A node on ordinary storage: the owner's own file, or a preview route's view of it. */
	private function plainNode(): FileInfo&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);

		$node = $this->createMock(FileInfo::class);
		$node->method('getStorage')->willReturn($storage);

		return $node;
	}

	private function share(int $type, string $sharedWith): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn($type);
		$share->method('getSharedWith')->willReturn($sharedWith);

		return $share;
	}

	/** The headline case: the download of a file mailed to somebody. */
	public function testAMailShareOnTheStorageNamesItsRecipient(): void {
		$node = $this->nodeOnShare($this->share(IShare::TYPE_EMAIL, 'reader@example.org'));

		$this->assertSame('reader@example.org', $this->recipient->email($node));
	}

	/**
	 * An internal share is an `ISharedStorage` too, and its recipient is a **uid**. Reading
	 * it as an address would put a username where the template asked for an email.
	 */
	public function testAnInternalShareIsNotAnAddress(): void {
		$node = $this->nodeOnShare($this->share(IShare::TYPE_USER, 'alice'));

		$this->assertNull($this->recipient->email($node));
	}

	/** An ordinary public link was sent to nobody in particular, and names nobody. */
	public function testAnOrdinaryLinkShareNamesNobody(): void {
		$node = $this->nodeOnShare($this->share(IShare::TYPE_LINK, ''));

		$this->assertNull($this->recipient->email($node));
	}

	public function testAFileOnOrdinaryStorageNamesNobody(): void {
		$this->assertNull($this->recipient->email($this->plainNode()));
	}

	/**
	 * The preview route's case: nothing on the node says "share", and the token noted by
	 * the middleware is the only thing that can answer.
	 */
	public function testANotedTokenAnswersWhenTheNodeCannot(): void {
		$this->shareManager->method('getShareByToken')
			->with('tok123')
			->willReturn($this->share(IShare::TYPE_EMAIL, 'viewer@example.org'));

		$this->recipient->noteShareToken('tok123');

		$this->assertSame('viewer@example.org', $this->recipient->email($this->plainNode()));
	}

	/**
	 * The storage wins when both could answer.
	 *
	 * It is the share this request actually authenticated against; a token is whatever was
	 * last noted, and on a request that touches more than one file it need not describe the
	 * node being rendered.
	 */
	public function testTheStorageIsPreferredOverANotedToken(): void {
		$this->shareManager->method('getShareByToken')
			->willReturn($this->share(IShare::TYPE_EMAIL, 'from-token@example.org'));
		$this->recipient->noteShareToken('tok123');

		$node = $this->nodeOnShare($this->share(IShare::TYPE_EMAIL, 'from-storage@example.org'));

		$this->assertSame('from-storage@example.org', $this->recipient->email($node));
	}

	/** A token that no longer resolves is not an error worth failing a download over. */
	public function testAnExpiredTokenNamesNobody(): void {
		$this->shareManager->method('getShareByToken')
			->willThrowException(new ShareNotFound());
		$this->recipient->noteShareToken('gone');

		$this->assertNull($this->recipient->email($this->plainNode()));
	}

	/** A storage that will not answer must not cost the reader their download. */
	public function testAStorageThatThrowsNamesNobody(): void {
		$node = $this->createMock(FileInfo::class);
		$node->method('getStorage')->willThrowException(new \RuntimeException('no mount'));

		$this->assertNull($this->recipient->email($node));
	}

	/** A share by email with no address recorded is not an address. */
	public function testAMailShareWithNoAddressNamesNobody(): void {
		$node = $this->nodeOnShare($this->share(IShare::TYPE_EMAIL, '   '));

		$this->assertNull($this->recipient->email($node));
	}
}
