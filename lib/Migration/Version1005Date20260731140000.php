<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop `group_id`, and with it the group-override feature.
 *
 * The column was accepted by `saveConfig`, validated, indexed and stored - and never read
 * by anything. `WatermarkService` resolved user → global → default, the mapper had no
 * finder for it, and no UI ever set it. A group policy therefore did nothing at all while
 * looking like a supported feature, which is worse than not offering it: an admin who
 * managed to store one would see it persisted and conclude it was in force.
 *
 * Dropped rather than implemented, on the same reasoning that removed the flattening
 * columns in {@see Version1003Date20260730120000}: a stored setting no code consults is a
 * setting being quietly ignored. Precedence is now user → global → default, stated in one
 * place and true.
 *
 * One-way, as all Nextcloud migrations are - there is no `down()`, and restoring the
 * column would not restore a feature that was never built. Any values it held are lost,
 * which costs nothing: they were inert.
 *
 * Guarded on `hasColumn`, so a fresh install - where 1003 no longer creates the column at
 * all - passes through untouched.
 */
class Version1005Date20260731140000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			return null;
		}

		$table = $schema->getTable('watermark_config');

		if (!$table->hasColumn('group_id')) {
			return null;
		}

		// The index goes first. A column dropped out from under an index leaves the index
		// referencing nothing, which the platforms disagree about: MySQL quietly rewrites
		// it, PostgreSQL drops it, and Doctrine's own diff can emit DDL neither accepts.
		if ($table->hasIndex('wm_config_group_idx')) {
			$table->dropIndex('wm_config_group_idx');
		}

		$table->dropColumn('group_id');

		return $schema;
	}
}
