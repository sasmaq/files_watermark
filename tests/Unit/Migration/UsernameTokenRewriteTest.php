<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Migration;

use OCA\FilesWatermark\Migration\Version1004Date20260731000000;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * {@see Version1004Date20260731000000}: rewriting stored `{username}` to `{displayname}`.
 *
 * The token changed meaning - it used to resolve to the display name and now resolves to
 * the account name - so what this migration protects is that **no existing watermark
 * changes on upgrade**. Getting it wrong is silent: the files still render, they just say
 * `asmith3` where they used to say `Alice Smith`, and nothing in the UI or the audit log
 * would explain it.
 *
 * The QueryBuilder is mocked, as in {@see LegacyImagePathCleanupTest}, so this pins the
 * decisions - which rows are rewritten and to what - rather than the SQL.
 */
class UsernameTokenRewriteTest extends TestCase {

	public function testOnlyTemplatesNamingTheTokenAreRewritten(): void {
		$rows = [
			['id' => 1, 'text_template' => '{username} - {date}'],
			['id' => 2, 'text_template' => 'Confidential - {email}'],
			['id' => 3, 'text_template' => '{username}/{filename} ({username})'],
			['id' => 4, 'text_template' => ''],
		];

		$captured = [];
		$db = $this->connectionReturning($rows, expectedUpdates: 2, captured: $captured);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('in 2 watermark template(s)'));

		(new Version1004Date20260731000000($db))->postSchemaChange($output, fn () => null, []);

		$this->assertSame(
			[
				'{displayname} - {date}',
				// Every occurrence, not just the first: a template naming the token twice
				// would otherwise come out half-migrated and render two different identities.
				'{displayname}/{filename} ({displayname})',
			],
			$captured,
		);
	}

	/** An instance whose templates never named the token should not be written to at all. */
	public function testNoWriteWhenNoTemplateUsesTheToken(): void {
		$rows = [
			['id' => 1, 'text_template' => '{displayname} - {date}'],
			['id' => 2, 'text_template' => 'Internal use only'],
		];

		$db = $this->connectionReturning($rows, expectedUpdates: 0);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new Version1004Date20260731000000($db))->postSchemaChange($output, fn () => null, []);
	}

	/** A fresh install has no rows, and must not fall over on the empty cursor. */
	public function testFreshInstallIsANoOp(): void {
		$db = $this->connectionReturning([], expectedUpdates: 0);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new Version1004Date20260731000000($db))->postSchemaChange($output, fn () => null, []);
	}

	/**
	 * Builds a connection whose select yields `$rows` and whose updates record the template
	 * value they were handed.
	 *
	 * @param list<array{id: int, text_template: string}> $rows
	 * @param list<string> $captured
	 */
	private function connectionReturning(array $rows, int $expectedUpdates, array &$captured = []): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

		$expr = $this->createMock(IExpressionBuilder::class);

		$select = $this->createMock(IQueryBuilder::class);
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('expr')->willReturn($expr);
		$select->method('executeQuery')->willReturn($result);

		// One builder per rewritten row, since each carries a different value.
		$updates = [];
		for ($i = 0; $i < $expectedUpdates; $i++) {
			$update = $this->createMock(IQueryBuilder::class);
			$update->method('update')->willReturnSelf();
			$update->method('set')->willReturnSelf();
			$update->method('where')->willReturnSelf();
			$update->method('expr')->willReturn($expr);
			$update->method('createNamedParameter')->willReturnCallback(
				function (mixed $value) use (&$captured): string {
					if (is_string($value)) {
						$captured[] = $value;
					}
					return ':p';
				},
			);
			$update->expects($this->once())->method('executeStatement');
			$updates[] = $update;
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($select, ...$updates);

		return $db;
	}
}
