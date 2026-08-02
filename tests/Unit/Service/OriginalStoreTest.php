<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\OriginalStore;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Where a preserved original is written, and what happens when it cannot be.
 *
 * The location is the whole point of this class and is asserted directly: copies go into
 * the **owner's** storage through the Files API, because that is the only path the
 * server's encryption module covers. A test that only checked "a backup exists" would
 * pass just as happily with the copy written in the clear to appdata, which is the bug
 * this arrangement exists to prevent.
 */
class OriginalStoreTest extends TestCase {

	private IAppDataFactory&MockObject $appDataFactory;
	private IRootFolder&MockObject $rootFolder;
	private LoggerInterface&MockObject $logger;
	private OriginalStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->appDataFactory = $this->createMock(IAppDataFactory::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->store = new OriginalStore($this->appDataFactory, $this->rootFolder, $this->logger);
	}

	public function testOriginalIsWrittenIntoTheOwnersStorage(): void {
		$originals = $this->createMock(Folder::class);
		$originals->method('nodeExists')->with('11')->willReturn(false);
		// Through the Files API, so the storage layer applies the selected encryption
		// module. A raw write would leave the copy in the clear beside ciphertext.
		$originals->expects($this->once())
			->method('newFile')
			->with('11', 'clean-bytes');

		$this->userFolder('alice', $originals);

		$this->assertTrue($this->store->store($this->file(11, 'alice'), 'clean-bytes'));
	}

	public function testTheCopyGoesToTheOwnerNotTheActingUser(): void {
		// A shared file's backup belongs to whoever owns the bytes: the recipient can
		// lose access tomorrow, and it is not their quota to spend.
		$originals = $this->createMock(Folder::class);
		$originals->method('nodeExists')->willReturn(false);
		$originals->method('newFile');

		// The assertion that matters is the uid, not the call count: store() checks for
		// an existing copy before writing one, so it resolves the folder twice.
		$this->rootFolder->expects($this->atLeastOnce())
			->method('getUserFolder')
			->with('owner')
			->willReturn($this->userFolderReturning($originals));

		$this->store->store($this->file(11, 'owner'), 'clean-bytes');
	}

	public function testAnExistingOriginalIsNeverOverwritten(): void {
		// Re-watermarking an already-watermarked file would otherwise replace the true
		// original with watermarked bytes, quietly making the file unrestorable.
		$originals = $this->createMock(Folder::class);
		$originals->method('nodeExists')->with('11')->willReturn(true);
		$originals->expects($this->never())->method('newFile');

		$this->userFolder('alice', $originals);

		$this->assertTrue($this->store->store($this->file(11, 'alice'), 'newer-bytes'));
	}

	public function testAFailedWriteIsReportedRatherThanThrown(): void {
		// Quota is the expected failure now that copies land in the owner's storage. The
		// watermark still applies; removeWatermark() is what tells the user it cannot be
		// undone. Throwing here would take down the watermark operation instead.
		$originals = $this->createMock(Folder::class);
		$originals->method('nodeExists')->willReturn(false);
		$originals->method('newFile')->willThrowException(new \RuntimeException('quota exceeded'));

		$this->userFolder('alice', $originals);
		$this->emptyLegacyFolder();
		$this->logger->expects($this->once())->method('error');

		$this->assertFalse($this->store->store($this->file(11, 'alice'), 'clean-bytes'));
	}

	public function testReadPrefersTheOwnersCopy(): void {
		$copy = $this->createMock(File::class);
		$copy->method('getContent')->willReturn('clean-bytes');

		$originals = $this->createMock(Folder::class);
		$originals->method('get')->with('11')->willReturn($copy);
		$this->userFolder('alice', $originals);

		// appdata must not even be consulted when the owner's copy is there.
		$this->appDataFactory->expects($this->never())->method('get');

		$this->assertSame('clean-bytes', $this->store->read($this->file(11, 'alice')));
	}

	public function testACopyWrittenBeforeTheMoveIsStillRestorable(): void {
		// Upgrading must not strand the backups already in appdata: nothing migrates
		// them, so a read that stopped at the owner's storage would make every
		// pre-upgrade watermark permanent.
		$originals = $this->createMock(Folder::class);
		$originals->method('get')->willThrowException(new NotFoundException());
		$this->userFolder('alice', $originals);

		$legacyFile = $this->createMock(ISimpleFile::class);
		$legacyFile->method('getContent')->willReturn('legacy-bytes');
		$this->legacyFolder($legacyFile);

		$this->assertSame('legacy-bytes', $this->store->read($this->file(11, 'alice')));
	}

	public function testDiscardClearsBothLocations(): void {
		// A file watermarked before the move and again after it has a copy in each, and
		// leaving the appdata one behind would keep the older bytes as the file's
		// apparent original for ever.
		$copy = $this->createMock(File::class);
		$copy->expects($this->once())->method('delete');
		$originals = $this->createMock(Folder::class);
		$originals->method('get')->with('11')->willReturn($copy);
		$this->userFolder('alice', $originals);

		$legacyFile = $this->createMock(ISimpleFile::class);
		$legacyFile->expects($this->once())->method('delete');
		$this->legacyFolder($legacyFile);

		$this->store->discard($this->file(11, 'alice'));
	}

	public function testAnOwnerlessNodeFallsBackToAppdataRatherThanFailing(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(11);
		$file->method('getOwner')->willReturn(null);

		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$legacyFile = $this->createMock(ISimpleFile::class);
		$legacyFile->method('getContent')->willReturn('legacy-bytes');
		$this->legacyFolder($legacyFile);

		$this->assertSame('legacy-bytes', $this->store->read($file));
	}

	/**
	 * @dataProvider backupPathProvider
	 */
	public function testBackupsAreRecognisedByPath(string $path, bool $expected): void {
		// The guard that keeps every trigger off the app's own copies. Without it,
		// storing one queues a watermark of the copy, whose own copy queues another.
		$node = $this->createMock(File::class);
		$node->method('getPath')->willReturn($path);

		$this->assertSame($expected, $this->store->isBackup($node));
	}

	/** @return array<string, array{string, bool}> */
	public static function backupPathProvider(): array {
		return [
			'a preserved original' => ['/alice/files/.files_watermark/originals/11', true],
			'nested under it' => ['/bob/files/.files_watermark/originals/220', true],
			'an ordinary file' => ['/alice/files/report.pdf', false],
			// A user is free to make a folder of that name themselves; only the app's
			// own path — the full folder/subfolder pair under files/ — is excluded.
			'a lookalike folder' => ['/alice/files/.files_watermark/notes.pdf', false],
			'a file merely named like it' => ['/alice/files/originals/11', false],
		];
	}

	private function file(int $id, string $owner): File&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($owner);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getOwner')->willReturn($user);
		return $file;
	}

	private function userFolder(string $uid, Folder&MockObject $originals): void {
		$this->rootFolder->method('getUserFolder')
			->with($uid)
			->willReturn($this->userFolderReturning($originals));
	}

	private function userFolderReturning(Folder&MockObject $originals): Folder&MockObject {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')
			->with(OriginalStore::HOME_FOLDER . '/' . OriginalStore::HOME_SUBFOLDER)
			->willReturn($originals);
		$userFolder->method('newFolder')->willReturn($originals);
		return $userFolder;
	}

	/** Appdata reachable, but holding no copy for this file. */
	private function emptyLegacyFolder(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturn(false);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$this->appDataFactory->method('get')->willReturn($appData);
	}

	private function legacyFolder(ISimpleFile&MockObject $file): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturn(true);
		$folder->method('getFile')->with('11')->willReturn($file);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);

		$this->appDataFactory->method('get')->with('files_watermark')->willReturn($appData);
	}
}
