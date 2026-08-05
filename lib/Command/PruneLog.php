<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Command;

use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retention for `watermark_log`: `occ files_watermark:prune-log`.
 *
 * The table has no expiry of its own, and a marked file records a row on every fetch when
 * `log_delivery` is on, which is what makes one necessary.
 *
 * **Every row is reachable now**, which is a change worth stating rather than leaving to be
 * discovered. This command used to be able to delete delivery rows and nothing else,
 * because the other rows were not history at all - they were how the app knew which files
 * carried a watermark, so deleting one un-badged its file and let it be stamped twice. The
 * mark is its own table now. Nothing in this log is load-bearing, so retention here deletes
 * exactly what it says and leaves no file's status behind.
 */
class PruneLog extends Command {

	private const DEFAULT_DAYS = 90;

	public function __construct(
		private WatermarkLogMapper $logMapper,
		private ITimeFactory $timeFactory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('files_watermark:prune-log')
			->setDescription('Delete old rows from the watermark audit log')
			->addOption(
				'days',
				'd',
				InputOption::VALUE_REQUIRED,
				'Delete rows older than this many days',
				(string)self::DEFAULT_DAYS,
			)
			->addOption(
				'all',
				null,
				InputOption::VALUE_NONE,
				'Delete rows of every age, ignoring --days',
			)
			->addOption(
				'dry-run',
				null,
				InputOption::VALUE_NONE,
				'Report what would be deleted and delete nothing',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$all = (bool)$input->getOption('all');
		$dryRun = (bool)$input->getOption('dry-run');
		$days = (string)$input->getOption('days');

		if (!$all) {
			// Rejected rather than coerced: `--days=abc` silently becoming 0 would delete
			// everything, which is the opposite of what a mistyped retention means.
			if (!ctype_digit($days) || (int)$days < 1) {
				$output->writeln("<error>--days must be a positive whole number, got \"$days\"</error>");
				return 1;
			}
		}

		$cutoff = $all
			? null
			: $this->timeFactory->getDateTime()
				->modify('-' . (int)$days . ' days')
				->format('Y-m-d H:i:s');

		$age = $cutoff === null ? 'any age' : "older than $cutoff";

		if ($dryRun) {
			$count = $this->logMapper->countBefore($cutoff);
			$output->writeln("Would delete <info>$count</info> audit row(s) of $age.");
			return 0;
		}

		$deleted = $this->logMapper->deleteBefore($cutoff);
		$output->writeln("Deleted <info>$deleted</info> audit row(s) of $age.");

		return 0;
	}
}
