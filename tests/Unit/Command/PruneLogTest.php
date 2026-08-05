<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Command;

use OCA\FilesWatermark\Command\PruneLog;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\FilesWatermark\Command\PruneLog
 */
class PruneLogTest extends TestCase {

	private WatermarkLogMapper&MockObject $logMapper;
	private CommandTester $command;

	protected function setUp(): void {
		parent::setUp();

		$this->logMapper = $this->createMock(WatermarkLogMapper::class);

		// Frozen, so the cutoff a given `--days` produces is an assertable string rather
		// than whatever the clock said while the suite ran.
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			static fn (): \DateTime => new \DateTime('2026-08-01 12:00:00'),
		);

		$this->command = new CommandTester(new PruneLog($this->logMapper, $time));
	}

	public function testDefaultsToNinetyDays(): void {
		$this->logMapper->expects($this->once())
			->method('deleteBefore')
			->with('2026-05-03 12:00:00')
			->willReturn(12);

		$this->assertSame(0, $this->command->execute([]));
		$this->assertStringContainsString('Deleted 12 audit row(s)', $this->command->getDisplay());
	}

	public function testDaysMovesTheCutoff(): void {
		$this->logMapper->expects($this->once())
			->method('deleteBefore')
			->with('2026-07-25 12:00:00')
			->willReturn(3);

		$this->assertSame(0, $this->command->execute(['--days' => '7']));
	}

	/**
	 * A mistyped retention must not read as "delete everything". Coercing `abc` to 0
	 * would make `--days=abc` the most destructive form of the command.
	 *
	 * @dataProvider badDaysProvider
	 */
	public function testARetentionThatIsNotAPositiveNumberIsRefused(string $days): void {
		$this->logMapper->expects($this->never())->method('deleteBefore');

		$this->assertSame(1, $this->command->execute(['--days' => $days]));
		$this->assertStringContainsString('--days must be a positive whole number', $this->command->getDisplay());
	}

	/** @return array<string, array{string}> */
	public static function badDaysProvider(): array {
		return [
			'not a number' => ['abc'],
			'zero' => ['0'],
			'negative' => ['-5'],
			'fractional' => ['1.5'],
		];
	}

	public function testAllDropsTheAgeFilterEntirely(): void {
		$this->logMapper->expects($this->once())
			->method('deleteBefore')
			->with(null)
			->willReturn(400);

		$this->assertSame(0, $this->command->execute(['--all' => true]));
		$this->assertStringContainsString('any age', $this->command->getDisplay());
	}

	/** `--all` wins over `--days`, rather than the two quietly disagreeing. */
	public function testAllOverridesDaysAndIsNotRefusedByItsValidation(): void {
		$this->logMapper->expects($this->once())
			->method('deleteBefore')
			->with(null)
			->willReturn(1);

		$this->assertSame(0, $this->command->execute(['--all' => true, '--days' => 'nonsense']));
	}

	public function testDryRunCountsAndDeletesNothing(): void {
		$this->logMapper->expects($this->once())
			->method('countBefore')
			->with('2026-05-03 12:00:00')
			->willReturn(87);
		$this->logMapper->expects($this->never())->method('deleteBefore');

		$this->assertSame(0, $this->command->execute(['--dry-run' => true]));
		$this->assertStringContainsString('Would delete 87 audit row(s)', $this->command->getDisplay());
	}

	/**
	 * There is still no flag for a wider scope, for a different reason than there used to be.
	 *
	 * The carve-out this command was built around is gone: no row in `watermark_log` decides
	 * whether a file is watermarked any more, so every row is now deletable and `--days`
	 * plus `--all` already express the whole of what retention means. `--include-applied`
	 * has nothing left to include.
	 */
	public function testThereIsNoScopeOption(): void {
		$this->logMapper->expects($this->never())->method('deleteBefore');

		$this->expectException(InvalidOptionException::class);
		$this->expectExceptionMessage('The "--include-applied" option does not exist.');
		$this->command->execute(['--include-applied' => true]);
	}

	public function testEveryRunSaysWhatAgeItTook(): void {
		// The cutoff is stated on every run, so an admin reading the output never has to
		// wonder whether this one took more than the last.
		$this->logMapper->method('deleteBefore')->willReturn(9);

		$this->command->execute([]);

		$this->assertStringContainsString('older than 2026-05-03 12:00:00', $this->command->getDisplay());
	}
}
