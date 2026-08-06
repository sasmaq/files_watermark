<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Migration;

use OCA\FilesWatermark\Migration\Version1002Date20260804120000;
use OCA\FilesWatermark\Migration\Version1003Date20260806120000;
use OCA\FilesWatermark\Migration\Version1004Date20260806140000;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Pins the property that squashing the migration chain into one file depends on.
 *
 * `Version1002Date20260804120000` replaced the whole previous chain: 1003 (itself a squash
 * of 1000-1002), 1004, 1005, 1006, 1007 and 1008. Nextcloud will not re-run a migration it
 * has already recorded, but the squashed file carries a version string no instance has seen
 * - so it runs everywhere, meeting instances in five different states, and has to land all
 * of them on the same schema:
 *
 * 1. **fresh** - no tables at all;
 * 2. **applied 1000** - tables exist, no flattening columns;
 * 3. **applied 1000 + 1001** - tables exist *with* the flattening columns;
 * 4. **applied 1003** - tables exist as that step created them, scope columns included;
 * 5. **applied 1003 through 1008** - the finished schema, with nothing left to do.
 *
 * State 5 is what the squash added and it is the one worth having: the file now runs
 * against instances that are *already correct*, so every step has to be a no-op there.
 * Only state 1 is what a developer sees locally, which is exactly why this is a test and
 * not a comment. An `addColumn` that loses its `hasColumn` guard, or a `createTable` that
 * stops checking `hasTable`, breaks the other four silently.
 *
 * The token rewrite is the one step not exercised here - it changes no schema, and its
 * gate is pinned by `UsernameTokenRewriteTest`.
 *
 * Doctrine is not a dependency of this app - Nextcloud provides it at runtime - so the
 * schema objects here are fakes. That is enough: what is under test is the migration's
 * branching, not Doctrine's DDL.
 */
class SchemaConvergenceTest extends TestCase {

	/**
	 * Ordered, because that is what a fresh install produces and what an upgraded one has
	 * to match: `log_delivery` is last because 1007 appends it to a table the earlier
	 * steps already built.
	 */
	private const EXPECTED_CONFIG_COLUMNS = [
		'id',
		'type',
		'text_template',
		'image_path',
		'opacity',
		'font_size',
		'color',
		'rotation',
		'trigger',
		'mime_types',
		'folder_tag',
		'created_at',
		'updated_at',
		'log_delivery',
		// 1004, and last for the same reason `log_delivery` was: appended to a table every
		// earlier step has already built.
		'watermark_internal_shares',
		'watermark_external_shares',
	];

	/**
	 * What the pre-1007 states are seeded with - every expected column *except* the one
	 * 1007 adds. Seeding `log_delivery` too would let its `hasColumn` guard skip the
	 * `addColumn` on all three upgrade paths, which is precisely the branch under test.
	 *
	 * `position` is absent here and seeded with the other dropped columns instead: every
	 * install that predates 1008 has it, and no install that survives 1008 does.
	 */
	private const PRE_1007_CONFIG_COLUMNS = [
		'id',
		'type',
		'text_template',
		'image_path',
		'opacity',
		'font_size',
		'color',
		'rotation',
		'trigger',
		'mime_types',
		'folder_tag',
		'created_at',
		'updated_at',
	];

	/**
	 * Columns an earlier version created and a later one dropped. Seeding them here is
	 * what makes the upgrade states differ from the fresh one at all.
	 *
	 * `position` joins the scope columns as of 1008: same story, one step later - stored,
	 * never read, and dropped rather than implemented.
	 */
	private const DROPPED_CONFIG_COLUMNS = ['user_id', 'group_id', 'position'];

	/** The scope columns' indexes, which have to go with them. `position` had none. */
	private const DROPPED_CONFIG_INDEXES = ['wm_config_user_idx', 'wm_config_group_idx'];

	/**
	 * @return array<string, array{bool, list<string>, bool}> whether the tables already
	 *                                                        exist, any flattening columns
	 *                                                        on them, and whether the
	 *                                                        schema is already current
	 */
	public static function startingStateProvider(): array {
		return [
			'fresh install' => [false, [], false],
			'applied 1000' => [true, [], false],
			'applied 1000 and 1001' => [true, ['flatten_pdf', 'flatten_dpi'], false],
			'applied 1003' => [true, [], false],
			'applied 1003 through 1008' => [true, [], true],
		];
	}

	/**
	 * @dataProvider startingStateProvider
	 *
	 * @param list<string> $preexistingFlattenColumns
	 */
	public function testEveryStartingStateConvergesOnTheSameSchema(
		bool $tablesAlreadyExist,
		array $preexistingFlattenColumns,
		bool $alreadyCurrent,
	): void {
		$schema = new FakeSchema();

		// A fresh install starts empty; the upgrade states are pre-seeded the way the
		// deleted migrations would have left them.
		if ($alreadyCurrent) {
			$this->preCreateCurrentTables($schema);
		} elseif ($tablesAlreadyExist) {
			$this->preCreateTables($schema, $preexistingFlattenColumns);
		}

		$this->runMigration($schema);

		$this->assertSame(
			self::EXPECTED_CONFIG_COLUMNS,
			$schema->getTable('watermark_config')->columnNames(),
			'watermark_config did not converge on the expected columns',
		);
		$this->assertTrue($schema->hasTable('watermark_log'), 'watermark_log is missing');
		$this->assertTrue($schema->hasTable('watermark_mark'), 'watermark_mark is missing');
		$this->assertContains(
			'wm_mark_file_idx',
			$schema->getTable('watermark_mark')->indexNames(),
			'the mark table needs its unique file_id index - it is what makes marking idempotent',
		);
	}

	/** The flattening columns must be gone whichever state we started from. */
	public function testFlatteningColumnsAreDroppedOnUpgrade(): void {
		$schema = new FakeSchema();
		$this->preCreateTables($schema, ['flatten_pdf', 'flatten_dpi']);

		$this->runMigration($schema);

		$columns = $schema->getTable('watermark_config')->columnNames();
		$this->assertNotContains('flatten_pdf', $columns);
		$this->assertNotContains('flatten_dpi', $columns);
	}

	/**
	 * The retired columns go the same way, and any indexes have to go with them.
	 *
	 * Leaving an index behind is the failure mode worth pinning: the column drop is the
	 * visible half, and an index over a column that no longer exists is DDL the database
	 * platforms disagree about rather than anything this test could otherwise see.
	 */
	public function testRetiredColumnsAndTheirIndexesAreDroppedOnUpgrade(): void {
		$schema = new FakeSchema();
		$this->preCreateTables($schema, []);
		$table = $schema->getTable('watermark_config');
		foreach (self::DROPPED_CONFIG_COLUMNS as $column) {
			$this->assertTrue($table->hasColumn($column), "the starting state must have $column");
		}
		foreach (self::DROPPED_CONFIG_INDEXES as $index) {
			$this->assertTrue($table->hasIndex($index), "the starting state must have $index");
		}

		$this->runMigration($schema);

		foreach (self::DROPPED_CONFIG_COLUMNS as $column) {
			$this->assertNotContains($column, $table->columnNames());
		}
		foreach (self::DROPPED_CONFIG_INDEXES as $index) {
			$this->assertNotContains($index, $table->indexNames());
		}
	}

	/** A fresh install never has them, so the drop steps must pass over them quietly. */
	public function testDroppingTheRetiredColumnsIsSkippedOnAFreshInstall(): void {
		$schema = new FakeSchema();

		$this->runMigration($schema);

		$columns = $schema->getTable('watermark_config')->columnNames();
		foreach (self::DROPPED_CONFIG_COLUMNS as $column) {
			$this->assertNotContains($column, $columns);
		}
	}

	/** Re-running must not duplicate columns or throw - the tables already exist. */
	public function testRunningTwiceIsHarmless(): void {
		$schema = new FakeSchema();
		$this->runMigration($schema);
		$first = $schema->getTable('watermark_config')->columnNames();

		$this->runMigration($schema);

		$this->assertSame($first, $schema->getTable('watermark_config')->columnNames());
	}

	/**
	 * The legacy `image_path` cleanup decides what to clear with
	 * {@see WatermarkImageStore::isReference()}, so the values that survive validation are
	 * exactly the ones the renderers can resolve.
	 *
	 * The query plumbing is not mocked here - that would assert the shape of a
	 * QueryBuilder chain rather than any behaviour. What matters is the predicate, and
	 * that it treats a pre-validation absolute path as legacy while leaving a
	 * store-issued reference alone.
	 */
	public function testLegacyImagePathsAreDistinguishedFromValidReferences(): void {
		$valid = str_repeat('a', 32) . '.png';
		$this->assertTrue(WatermarkImageStore::isReference($valid));

		foreach (
			[
				'/var/www/html/core/img/logo.png',
				'/etc/passwd',
				'../../' . str_repeat('a', 32) . '.png',
				'logo.png',
			] as $legacy
		) {
			$this->assertFalse(
				WatermarkImageStore::isReference($legacy),
				"$legacy should be treated as a legacy value and cleared",
			);
		}
	}

	/** @param list<string> $flattenColumns */
	private function preCreateTables(FakeSchema $schema, array $flattenColumns): void {
		$config = $schema->createTable('watermark_config');
		foreach (self::PRE_1007_CONFIG_COLUMNS as $column) {
			$config->addColumn($column, 'string');
		}
		foreach (self::DROPPED_CONFIG_COLUMNS as $column) {
			$config->addColumn($column, 'string');
		}
		foreach ($flattenColumns as $column) {
			$config->addColumn($column, 'boolean');
		}
		foreach (self::DROPPED_CONFIG_INDEXES as $index) {
			$config->addIndex(['ignored'], $index);
		}
		$schema->createTable('watermark_log')->addColumn('id', 'bigint');
	}

	/**
	 * The finished schema, as an instance that ran the whole old chain already has it.
	 *
	 * No dropped columns and no dropped indexes, because 1005, 1006 and 1008 already took
	 * them - and `log_delivery` present, because 1007 already added it. Every step in the
	 * squashed migration must find nothing to do here.
	 */
	private function preCreateCurrentTables(FakeSchema $schema): void {
		$config = $schema->createTable('watermark_config');
		foreach (self::EXPECTED_CONFIG_COLUMNS as $column) {
			$config->addColumn($column, 'string');
		}
		$schema->createTable('watermark_log')->addColumn('id', 'bigint');
	}

	/**
	 * The migration's schema half, in the order Nextcloud applies it.
	 *
	 * `preSchemaChange` is driven too, and that is not incidental: it deletes the per-user
	 * rows *before* `user_id` goes, and it is where the token-rewrite gate reads
	 * `log_delivery` - before `changeSchema` adds that very column. Running the two out of
	 * order is the mistake worth catching.
	 */
	private function runMigration(FakeSchema $schema): void {
		$output = $this->createMock(IOutput::class);
		$closure = fn (): ISchemaWrapper => $schema;

		$migration = new Version1002Date20260804120000($this->stubConnection());
		$migration->preSchemaChange($output, $closure, []);
		$migration->changeSchema($output, $closure, []);

		// 1003 and 1004 ride along rather than getting their own runners: every state above
		// reaches them too, and the property under test is that the whole chain converges -
		// not that each file converges on its own.
		(new Version1003Date20260806120000())->changeSchema($output, $closure, []);
		(new Version1004Date20260806140000())->changeSchema($output, $closure, []);
	}

	/**
	 * A connection whose query builder accepts the per-user delete and reports nothing
	 * removed. The delete's *SQL* is not what this test is about - the ordering against
	 * the column drop is.
	 */
	private function stubConnection(): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('delete')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('executeStatement')->willReturn(0);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}
}
