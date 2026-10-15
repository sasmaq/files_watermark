<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Migration;

use OCA\FilesWatermark\Migration\Version1010Date20261015130000;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * {@see Version1010Date20261015130000}: recording, on marks placed before the remove gate
 * asked for it, who owns the file.
 *
 * As with the other data-migration tests, the QueryBuilder is mocked: this proves which rows
 * are written and with what, not that the SQL runs.
 */
class MarkOwnerBackfillTest extends TestCase {

	/**
	 * @param list<list<array{id: int, file_id: int}>> $pages what each select returns, in order
	 * @param list<array{0: mixed, 1: mixed}> $updates receives [owner, mark id] per update
	 */
	private function connection(array $pages, array &$updates): IDBConnection {
		$params = [];

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'update', 'set'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturnCallback(static function (mixed $value) use (&$params): string {
			$params[] = $value;
			return ':param' . count($params);
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$pages): IResult {
			$rows = array_shift($pages) ?? [];
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
			return $result;
		});
		// The update binds the owner, then the mark id - the last two parameters bound.
		$qb->method('executeStatement')->willReturnCallback(static function () use (&$params, &$updates): int {
			$updates[] = array_slice($params, -2);
			return 1;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function mountFor(string $uid): ICachedMountFileInfo {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$mount = $this->createMock(ICachedMountFileInfo::class);
		$mount->method('getUser')->willReturn($user);
		return $mount;
	}

	private function ownedBy(string $uid): File {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$file = $this->createMock(File::class);
		$file->method('getOwner')->willReturn($user);
		return $file;
	}

	public function testEachResolvableMarkIsGivenItsFilesOwnerAndTheRestAreLeftAlone(): void {
		$updates = [];
		$db = $this->connection([
			[['id' => 1, 'file_id' => 10], ['id' => 2, 'file_id' => 20], ['id' => 3, 'file_id' => 30]],
			[],
		], $updates);

		// 10 is mounted for its owner, 20 for nobody (deleted for good), and 30 for a
		// recipient whose folder cannot resolve it.
		$mountCache = $this->createMock(IUserMountCache::class);
		$mountCache->method('getMountsForFileId')->willReturnCallback(fn (int $id): array => match ($id) {
			10 => [$this->mountFor('bob')],
			20 => [],
			30 => [$this->mountFor('carol')],
		});

		// Bob reaches file 10 through a share, and the owner is still Alice.
		$bobs = $this->createMock(Folder::class);
		$bobs->method('getFirstNodeById')->with(10)->willReturn($this->ownedBy('alice'));
		$carols = $this->createMock(Folder::class);
		$carols->method('getFirstNodeById')->willThrowException(new \RuntimeException('mount offline'));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(static fn (string $uid): Folder => $uid === 'bob' ? $bobs : $carols);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('recorded the owner on 1 existing watermark mark(s); 2 could not be resolved'));

		(new Version1010Date20261015130000($db, $rootFolder, $mountCache, new NullLogger()))
			->postSchemaChange($output, fn () => null, []);

		$this->assertSame([['alice', 1]], $updates);
	}

	/** An install with nothing to backfill writes nothing and says nothing. */
	public function testNothingToBackfillIsSilent(): void {
		$updates = [];
		$db = $this->connection([[]], $updates);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new Version1010Date20261015130000($db, $this->createMock(IRootFolder::class), $this->createMock(IUserMountCache::class), new NullLogger()))
			->postSchemaChange($output, fn () => null, []);

		$this->assertSame([], $updates);
	}
}
