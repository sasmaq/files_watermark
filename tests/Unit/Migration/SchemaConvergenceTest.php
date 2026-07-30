<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Migration;

use OCA\FilesWatermark\Migration\Version1003Date20260730120000;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Pins the property that squashing the migration chain into one file depends on.
 *
 * `Version1003` replaced three files (1000 created the tables, 1001 added the PDF
 * flattening columns, 1002 dropped them). Nextcloud will not re-run a migration it has
 * already recorded, so the single remaining step meets instances in three different
 * states and has to land all of them on the same schema:
 *
 * 1. **fresh** — no tables at all;
 * 2. **applied 1000** — tables exist, no flattening columns;
 * 3. **applied 1000 + 1001** — tables exist *with* the flattening columns.
 *
 * Only the first is what a developer sees locally, which is exactly why this is a test
 * and not a comment. An `addColumn` that loses its `hasColumn` guard, or a `createTable`
 * that stops checking `hasTable`, breaks the other two silently.
 *
 * Doctrine is not a dependency of this app — Nextcloud provides it at runtime — so the
 * schema objects here are fakes. That is enough: what is under test is the migration's
 * branching, not Doctrine's DDL.
 */
class SchemaConvergenceTest extends TestCase {

	private const EXPECTED_CONFIG_COLUMNS = [
		'id', 'user_id', 'group_id', 'type', 'text_template', 'image_path', 'position',
		'opacity', 'font_size', 'color', 'rotation', 'trigger', 'mime_types', 'folder_tag',
		'created_at', 'updated_at',
	];

	/**
	 * @return array<string, array{bool, list<string>}> whether the tables already exist,
	 *                                                  and any flattening columns on them
	 */
	public static function startingStateProvider(): array {
		return [
			'fresh install' => [false, []],
			'applied 1000' => [true, []],
			'applied 1000 and 1001' => [true, ['flatten_pdf', 'flatten_dpi']],
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
		foreach (self::EXPECTED_CONFIG_COLUMNS as $column) {
			$config->addColumn($column, 'string');
		}
		foreach ($flattenColumns as $column) {
			$config->addColumn($column, 'boolean');
		}
		$schema->createTable('watermark_log')->addColumn('id', 'bigint');
	}

	private function runMigration(FakeSchema $schema): void {
		$migration = new Version1003Date20260730120000($this->createMock(IDBConnection::class));
		$migration->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $schema,
			[],
		);
	}
}
