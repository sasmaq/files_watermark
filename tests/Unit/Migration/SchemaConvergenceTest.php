<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Migration;

use OCA\FilesWatermark\Migration\Version1003Date20260730120000;
use OCA\FilesWatermark\Migration\Version1005Date20260731140000;
use OCA\FilesWatermark\Migration\Version1006Date20260731160000;
use OCA\FilesWatermark\Migration\Version1007Date20260801120000;
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
 * `Version1003` replaced three files (1000 created the tables, 1001 added the PDF
 * flattening columns, 1002 dropped them). Nextcloud will not re-run a migration it has
 * already recorded, so the remaining steps meet instances in four different states and
 * have to land all of them on the same schema:
 *
 * 1. **fresh** — no tables at all;
 * 2. **applied 1000** — tables exist, no flattening columns;
 * 3. **applied 1000 + 1001** — tables exist *with* the flattening columns;
 * 4. **applied 1003** — tables exist as that step created them, scope columns included.
 *
 * Only the first is what a developer sees locally, which is exactly why this is a test
 * and not a comment. An `addColumn` that loses its `hasColumn` guard, or a `createTable`
 * that stops checking `hasTable`, breaks the other three silently.
 *
 * The chain under test is therefore 1003, 1005 and 1006. 1003 no longer creates the
 * per-group and per-user scope columns, so a fresh install never has them; 1005 drops
 * `group_id` and 1006 drops `user_id` from every install that does. 1004 is a data-only
 * step and changes no schema, so it is absent here.
 *
 * Doctrine is not a dependency of this app — Nextcloud provides it at runtime — so the
 * schema objects here are fakes. That is enough: what is under test is the migrations'
 * branching, not Doctrine's DDL.
 */
class SchemaConvergenceTest extends TestCase {

	/**
	 * Ordered, because that is what a fresh install produces and what an upgraded one has
	 * to match: `log_delivery` is last because 1007 appends it to a table the earlier
	 * steps already built.
	 */
	private const EXPECTED_CONFIG_COLUMNS = [
		'id', 'type', 'text_template', 'image_path', 'position',
		'opacity', 'font_size', 'color', 'rotation', 'trigger', 'mime_types', 'folder_tag',
		'created_at', 'updated_at', 'log_delivery',
	];

	/**
	 * What the pre-1007 states are seeded with — every expected column *except* the one
	 * 1007 adds. Seeding `log_delivery` too would let its `hasColumn` guard skip the
	 * `addColumn` on all three upgrade paths, which is precisely the branch under test.
	 */
	private const PRE_1007_CONFIG_COLUMNS = [
		'id', 'type', 'text_template', 'image_path', 'position',
		'opacity', 'font_size', 'color', 'rotation', 'trigger', 'mime_types', 'folder_tag',
		'created_at', 'updated_at',
	];

	/**
	 * Columns an earlier version created and a later one dropped. Seeding them here is
	 * what makes the upgrade states differ from the fresh one at all.
	 */
	private const DROPPED_CONFIG_COLUMNS = ['user_id', 'group_id'];

	/** Their indexes, which have to go with them. */
	private const DROPPED_CONFIG_INDEXES = ['wm_config_user_idx', 'wm_config_group_idx'];

	/**
	 * @return array<string, array{bool, list<string>}> whether the tables already exist,
	 *                                                  and any flattening columns on them
	 */
	public static function startingStateProvider(): array {
		return [
			'fresh install' => [false, []],
			'applied 1000' => [true, []],
			'applied 1000 and 1001' => [true, ['flatten_pdf', 'flatten_dpi']],
			'applied 1003' => [true, []],
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
	): void {
		$schema = new FakeSchema();

		// A fresh install starts empty; the other two states are pre-seeded the way the
		// deleted migrations would have left them.
		if ($tablesAlreadyExist) {
			$this->preCreateTables($schema, $preexistingFlattenColumns);
		}

		$this->runMigration($schema);

		$this->assertSame(
			self::EXPECTED_CONFIG_COLUMNS,
			$schema->getTable('watermark_config')->columnNames(),
			'watermark_config did not converge on the expected columns',
		);
		$this->assertTrue($schema->hasTable('watermark_log'), 'watermark_log is missing');
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
	 * The scope columns go the same way, and their indexes have to go with them.
	 *
	 * Leaving an index behind is the failure mode worth pinning: the column drop is the
	 * visible half, and an index over a column that no longer exists is DDL the database
	 * platforms disagree about rather than anything this test could otherwise see.
	 */
	public function testScopeColumnsAndTheirIndexesAreDroppedOnUpgrade(): void {
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
	public function testDroppingTheScopeColumnsIsSkippedOnAFreshInstall(): void {
		$schema = new FakeSchema();

		$this->runMigration($schema);

		$columns = $schema->getTable('watermark_config')->columnNames();
		foreach (self::DROPPED_CONFIG_COLUMNS as $column) {
			$this->assertNotContains($column, $columns);
		}
	}

	/** Re-running must not duplicate columns or throw — the tables already exist. */
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
	 * The query plumbing is not mocked here — that would assert the shape of a
	 * QueryBuilder chain rather than any behaviour. What matters is the predicate, and
	 * that it treats a pre-validation absolute path as legacy while leaving a
	 * store-issued reference alone.
	 */
	public function testLegacyImagePathsAreDistinguishedFromValidReferences(): void {
		$valid = str_repeat('a', 32) . '.png';
		$this->assertTrue(WatermarkImageStore::isReference($valid));

		foreach ([
			'/var/www/html/core/img/logo.png',
			'/etc/passwd',
			'../../' . str_repeat('a', 32) . '.png',
			'logo.png',
		] as $legacy) {
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
	 * Every schema-changing step, in the order Nextcloud would apply them.
	 *
	 * 1006's `preSchemaChange` is driven too: it deletes the per-user rows *before* the
	 * column goes, and running the steps out of that order is the mistake worth catching.
	 */
	private function runMigration(FakeSchema $schema): void {
		$output = $this->createMock(IOutput::class);
		$closure = fn (): ISchemaWrapper => $schema;

		(new Version1003Date20260730120000($this->createMock(IDBConnection::class)))
			->changeSchema($output, $closure, []);
		(new Version1005Date20260731140000())
			->changeSchema($output, $closure, []);

		$migration1006 = new Version1006Date20260731160000($this->stubConnection());
		$migration1006->preSchemaChange($output, $closure, []);
		$migration1006->changeSchema($output, $closure, []);

		(new Version1007Date20260801120000())
			->changeSchema($output, $closure, []);
	}

	/**
	 * A connection whose query builder accepts the per-user delete and reports nothing
	 * removed. The delete's *SQL* is not what this test is about — the ordering against
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
