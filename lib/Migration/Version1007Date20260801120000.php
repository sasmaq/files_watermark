<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `log_delivery`, the switch that makes delivery-time audit rows opt-in.
 *
 * `on_download` and `on_share` render **per fetch**, so they also logged per fetch - one
 * row per watermarked member of every archive, every time anyone downloaded it, forever,
 * with nothing pruning the table. That is the growth this column answers.
 *
 * **It governs the delivery rows only, and that distinction is the whole design.** The
 * in-place rows (`on_demand`, `on_upload`, `removed`) are not history that an admin may
 * decline to keep: they are how the app knows a file's stored bytes carry a watermark -
 * the Files-list badge and the guard against a second burn both read them. A switch that
 * turned those off would silently un-badge every file and let already-watermarked files be
 * stamped again. They are written regardless of this column.
 *
 * **It defaults to off, including on upgrade**, which is a deliberate change of behaviour
 * for an instance that already had a delivery trigger configured: delivery downloads stop
 * being recorded until an admin ticks the box in the settings page. The alternative -
 * defaulting existing rows to on - would have left every current install with the
 * unbounded growth this release exists to fix, and the setting is one checkbox away. The
 * `occ files_watermark:prune-log` command deals with what is already there.
 *
 * Guarded on `hasColumn`, so it is safe to re-run and harmless on an install that somehow
 * already has it.
 */
class Version1007Date20260801120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			return null;
		}

		$table = $schema->getTable('watermark_config');

		if ($table->hasColumn('log_delivery')) {
			return null;
		}

		$table->addColumn('log_delivery', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}
}
