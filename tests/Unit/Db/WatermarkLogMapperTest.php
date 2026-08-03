<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Db;

use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WatermarkLogMapperTest extends TestCase {

	private IDBConnection&MockObject $db;
	private WatermarkLogMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new WatermarkLogMapper($this->db);
	}

	public function testFindWatermarkedFileIdsReturnsEmptyForNoInput(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->assertSame([], $this->mapper->findWatermarkedFileIds([]));
	}

	/**
	 * Wire up a query-builder mock that yields $rows, asserting the query shape:
	 * batched `IN (...)`, non-destructive triggers excluded, ordered by insertion.
	 *
	 * @param array<int, array{file_id: int, trigger: string}> $rows
	 * @param int[] $expectedIds the de-duplicated ids expected to be bound
	 */
	private function mockQuery(array $rows, array $expectedIds): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->expects($this->once())
			->method('in')
			->with('file_id', 'param')
			->willReturn('file_id IN (:param)');
		// Non-destructive delivery rows (on_download, on_share) are filtered out -
		// they stream a copy and never watermark stored content. `removed` is *not*
		// filtered: it is an in-place event that cancels an earlier apply.
		$expr->expects($this->once())
			->method('notIn')
			->with('trigger', 'triggerParam')
			->willReturn('trigger NOT IN (:triggerParam)');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->once())
			->method('select')
			->with('file_id', 'trigger')
			->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = IQueryBuilder::PARAM_STR) use ($expectedIds) {
				// Duplicates collapsed before binding; values re-indexed.
				if ($value === $expectedIds && $type === IQueryBuilder::PARAM_INT_ARRAY) {
					return 'param';
				}
				if ($value === ['on_download', 'on_share'] && $type === IQueryBuilder::PARAM_STR_ARRAY) {
					return 'triggerParam';
				}
				$this->fail('unexpected createNamedParameter argument');
			},
		);
		$qb->expects($this->once())
			->method('where')
			->with('file_id IN (:param)')
			->willReturnSelf();
		$qb->expects($this->once())
			->method('andWhere')
			->with('trigger NOT IN (:triggerParam)')
			->willReturnSelf();
		// The most recent row per file decides its status, so ordering is load-bearing.
		$qb->expects($this->once())
			->method('orderBy')
			->with('id', 'ASC')
			->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	public function testFindWatermarkedFileIdsBatchesAndReturnsDistinct(): void {
		$this->mockQuery(
			[
				['file_id' => 2, 'trigger' => 'on_demand'],
				['file_id' => 5, 'trigger' => 'on_upload'],
			],
			[1, 2, 5],
		);

		$this->assertSame([2, 5], $this->mapper->findWatermarkedFileIds([1, 2, 5, 2]));
	}

	public function testRemovalCancelsAnEarlierWatermark(): void {
		// apply → removed: the watermark is gone from the stored content, so the file
		// must stop reporting as watermarked (and become re-appliable).
		$this->mockQuery(
			[
				['file_id' => 2, 'trigger' => 'on_demand'],
				['file_id' => 5, 'trigger' => 'on_demand'],
				['file_id' => 5, 'trigger' => 'removed'],
			],
			[2, 5],
		);

		$this->assertSame([2], $this->mapper->findWatermarkedFileIds([2, 5]));
	}

	public function testAReplacementCancelsAnEarlierWatermark(): void {
		// A user's own write over a watermarked file leaves bytes that carry no
		// watermark. Keyed by file id alone the row would outlive the content it
		// describes, which is what let a second upload land clean and still badged.
		$this->mockQuery(
			[
				['file_id' => 2, 'trigger' => 'on_upload'],
				['file_id' => 5, 'trigger' => 'on_upload'],
				['file_id' => 5, 'trigger' => 'replaced'],
			],
			[2, 5],
		);

		$this->assertSame([2], $this->mapper->findWatermarkedFileIds([2, 5]));
	}

	public function testAWatermarkAfterAReplacementCountsAgain(): void {
		// upload → replaced → upload: the overwrite is watermarked in its turn, and the
		// file is watermarked again. Only the last event counts.
		$this->mockQuery(
			[
				['file_id' => 7, 'trigger' => 'on_upload'],
				['file_id' => 7, 'trigger' => 'replaced'],
				['file_id' => 7, 'trigger' => 'on_upload'],
			],
			[7],
		);

		$this->assertSame([7], $this->mapper->findWatermarkedFileIds([7]));
	}

	public function testReapplyAfterRemovalCountsAsWatermarkedAgain(): void {
		// apply → removed → apply: only the last event counts.
		$this->mockQuery(
			[
				['file_id' => 7, 'trigger' => 'on_demand'],
				['file_id' => 7, 'trigger' => 'removed'],
				['file_id' => 7, 'trigger' => 'on_demand'],
			],
			[7],
		);

		$this->assertSame([7], $this->mapper->findWatermarkedFileIds([7]));
	}

	// ---------------------------------------------------------------------
	// Pruning
	// ---------------------------------------------------------------------

	/**
	 * A query-builder mock for the prune pair, recording the `WHERE` clauses it is given.
	 *
	 * The clauses are what the tests assert on, because the destructive mistake here is
	 * not a wrong row count - it is a `DELETE` that reaches rows it was never meant to.
	 *
	 * @param array<string, string> $clauses filled in with the clauses applied
	 */
	private function mockPruneQuery(array &$clauses, int $result): IQueryBuilder&MockObject {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('lt')->willReturnCallback(
			static fn (string $column, string $param): string => "$column < $param",
		);
		$expr->method('in')->willReturnCallback(
			static fn (string $column, string $param): string => "$column IN $param",
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('delete')->willReturnSelf();
		$qb->method('func')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class));
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type = IQueryBuilder::PARAM_STR): string {
				return is_array($value) ? '(:triggers)' : ':' . (string)$value;
			},
		);
		$qb->method('andWhere')->willReturnCallback(function (string $clause) use (&$clauses, $qb) {
			$clauses[] = $clause;
			return $qb;
		});
		$qb->method('executeStatement')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		return $qb;
	}

	public function testDeleteBeforeRestrictsToTheCutoffAndToDeliveryRows(): void {
		$clauses = [];
		$this->mockPruneQuery($clauses, 42);

		$this->assertSame(42, $this->mapper->deleteBefore('2026-05-03 12:00:00'));
		$this->assertSame([
			'created_at < :2026-05-03 12:00:00',
			'trigger IN (:triggers)',
		], $clauses);
	}

	public function testANullCutoffMeansEveryAgeRatherThanNoRows(): void {
		$clauses = [];
		$this->mockPruneQuery($clauses, 900);

		$this->assertSame(900, $this->mapper->deleteBefore(null));
		// Still restricted by trigger: "every age" is not "every row".
		$this->assertSame(['trigger IN (:triggers)'], $clauses);
	}

	/**
	 * The in-place rows cannot be deleted from here **at all** - no parameter, no default
	 * to get wrong. They are what the Files-list badge and the double-burn guard read, so
	 * a caller that could reach them could make the app forget a file it has stamped.
	 */
	public function testTheTriggerRestrictionIsNotOptional(): void {
		$this->assertSame(
			1,
			(new \ReflectionMethod($this->mapper, 'deleteBefore'))->getNumberOfParameters(),
			'deleteBefore grew a parameter - the only one it may take is the cutoff',
		);
		$this->assertSame(
			1,
			(new \ReflectionMethod($this->mapper, 'countBefore'))->getNumberOfParameters(),
		);

		// And the clause is emitted whatever the cutoff.
		foreach (['2026-05-03 12:00:00', null] as $cutoff) {
			$clauses = [];
			$this->mockPruneQuery($clauses, 1);
			$this->mapper->deleteBefore($cutoff);
			$this->assertContains('trigger IN (:triggers)', $clauses);
		}
	}
}
