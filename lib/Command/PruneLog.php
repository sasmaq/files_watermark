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
 * The table has no expiry of its own, and delivery triggers render - and so record - per
 * fetch, which is what makes one necessary. Since 1.6.0 delivery rows are only written
 * when the policy asks for them, but an instance that has been running with them on has
 * the backlog either way.
 *
 * **Delivery rows only, with no way to ask for more.** The in-place rows (`on_demand`,
 * `on_upload`, `removed`) are not history: they are how the app knows a file's stored bytes
 * carry a watermark. Deleting one un-badges its file in the Files list *and* clears the
 * guard that stops it being stamped a second time - so the badge is never something a
 * retention command can take away. An earlier draft offered `--include-applied` for it,
 * which was the wrong shape: a flag whose help text has to warn you not to use it is a flag
 * that should not exist. `WatermarkLogMapper::deleteBefore()` cannot reach those rows at all
 * now, so this holds for any future caller too.
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

		$scope = 'delivery rows only (on_download, on_share)';
		$age = $cutoff === null ? 'any age' : "older than $cutoff";

		if ($dryRun) {
			$count = $this->logMapper->countBefore($cutoff);
			$output->writeln("Would delete <info>$count</info> row(s): $scope, $age.");
			return 0;
		}

		$deleted = $this->logMapper->deleteBefore($cutoff);
		$output->writeln("Deleted <info>$deleted</info> row(s): $scope, $age.");

		return 0;
	}
}
