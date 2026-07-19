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
        // `on_download` rows are filtered out — they don't watermark stored content.
        $expr->expects($this->once())
            ->method('neq')
            ->with('trigger', 'triggerParam')
            ->willReturn('trigger <> :triggerParam');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->expects($this->once())
            ->method('selectDistinct')
            ->with('file_id')
            ->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnCallback(
            function ($value, $type = IQueryBuilder::PARAM_STR) {
                // Duplicates collapsed before binding; values re-indexed.
                if ($value === [1, 2, 5] && $type === IQueryBuilder::PARAM_INT_ARRAY) {
                    return 'param';
                }
                if ($value === 'on_download') {
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
            ->with('trigger <> :triggerParam')
            ->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);

        $ids = $this->mapper->findWatermarkedFileIds([1, 2, 5, 2]);

        $this->assertSame([2, 5], $ids);
    }
}
