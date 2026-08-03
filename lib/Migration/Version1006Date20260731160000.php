<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop `user_id`, and with it per-user watermark policies.
 *
 * The watermark policy is now server-wide: one config, set by an administrator, in force
 * for every user, every trigger and every access path. `WatermarkService::resolveConfig()`
 * no longer takes a user id at all, so there is nothing left to key a row by.
 *
 * Unlike {@see Version1005Date20260731140000}, which dropped a column nothing ever read,
 * **this one deletes data that was live**. Per-user rows really did override the global
 * policy, so any user who had one now falls back to it. That is the point of the change,
 * but it is a change in what those users' files get watermarked with, not a no-op.
 *
 * **The delete has to happen before the column goes, which is why it is in
 * `preSchemaChange` rather than the usual `postSchemaChange`.** Dropping `user_id` first
 * would leave the per-user rows in the table as ordinary rows, indistinguishable from the
 * global one - and `findGlobal()` takes the first row it finds, so a former per-user
 * override could silently become the server-wide policy. Deleting first is what makes the
 * outcome deterministic.
 *
 * One-way, as all Nextcloud migrations are.
 */
class Version1006Date20260731160000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Guarded both ways: a fresh install has no table yet, and an instance that has
		// already run this step has no column. Neither should error.
		if (
			!$schema->hasTable('watermark_config')
			|| !$schema->getTable('watermark_config')->hasColumn('user_id')
		) {
			return;
		}

		$delete = $this->db->getQueryBuilder();
		$delete->delete('watermark_config')
			->where($delete->expr()->isNotNull('user_id'));
		$removed = $delete->executeStatement();

		if ($removed > 0) {
			$output->info(sprintf(
				'files_watermark: removed %d per-user watermark policy/policies; those users now '
					. 'follow the global policy, which is the only one the app has.',
				$removed,
			));
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			return null;
		}

		$table = $schema->getTable('watermark_config');

		if (!$table->hasColumn('user_id')) {
			return null;
		}

		// Index first - see the note in Version1005: a column pulled out from under a live
		// index is DDL the database platforms disagree about.
		if ($table->hasIndex('wm_config_user_idx')) {
			$table->dropIndex('wm_config_user_idx');
		}

		$table->dropColumn('user_id');

		return $schema;
	}
}
