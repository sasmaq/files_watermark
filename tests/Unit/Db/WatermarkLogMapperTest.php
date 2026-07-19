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
        // Non-destructive delivery rows (on_download, on_share) are filtered out —
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
}
