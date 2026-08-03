<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Db;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The queries, and the type map they carry values through.
 *
 * No database: the query builder is mocked and the assertions are about the *shape* of what
 * the mapper asks for, which is where this file's failures live. Two of them are not
 * theoretical:
 *
 * - `setMaxResults(1)` on {@see WatermarkConfigMapper::findGlobal} is what keeps
 *   `findEntity()` from throwing `MultipleObjectsReturnedException` on an install that ended
 *   up with two rows — and every watermarked request goes through it;
 * - the parameter *type* each column is bound with comes from `WatermarkConfig::addType()`,
 *   so a column added without a matching `addType` is written as a string. PostgreSQL
 *   refuses an integer column bound that way; MySQL and SQLite take it, which is what makes
 *   it the kind of bug that ships.
 *
 * `insert()` and `update()` themselves are `QBMapper`'s, not this app's. What is asserted
 * about them here is the part this app supplies: which columns and which types.
 */
class WatermarkConfigMapperTest extends TestCase {

	private IDBConnection&MockObject $db;
	private WatermarkConfigMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new WatermarkConfigMapper($this->db);
	}

	/**
	 * A row as the database hands it back: every value a string, which is what makes the
	 * entity's type map the only thing standing between the column and the renderers.
	 *
	 * @return array<string, string|null>
	 */
	private function row(array $overrides = []): array {
		return array_merge([
			'id' => '7',
			'type' => 'text',
			'text_template' => '{displayname} - {date}',
			'image_path' => null,
			'opacity' => '40',
			'font_size' => '24',
			'color' => '#808080',
			'rotation' => '45',
			'trigger' => 'on_download',
			'mime_types' => null,
			'folder_tag' => null,
			'log_delivery' => '0',
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-02 00:00:00',
		], $overrides);
	}

	/**
	 * A query builder that yields `$rows` and records nothing. Returned so a test can add
	 * its own expectations before the call.
	 *
	 * @param list<array<string, string|null>> $rows
	 */
	private function queryReturning(array $rows): IQueryBuilder&MockObject {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false, false]);
		// Every read path has to release the statement, whether it found anything or not.
		$result->expects($this->atLeastOnce())->method('closeCursor');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('param');
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		return $qb;
	}

	// findGlobal --------------------------------------------------------------------------

	public function testFindGlobalReturnsTheOneRow(): void {
		$qb = $this->queryReturning([$this->row()]);
		$qb->expects($this->once())->method('from')->with('watermark_config')->willReturnSelf();

		$config = $this->mapper->findGlobal();

		$this->assertSame(7, $config->getId());
		$this->assertSame('on_download', $config->getTrigger());
	}

	/**
	 * There is meant to be exactly one row, and nothing in the schema enforces it — the
	 * column that scoped configs to a user was dropped, not replaced with a unique key. Left
	 * unbounded, `findEntity()` answers a second row with `MultipleObjectsReturnedException`,
	 * which would surface on every watermarked request rather than in the settings page.
	 */
	public function testFindGlobalAsksForOneRowOnly(): void {
		$qb = $this->queryReturning([$this->row()]);
		$qb->expects($this->once())->method('setMaxResults')->with(1)->willReturnSelf();

		$this->mapper->findGlobal();
	}

	/**
	 * A fresh install has no policy. This is the *expected* state, not a fault:
	 * `WatermarkService::resolveConfig()` catches it and returns the built-in default.
	 */
	public function testFindGlobalThrowsWhenNoPolicyIsSaved(): void {
		$this->queryReturning([]);

		$this->expectException(DoesNotExistException::class);

		$this->mapper->findGlobal();
	}

	// findById ----------------------------------------------------------------------------

	public function testFindByIdBindsTheIdAsAnInteger(): void {
		$qb = $this->queryReturning([$this->row()]);
		// Bound as a string this works on SQLite and MySQL and fails on PostgreSQL, which
		// will not compare integer to text — the classic mapper bug that only one of the
		// three supported databases reports.
		$qb->expects($this->once())
			->method('createNamedParameter')
			->with(7, IQueryBuilder::PARAM_INT)
			->willReturn('param');

		$this->assertSame(7, $this->mapper->findById(7)->getId());
	}

	public function testFindByIdThrowsForAnUnknownId(): void {
		$this->queryReturning([]);

		$this->expectException(DoesNotExistException::class);

		$this->mapper->findById(999);
	}

	// findAll -----------------------------------------------------------------------------

	public function testFindAllMapsEveryRow(): void {
		$this->queryReturning([
			$this->row(),
			$this->row(['id' => '8', 'trigger' => 'on_demand']),
		]);

		$configs = $this->mapper->findAll();

		$this->assertCount(2, $configs);
		$this->assertSame([7, 8], array_map(static fn (WatermarkConfig $c): int => $c->getId(), $configs));
	}

	public function testFindAllIsEmptyOnAFreshInstall(): void {
		$this->queryReturning([]);

		$this->assertSame([], $this->mapper->findAll());
	}

	// hasDeliveryTrigger ------------------------------------------------------------------

	/**
	 * The archive interceptor's coarse gate: one indexed lookup that answers "could this
	 * request need watermarking at all", so the `on_demand` / `on_upload` case stays off
	 * core's zip path entirely.
	 */
	public function testHasDeliveryTriggerAsksOnlyForTheDeliveryTriggers(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($this->row());
		$result->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(IExpressionBuilder::class);
		// Exactly the two triggers that render per fetch. `on_demand` and `on_upload` burn
		// into the stored bytes, so a plain archive of those already carries the watermark
		// and taking over core's path would buy nothing.
		$expr->expects($this->once())
			->method('in')
			->with('trigger', 'param')
			->willReturn('trigger IN (:param)');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		// `id`, not `*`: the row is never read, only counted, and the index alone can answer.
		$qb->expects($this->once())->method('select')->with('id')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->expects($this->once())
			->method('createNamedParameter')
			->with(['on_download', 'on_share'], IQueryBuilder::PARAM_STR_ARRAY)
			->willReturn('param');
		$qb->expects($this->once())->method('where')->with('trigger IN (:param)')->willReturnSelf();
		// Existence, not a count: this runs on every folder download.
		$qb->expects($this->once())->method('setMaxResults')->with(1)->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->assertTrue($this->mapper->hasDeliveryTrigger());
	}

	public function testHasDeliveryTriggerIsFalseWhenNoPolicyDeliversOnFetch(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		// Closed on the empty path too — this is the common case on most instances, so a
		// leaked statement here would be the one that leaks constantly.
		$result->expects($this->once())->method('closeCursor');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('param');
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->assertFalse($this->mapper->hasDeliveryTrigger());
	}

	// Types, in both directions --------------------------------------------------------------

	/**
	 * Coming out of the database every value is a string, and the API contract the settings
	 * page binds to is not: `logDelivery` has to be a JSON boolean, because `"0"` is truthy
	 * in JavaScript and would tick a checkbox on an instance that has delivery logging off.
	 *
	 * The coercion is the entity's *typed properties* rather than `addType()` — dropping the
	 * `addType` line for `logDelivery` changes nothing here, which is worth knowing before
	 * reaching for it as the fix. `addType()` governs the write path instead, pinned below.
	 */
	public function testRowValuesComeBackAsTheTypesTheAppUses(): void {
		$this->queryReturning([$this->row()]);

		$config = $this->mapper->findById(7);

		$this->assertSame(40, $config->getOpacity());
		$this->assertSame(24, $config->getFontSize());
		$this->assertSame(45, $config->getRotation());
		$this->assertFalse($config->getLogDelivery());
		// And out again in the same types, which is the shape AdminSettings.vue binds to.
		$this->assertSame(40, $config->jsonSerialize()['opacity']);
		$this->assertFalse($config->jsonSerialize()['logDelivery']);
	}

	public function testLogDeliveryTrueSurvivesTheRoundTrip(): void {
		$this->queryReturning([$this->row(['log_delivery' => '1'])]);

		$this->assertTrue($this->mapper->findById(7)->getLogDelivery());
	}

	/**
	 * The same map, on the way in. `QBMapper::insert()` asks the entity for each property's
	 * type, so a column added to `WatermarkConfig` without a matching `addType()` is bound as
	 * a string — silently, and only on the databases that tolerate it.
	 */
	public function testInsertBindsEachColumnWithTheTypeTheEntityDeclares(): void {
		$bound = [];
		$lastType = null;

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->with('watermark_config')->willReturnSelf();
		// `setValue($column, $qb->createNamedParameter($value, $type))` — the inner call runs
		// first, so the type recorded here is the one belonging to the column that follows.
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type) use (&$lastType): string {
				$lastType = $type;
				return 'param';
			},
		);
		$qb->method('setValue')->willReturnCallback(
			static function (string $column) use (&$bound, &$lastType): void {
				$bound[$column] = $lastType;
			},
		);
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn(11);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		// Every value differs from the entity's default on purpose: `Entity`'s setters mark a
		// field updated only when it *changes*, so a "save" of the defaults writes no columns
		// at all and this would assert against an empty insert.
		$config = new WatermarkConfig();
		$config->setType('combined');
		$config->setColor('#ff0000');
		$config->setOpacity(55);
		$config->setFontSize(18);
		$config->setRotation(30);
		$config->setLogDelivery(true);

		$saved = $this->mapper->insert($config);

		$this->assertSame(IQueryBuilder::PARAM_INT, $bound['opacity']);
		$this->assertSame(IQueryBuilder::PARAM_INT, $bound['font_size']);
		$this->assertSame(IQueryBuilder::PARAM_INT, $bound['rotation']);
		$this->assertSame(IQueryBuilder::PARAM_BOOL, $bound['log_delivery']);
		$this->assertSame(IQueryBuilder::PARAM_STR, $bound['type']);
		$this->assertSame(IQueryBuilder::PARAM_STR, $bound['color']);
		// The generated id lands on the entity, which is what the controller returns to the
		// settings page so the next save updates this row instead of adding a second.
		$this->assertSame(11, $saved->getId());
	}

	/**
	 * Only the fields that were set are written. The entity tracks that itself, and it is
	 * what keeps a save of the trigger alone from also rewriting every other column with
	 * whatever the defaults happen to be.
	 */
	public function testUpdateWritesOnlyTheChangedColumns(): void {
		$columns = [];

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('id = :id');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('update')->with('watermark_config')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn('param');
		$qb->method('set')->willReturnCallback(
			static function (string $column) use (&$columns): void {
				$columns[] = $column;
			},
		);
		$qb->expects($this->once())->method('where')->with('id = :id')->willReturnSelf();
		$qb->method('executeStatement')->willReturn(1);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		$config = WatermarkConfig::fromRow($this->row());
		$config->setTrigger('on_share');

		$this->mapper->update($config);

		$this->assertSame(['trigger'], $columns);
	}
}
