<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\FolderTagScope;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Cache\IFileAccess;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Inside a tagged folder", at any depth, by where the file is stored.
 *
 * The storage below is one home: `files` (1), `files/Projects` (2, tagged in most cases),
 * `files/Projects/Q3` (3) and `files/Projects/Q3/report.pdf` (42), plus the storage root (10).
 *
 * @covers \OCA\FilesWatermark\Service\FolderTagScope
 */
class FolderTagScopeTest extends TestCase {

	private const STORAGE = 5;
	private const FOLDERS = ['' => 10, 'files' => 1, 'files/Projects' => 2, 'files/Projects/Q3' => 3];

	private IFileAccess&MockObject $fileAccess;
	private ISystemTagObjectMapper&MockObject $tagObjectMapper;
	private FolderTagScope $scope;

	/** @var list<string> paths looked up, in order */
	private array $lookedUp = [];

	protected function setUp(): void {
		parent::setUp();

		$this->fileAccess = $this->createMock(IFileAccess::class);
		$this->tagObjectMapper = $this->createMock(ISystemTagObjectMapper::class);
		$this->scope = new FolderTagScope($this->fileAccess, $this->tagObjectMapper);

		$this->fileAccess->method('getByPathInStorage')->willReturnCallback(
			function (string $path, int $storage): ?ICacheEntry {
				$this->lookedUp[] = $path;
				$id = $storage === self::STORAGE ? (self::FOLDERS[$path] ?? null) : null;
				return $id === null ? null : $this->entry($id, $path, 'httpd/unix-directory');
			},
		);
	}

	private function entry(int $id, string $path, string $mime = 'application/pdf'): ICacheEntry {
		$entry = $this->createMock(ICacheEntry::class);
		$entry->method('getId')->willReturn($id);
		$entry->method('getPath')->willReturn($path);
		$entry->method('getMimeType')->willReturn($mime);
		$entry->method('getStorageId')->willReturn(self::STORAGE);
		return $entry;
	}

	/** @param array<int, ICacheEntry> $entries by file id */
	private function rows(array $entries): void {
		$this->fileAccess->method('getByFileId')->willReturnCallback(
			static fn (int $id): ?ICacheEntry => $entries[$id] ?? null,
		);
	}

	/**
	 * **Two folders down from the tagged one is still inside it**, which the old
	 * immediate-parent test missed. Every ancestor up to the storage root is a candidate,
	 * and the tag is asked once, for any of them.
	 */
	public function testAFileAnyDepthBelowTheTaggedFolderIsInside(): void {
		$this->rows([42 => $this->entry(42, 'files/Projects/Q3/report.pdf')]);

		$this->tagObjectMapper->expects($this->once())
			->method('haveTag')
			->with(['3', '2', '1', '10'], 'files', '7', false)
			->willReturn(true);

		$this->assertTrue($this->scope->covers(42, '7'));
	}

	public function testAFileWithNoTaggedAncestorIsNotInside(): void {
		$this->rows([42 => $this->entry(42, 'files/Projects/Q3/report.pdf')]);
		$this->tagObjectMapper->method('haveTag')->willReturn(false);

		$this->assertFalse($this->scope->covers(42, '7'));
	}

	/**
	 * **A file carrying the tag itself is not inside anything.** The setting scopes folders;
	 * the file's own id is never a candidate.
	 */
	public function testAFilesOwnTagIsNotACandidate(): void {
		$this->rows([42 => $this->entry(42, 'files/report.pdf')]);

		$this->tagObjectMapper->expects($this->once())
			->method('haveTag')
			->with(['1', '10'], 'files', '7', false)
			->willReturn(false);

		$this->assertFalse($this->scope->covers(42, '7'));
	}

	/** A folder counts as inside itself, which is what a moved or tagged folder asks. */
	public function testAFolderIsItsOwnCandidate(): void {
		$this->rows([2 => $this->entry(2, 'files/Projects', 'httpd/unix-directory')]);

		$this->tagObjectMapper->expects($this->once())
			->method('haveTag')
			->with(['2', '1', '10'], 'files', '7', false)
			->willReturn(true);

		$this->assertTrue($this->scope->covers(2, '7'));
	}

	/**
	 * **The ancestors are the stored ones**, read by storage and path from the raw file
	 * cache. A recipient's view of a share stops at its mount point; asked by id, the file's
	 * row is the owner's, and so are its ancestors. Here the row is all the test supplies -
	 * no node, no view - and the owner's tagged folder is still found.
	 */
	public function testTheAncestorsComeFromTheStorageNotFromAView(): void {
		$this->rows([42 => $this->entry(42, 'files/Projects/Q3/report.pdf')]);
		$this->tagObjectMapper->method('haveTag')->willReturn(true);

		$this->scope->covers(42, '7');

		$this->assertSame(['files/Projects/Q3', 'files/Projects', 'files', ''], $this->lookedUp);
	}

	/**
	 * Memoised by path for the request: a listing of siblings, or a walk of one folder,
	 * looks the shared ancestors up once. Only the file's own row is read per call - it is
	 * what a move changes.
	 */
	public function testSharedAncestorsAreLookedUpOncePerRequest(): void {
		$this->rows([
			42 => $this->entry(42, 'files/Projects/Q3/report.pdf'),
			43 => $this->entry(43, 'files/Projects/Q3/minutes.pdf'),
		]);
		$this->tagObjectMapper->method('haveTag')->willReturn(true);

		$this->scope->covers(42, '7');
		$this->scope->covers(43, '7');

		$this->assertSame(['files/Projects/Q3', 'files/Projects', 'files', ''], $this->lookedUp);
	}

	public function testAnIdWithNoRowIsNotInside(): void {
		$this->rows([]);
		$this->tagObjectMapper->expects($this->never())->method('haveTag');

		$this->assertFalse($this->scope->covers(42, '7'));
	}

	/** The storage root of an external storage or Team folder, tagged, holds everything in it. */
	public function testTheStorageRootIsACandidate(): void {
		$this->rows([42 => $this->entry(42, 'report.pdf')]);

		$this->tagObjectMapper->expects($this->once())
			->method('haveTag')
			->with(['10'], 'files', '7', false)
			->willReturn(true);

		$this->assertTrue($this->scope->covers(42, '7'));
	}

	/** An unusable tag is the caller's to report, so it is not swallowed here. */
	public function testAnUnusableTagIsThrownToTheCaller(): void {
		$this->rows([42 => $this->entry(42, 'files/report.pdf')]);
		$this->tagObjectMapper->method('haveTag')->willThrowException(new TagNotFoundException('gone'));

		$this->expectException(TagNotFoundException::class);
		$this->scope->covers(42, '7');
	}
}
