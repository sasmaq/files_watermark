<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Db;

use OCA\FilesWatermark\Db\WatermarkLogMapper;
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

	/**
	 * A query-builder mock for the prune pair, recording the `WHERE` clauses it is given.
	 *
	 * The clauses are what these tests assert on, because the destructive mistake here is
	 * not a wrong row count - it is a `DELETE` that reaches rows it was never meant to.
	 *
	 * @param array<int, string> $clauses filled in with the clauses applied
	 */
	private function mockPruneQuery(array &$clauses, int $result): IQueryBuilder&MockObject {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('lt')->willReturnCallback(
			static fn (string $column, string $param): string => "$column < $param",
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('delete')->willReturnSelf();
		$qb->method('func')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class));
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value, $type = IQueryBuilder::PARAM_STR): string => ':' . (string)$value,
		);
		$qb->method('andWhere')->willReturnCallback(function (string $clause) use (&$clauses, $qb) {
			$clauses[] = $clause;
			return $qb;
		});
		$qb->method('executeStatement')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		return $qb;
	}

	public function testDeleteBeforeRestrictsToTheCutoff(): void {
		$clauses = [];
		$this->mockPruneQuery($clauses, 42);

		$this->assertSame(42, $this->mapper->deleteBefore('2026-05-03 12:00:00'));
		$this->assertSame(['created_at < :2026-05-03 12:00:00'], $clauses);
	}

	/**
	 * A null cutoff means every row, and now really does mean every row.
	 *
	 * The trigger restriction that used to survive a null cutoff is gone with the reason for
	 * it: no row in this table backs the watermarked state any more, so pruning one deletes
	 * history and nothing else. A carve-out here would be retention that quietly keeps rows
	 * an admin asked to delete.
	 */
	public function testANullCutoffMeansEveryRow(): void {
		$clauses = [];
		$this->mockPruneQuery($clauses, 900);

		$this->assertSame(900, $this->mapper->deleteBefore(null));
		$this->assertSame([], $clauses);
	}

	/** Count and delete must select the same rows, or `--dry-run` reports a different number. */
	public function testCountAndDeleteApplyTheSameRestriction(): void {
		$deleteClauses = [];
		$this->mockPruneQuery($deleteClauses, 3);
		$this->mapper->deleteBefore('2026-05-03 12:00:00');

		$countClauses = [];
		$mapper = new WatermarkLogMapper($this->db = $this->createMock(IDBConnection::class));
		$this->mockPruneQuery($countClauses, 0);
		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchOne')->willReturn(3);
		$this->db->getQueryBuilder()->method('executeQuery')->willReturn($result);

		$mapper->countBefore('2026-05-03 12:00:00');

		$this->assertSame($deleteClauses, $countClauses);
	}
}
