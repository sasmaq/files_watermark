<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\TeamFolder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Recognising a Team folder mount without the groupfolders app present.
 *
 * These drive `IMountPoint` directly, which is the only thing they can do: `groupfolders`
 * is not installed in this repo's environment. So they pin the *logic* - which signals
 * are read, in which order, and what happens when one of them is missing or throws - and
 * they deliberately do not claim to prove the premise that a real Team folder mount
 * reports `OCA\GroupFolders\Mount\MountProvider` and the `group` mount type. That is
 * written down as the one thing an installed instance still has to confirm; see
 * `doc/development.md`.
 *
 * The provider name is asserted as a **string literal** here rather than through the
 * class constant, so a rename of the constant's value has to be made twice on purpose.
 */
class TeamFolderTest extends TestCase {

	private IRootFolder&MockObject $rootFolder;
	private LoggerInterface&MockObject $logger;
	private TeamFolder $teamFolder;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->teamFolder = new TeamFolder($this->rootFolder, $this->logger);
	}

	public function testAMountFromTheGroupfoldersProviderIsATeamFolder(): void {
		$node = $this->nodeOn($this->mount('OCA\GroupFolders\Mount\MountProvider', 'group'));

		$this->assertTrue($this->teamFolder->contains($node));
	}

	public function testTheMountTypeAloneIsEnough(): void {
		// getMountProvider() returns the empty string for a mount whose provider did not
		// set one, which is why the type is read as well rather than as a nicety.
		$node = $this->nodeOn($this->mount('', 'group'));

		$this->assertTrue($this->teamFolder->contains($node));
	}

	public function testTheProviderAloneIsEnough(): void {
		// The inverse: a future groupfolders that reports a different mount type is still
		// recognised by the provider that built the mount.
		$node = $this->nodeOn($this->mount('OCA\GroupFolders\Mount\MountProvider', 'something-else'));

		$this->assertTrue($this->teamFolder->contains($node));
	}

	/**
	 * @dataProvider ordinaryMountProvider
	 */
	public function testOrdinaryMountsAreNotTeamFolders(string $provider, string $type): void {
		$node = $this->nodeOn($this->mount($provider, $type));

		$this->assertFalse($this->teamFolder->contains($node));
	}

	/** @return array<string, array{string, string}> */
	public static function ordinaryMountProvider(): array {
		return [
			// The two mount types core itself uses. Neither may be reclassified: a
			// received share is already share access by the storage test, and treating an
			// external storage as a Team folder would send its backups somewhere no
			// encryption module reaches.
			'a received share' => ['OCA\Files_Sharing\MountProvider', 'shared'],
			'an external storage' => ['OCA\Files_External\Config\ConfigAdapter', 'external'],
			'a plain home' => ['OC\Files\Mount\LocalHomeMountProvider', ''],
		];
	}

	public function testAMountThatCannotNameItselfIsNotATeamFolder(): void {
		// Degrading to "ordinary node" is the safe direction: it leaves the app behaving
		// exactly as it did before Team folders were considered at all.
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getMountProvider')->willThrowException(new \RuntimeException('no provider'));
		$mount->method('getMountType')->willThrowException(new \RuntimeException('no type'));

		$this->assertFalse($this->teamFolder->contains($this->nodeOn($mount)));
	}

	public function testAThrowingMountPointIsNotATeamFolder(): void {
		$node = $this->createMock(File::class);
		$node->method('getMountPoint')->willThrowException(new \RuntimeException('no mount'));

		$this->assertFalse($this->teamFolder->contains($node));
	}

	public function testTheRootIsResolvedWithoutTheMountPointsTrailingSlash(): void {
		// getMountPoint() reports `/alice/files/Team A/`; IRootFolder::get() resolves a
		// different string for the same node if the slash is left on.
		$root = $this->createMock(Folder::class);
		$this->rootFolder->expects($this->once())
			->method('get')
			->with('/alice/files/Team A')
			->willReturn($root);

		$node = $this->nodeOn($this->mount('', 'group', '/alice/files/Team A/'));

		$this->assertSame($root, $this->teamFolder->rootOf($node));
	}

	public function testAnOrdinaryNodeHasNoTeamFolderRoot(): void {
		$this->rootFolder->expects($this->never())->method('get');

		$this->assertNull($this->teamFolder->rootOf($this->nodeOn($this->mount('', 'shared'))));
	}

	public function testAnUnreachableRootIsReportedAsNullRatherThanThrown(): void {
		// A backup that cannot be written is a watermark that cannot be undone, which
		// OriginalStore reports. Throwing here would take down the watermark itself.
		$this->rootFolder->method('get')->willThrowException(new NotFoundException());
		$this->logger->expects($this->once())->method('error');

		$this->assertNull($this->teamFolder->rootOf($this->nodeOn($this->mount('', 'group'))));
	}

	public function testARootThatIsNotAFolderIsRefused(): void {
		$this->rootFolder->method('get')->willReturn($this->createMock(File::class));

		$this->assertNull($this->teamFolder->rootOf($this->nodeOn($this->mount('', 'group'))));
	}

	private function mount(string $provider, string $type, string $path = '/alice/files/Team A/'): IMountPoint&MockObject {
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getMountProvider')->willReturn($provider);
		$mount->method('getMountType')->willReturn($type);
		$mount->method('getMountPoint')->willReturn($path);
		return $mount;
	}

	private function nodeOn(IMountPoint&MockObject $mount): File&MockObject {
		$node = $this->createMock(File::class);
		$node->method('getMountPoint')->willReturn($mount);
		return $node;
	}
}
