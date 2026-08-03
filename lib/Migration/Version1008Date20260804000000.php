<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop `position`, the last column nothing read.
 *
 * `saveConfig` accepted it, the entity carried it and the mapper stored it, but no renderer
 * ever consulted it: both `PdfWatermarker` and `ImageWatermarker` tile the whole page from
 * `TileLattice::positions()`, and placement is decided by `rotation` and `font_size` alone.
 * Every row in the wild holds the string `diagonal`, because that was the default and no UI
 * offered another value.
 *
 * Dropped rather than implemented, on the same reasoning as `group_id` in
 * {@see Version1005Date20260731140000}: a stored setting no code consults is a setting being
 * quietly ignored, and it invites an admin to conclude a placement they picked is in force.
 * Implementing corner placement is a feature with its own geometry, tests and UI; it does not
 * begin with a column that has been carrying `diagonal` since 1000.
 *
 * One-way, as all Nextcloud migrations are. Nothing is lost: the only value it held is the
 * one the renderers do unconditionally.
 *
 * Guarded on `hasColumn`, so a fresh install - where 1003 no longer creates the column -
 * passes through untouched.
 */
class Version1008Date20260804000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			return null;
		}

		$table = $schema->getTable('watermark_config');

		if (!$table->hasColumn('position')) {
			return null;
		}

		$table->dropColumn('position');

		return $schema;
	}
}
