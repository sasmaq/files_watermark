<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Db;

use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A real {@see DbException} carrying the reason the mapper branches on.
 *
 * Constructed rather than mocked: `getReason()` is the whole of what the mapper reads, and
 * a mock of an exception class cannot be thrown through code that catches by type without
 * also stubbing the parts PHPUnit needs to build one. Nextcloud's own exception wraps a
 * Doctrine one, which this app does not depend on, so the reason is supplied directly.
 */
class UniqueViolation extends DbException {
	public function __construct() {
	}

	public function getReason(): ?int {
		return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
	}
}

class ConnectionLost extends DbException {
	public function __construct() {
	}

	public function getReason(): ?int {
		return self::REASON_CONNECTION_LOST;
	}
}

class WatermarkMarkMapperTest extends TestCase {

	private IDBConnection&MockObject $db;
	private WatermarkMarkMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->mapper = new WatermarkMarkMapper($this->db);
	}

	public function testNoIdsCostsNoQuery(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->assertSame([], $this->mapper->markedFileIds([]));
	}

	/**
	 * @param array<int, array{file_id: int}> $rows
	 * @param int[] $expectedBound
	 */
	private function mockLookup(array $rows, array $expectedBound): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->expects($this->once())
			->method('in')
			->with('file_id', 'param')
			->willReturn('file_id IN (:param)');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->once())->method('select')->with('file_id')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = IQueryBuilder::PARAM_STR) use ($expectedBound) {
				// De-duplicated and re-indexed before binding.
				$this->assertSame($expectedBound, $value);
				$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $type);
				return 'param';
			},
		);
		$qb->expects($this->once())->method('where')->with('file_id IN (:param)')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	/**
	 * One query for the whole set, which is the point of the method: the caller that
	 * matters is a folder listing, and a query per row is what the batch exists to avoid.
	 */
	public function testTheLookupIsOneBatchedQuery(): void {
		$this->mockLookup([['file_id' => 2], ['file_id' => 5]], [1, 2, 5]);

		$this->assertSame([2, 5], $this->mapper->markedFileIds([1, 2, 5, 2]));
	}

	public function testAnEmptyResultMeansNothingIsMarked(): void {
		$this->mockLookup([], [7]);

		$this->assertSame([], $this->mapper->markedFileIds([7]));
	}

	/**
	 * **A collision on the unique index is success, not failure.**
	 *
	 * Two PHP workers finishing an upload of the same path is the ordinary case, not the
	 * exotic one, and the alternative - a `SELECT` then an `INSERT` - has a window between
	 * them that no amount of care in the mapper closes. Marking leans on the index instead,
	 * and reports "somebody else got there first" rather than raising: the postcondition
	 * the caller asked for holds either way.
	 */
	public function testAUniqueViolationReportsTheMarkAsAlreadyPresent(): void {
		$mapper = new class($this->db) extends WatermarkMarkMapper {
			public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
				throw new UniqueViolation();
			}
		};

		$this->assertFalse($mapper->mark(7, 'alice', 'on_upload', null));
	}

	/** Any other database failure is a real one and must not be swallowed. */
	public function testAnyOtherDatabaseFailureIsRaised(): void {
		$mapper = new class($this->db) extends WatermarkMarkMapper {
			public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
				throw new ConnectionLost();
			}
		};

		$this->expectException(DbException::class);
		$mapper->mark(7, 'alice', 'on_upload', null);
	}

	public function testMarkingReportsTrueWhenTheRowIsCreated(): void {
		$captured = null;
		$mapper = new class($this->db, $captured) extends WatermarkMarkMapper {
			public function __construct(
				IDBConnection $db,
				private mixed &$captured,
			) {
				parent::__construct($db);
			}

			public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
				$this->captured = $entity;
				return $entity;
			}
		};

		$this->assertTrue($mapper->mark(7, 'alice', 'on_demand', 3));

		$this->assertSame(7, $captured->getFileId());
		$this->assertSame('alice', $captured->getMarkedBy());
		$this->assertSame('on_demand', $captured->getTrigger());
		$this->assertSame(3, $captured->getConfigId());
		$this->assertNotSame('', $captured->getCreatedAt());
	}

	public function testUnmarkReportsWhetherARowWentAway(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('file_id = :id');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->once())->method('delete')->willReturnSelf();
		$qb->expects($this->once())->method('where')->with('file_id = :id')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':id');
		$qb->method('executeStatement')->willReturn(0);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->assertFalse($this->mapper->unmark(7));
	}
}
