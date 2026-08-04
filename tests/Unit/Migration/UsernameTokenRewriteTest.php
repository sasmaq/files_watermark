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
 * The token half of {@see Version1002Date20260804120000}: rewriting stored `{username}` to
 * `{displayname}`, and **not** doing it twice.
 *
 * The token changed meaning - it used to resolve to the display name and now resolves to
 * the account name - so what the rewrite protects is that **no existing watermark changes
 * on upgrade**. Getting it wrong is silent: the files still render, they just say `asmith3`
 * where they used to say `Alice Smith`, and nothing in the UI or the audit log would
 * explain it.
 *
 * **The gate is now half of what is under test.** The rewrite used to live in 1004, which
 * Nextcloud would never re-run; the squashed migration runs on instances that already
 * applied it, so a second rewrite is reachable and would turn an account name an admin
 * typed deliberately back into a display name. `preSchemaChange` decides, from whether
 * `log_delivery` exists, which side of that line the instance is on - so every case here
 * drives `preSchemaChange` before `postSchemaChange`, exactly as Nextcloud does.
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

		$this->runOn($db, $output, alreadyRewritten: false);

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

		$this->runOn($db, $output, alreadyRewritten: false);
	}

	/** A fresh install has no rows, and must not fall over on the empty cursor. */
	public function testFreshInstallIsANoOp(): void {
		$db = $this->connectionReturning([], expectedUpdates: 0);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$this->runOn($db, $output, alreadyRewritten: false);
	}

	/**
	 * The case the squash created, and the reason the gate exists.
	 *
	 * An instance that has `log_delivery` got as far as 1007, so it already ran 1004 - and a
	 * `{username}` in its templates is one an admin typed *after* the meaning changed,
	 * meaning the account name, on purpose. Rewriting it would silently turn it back into
	 * the display name.
	 *
	 * The connection here serves the image-path select and nothing else: a second select
	 * would mean the rewrite ran, and the mock would fail on the unexpected call.
	 */
	public function testTemplatesAreNotRewrittenAgainWhenLogDeliveryExists(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($this->selectYielding($this->resultOf([])));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$this->runOn($db, $output, alreadyRewritten: true);
	}

	/**
	 * Drive the migration the way Nextcloud does: `preSchemaChange` first, so the gate is
	 * decided against the pre-upgrade schema, then `postSchemaChange`.
	 *
	 * `user_id` is deliberately absent from the seeded table. Its delete is a separate
	 * concern, pinned by `SchemaConvergenceTest`, and including it here would consume a
	 * query builder this test's mock is not about.
	 *
	 * @param bool $alreadyRewritten seed `log_delivery`, marking an instance that reached 1007
	 */
	private function runOn(IDBConnection $db, IOutput $output, bool $alreadyRewritten): void {
		$schema = new FakeSchema();
		$config = $schema->createTable('watermark_config');
		$config->addColumn('id', 'integer');
		$config->addColumn('text_template', 'text');
		if ($alreadyRewritten) {
			$config->addColumn('log_delivery', 'boolean');
		}

		$migration = new Version1002Date20260804120000($db);
		$migration->preSchemaChange($output, fn () => $schema, []);
		$migration->postSchemaChange($output, fn () => $schema, []);
	}

	/**
	 * Builds a connection whose template select yields `$rows` and whose updates record the
	 * value they were handed.
	 *
	 * The **first** builder handed out serves the legacy image-path cleanup, which
	 * `postSchemaChange` runs before the rewrite. It is fed an empty cursor: what that step
	 * decides is `LegacyImagePathCleanupTest`'s subject, and it must not issue updates that
	 * this test would attribute to the rewrite.
	 *
	 * @param list<array{id: int, text_template: string}> $rows
	 * @param list<string> $captured
	 */
	private function connectionReturning(array $rows, int $expectedUpdates, array &$captured = []): IDBConnection {
		$imagePaths = $this->selectYielding($this->resultOf([]));
		$templates = $this->selectYielding($this->resultOf($rows));

		$expr = $this->createMock(IExpressionBuilder::class);

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
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($imagePaths, $templates, ...$updates);

		return $db;
	}

	/** @param list<array<string, mixed>> $rows */
	private function resultOf(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

		return $result;
	}

	private function selectYielding(IResult $result): IQueryBuilder {
		$select = $this->createMock(IQueryBuilder::class);
		$select->method('select')->willReturnSelf();
		$select->method('from')->willReturnSelf();
		$select->method('where')->willReturnSelf();
		$select->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$select->method('executeQuery')->willReturn($result);

		return $select;
	}
}
