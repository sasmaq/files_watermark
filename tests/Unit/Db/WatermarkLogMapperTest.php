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
    private WatermarkLogMapper        $mapper;

    protected function setUp(): void {
        parent::setUp();
        $this->db     = $this->createMock(IDBConnection::class);
        $this->mapper = new WatermarkLogMapper($this->db);
    }

    public function testFindWatermarkedFileIdsReturnsEmptyForNoInput(): void {
        $this->db->expects($this->never())->method('getQueryBuilder');

        $this->assertSame([], $this->mapper->findWatermarkedFileIds([]));
    }

    public function testFindWatermarkedFileIdsBatchesAndReturnsDistinct(): void {
        $result = $this->createMock(IResult::class);
        // Driver returns one row per matched id; distinct is enforced by SQL.
        $result->method('fetch')->willReturnOnConsecutiveCalls(
            ['file_id' => 2],
            ['file_id' => 5],
            false,
        );

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->expects($this->once())
            ->method('in')
            ->with('file_id', 'param')
            ->willReturn('file_id IN (:param)');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->expects($this->once())
            ->method('selectDistinct')
            ->with('file_id')
            ->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->expects($this->once())
            ->method('createNamedParameter')
            // Duplicates collapsed before binding; values re-indexed.
            ->with([1, 2, 5], IQueryBuilder::PARAM_INT_ARRAY)
            ->willReturn('param');
        $qb->expects($this->once())
            ->method('where')
            ->with('file_id IN (:param)')
            ->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $ids = $this->mapper->findWatermarkedFileIds([1, 2, 5, 2]);

        $this->assertSame([2, 5], $ids);
    }
}
