<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Migration;

use OCA\FilesWatermark\Migration\Version1002Date20260804120000;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The data half of {@see Version1002Date20260804120000}: clearing `image_path` values
 * that predate upload validation.
 *
 * Those rows are the residue of a fixed vulnerability - `saveConfig` once stored whatever
 * the client sent, so a config could name any file the web server could read. They still
 * look valid in the admin form while resolving to no image.
 *
 * What is worth testing here is the *decisions*: which rows are selected for clearing,
 * and that no write happens when there is nothing to clear. The QueryBuilder is mocked,
 * so this does not prove the SQL runs - it proves the migration asks for the right thing
 * and skips the write when it should.
 *
 * `postSchemaChange` is called without `preSchemaChange`, which isolates this step: the
 * token rewrite that shares the same hook is gated on a flag that defaults to *not*
 * rewriting, so it stays out of the way here. {@see UsernameTokenRewriteTest} drives both
 * hooks in order, which is what a real upgrade does.
 */
class LegacyImagePathCleanupTest extends TestCase {

	/** A write on every upgrade forever, on instances with nothing wrong, is worth avoiding. */
	public function testNoUpdateIsIssuedWhenEveryReferenceIsValid(): void {
		$rows = [
			['id' => 1, 'image_path' => str_repeat('a', 32) . '.png'],
			['id' => 2, 'image_path' => str_repeat('b', 32) . '.jpg'],
			['id' => 3, 'image_path' => ''],
		];

		$db = $this->connectionReturning($rows, expectUpdate: false);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new Version1002Date20260804120000($db))->postSchemaChange($output, fn () => null, []);
	}

	/**
	 * The ids handed to the update must be exactly the offending rows - clearing a valid
	 * reference would destroy a working configuration.
	 */
	public function testOnlyLegacyRowsAreCleared(): void {
		$rows = [
			['id' => 1, 'image_path' => str_repeat('a', 32) . '.png'],   // valid, keep
			['id' => 2, 'image_path' => '/var/www/html/core/img/logo.png'], // legacy
			['id' => 3, 'image_path' => ''],                              // nothing stored
			['id' => 4, 'image_path' => '../../' . str_repeat('a', 32) . '.png'], // traversal
			['id' => 5, 'image_path' => str_repeat('z', 32) . '.png'],    // not hex
		];

		$captured = [];
		$db = $this->connectionReturning($rows, expectUpdate: true, captured: $captured);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('cleared 3 watermark image reference(s)'));

		(new Version1002Date20260804120000($db))->postSchemaChange($output, fn () => null, []);

		$this->assertSame([[2, 4, 5]], $captured, 'the wrong set of rows was cleared');
	}

	/**
	 * Builds a connection whose select yields `$rows`, and whose update - if one is
	 * expected - records the id list it was given into `$captured`.
	 *
	 * @param list<array{id: int, image_path: string}> $rows
	 * @param list<list<int>> $captured
	 */
	private function connectionReturning(array $rows, bool $expectUpdate, array &$captured = []): IDBConnection {
		$result = $this->createMock(IResult::class);
		// fetch() drains the rows and then returns false, like a real cursor.
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

		$expr = $this->createMock(IExpressionBuilder::class);

		$select = $this->createMock(IQueryBuilder::class);
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('expr')->willReturn($expr);
		$select->method('executeQuery')->willReturn($result);

		$update = $this->createMock(IQueryBuilder::class);
		$update->method('update')->willReturnSelf();
		$update->method('set')->willReturnSelf();
		$update->method('where')->willReturnSelf();
		$update->method('expr')->willReturn($expr);
		$update->method('createNamedParameter')->willReturnCallback(
			function (mixed $value) use (&$captured): string {
				if (is_array($value)) {
					$captured[] = array_values($value);
				}
				return ':p';
			},
		);
		$update->expects($expectUpdate ? $this->once() : $this->never())->method('executeStatement');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($select, $update);

		return $db;
	}
}
