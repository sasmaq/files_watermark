<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `watermark_mark` - the table that replaces the burn.
 *
 * Until now "is this file watermarked?" was answered by replaying that file's in-place
 * rows in `watermark_log` and taking the last one, because the log was doing two jobs at
 * once: an audit history *and* the app's record of which files carry a watermark. That
 * overload is why `PruneLog` could never touch an in-place row - deleting a line of
 * history would have un-badged a file - and why one boolean cost an ordered scan.
 *
 * The mark is now its own row, and the log is only history.
 *
 * **The table is created empty, deliberately.** Nothing converts the old in-place rows
 * into marks. Files burned by a previous version already carry a watermark in their bytes;
 * marking them would draw a second one over the first on every fetch, and this app does not
 * rewrite user content to tidy up after itself. They keep the watermark they have, and the
 * rows that recorded it stay in `watermark_log` as the history they always were. Any
 * instance that wants those files on the new scheme restores them by hand and re-applies.
 *
 * Additive and guarded, like every step here: `SchemaConvergenceTest` runs the chain
 * against each starting state the app has ever shipped and asserts they all converge, so a
 * `createTable` without the `hasTable` check below would break exactly the upgrade paths
 * nobody develops against.
 */
class Version1003Date20260806120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('watermark_mark')) {
			return null;
		}

		$table = $schema->createTable('watermark_mark');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		// BIGINT to match `oc_filecache.fileid`, which is what this holds.
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		// The user whose action placed the mark. Not who the watermark names - that is
		// resolved per fetch, from whoever is asking.
		$table->addColumn('marked_by', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('trigger', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('config_id', Types::INTEGER, [
			'notnull' => false,
		]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		// Unique, not merely indexed. It is what makes marking idempotent under concurrency
		// without a read-then-write - see WatermarkMarkMapper::mark(). The lookup path
		// (`file_id IN (...)`, once per folder listing) rides the same index.
		$table->addUniqueIndex(['file_id'], 'wm_mark_file_idx');

		return $schema;
	}
}
