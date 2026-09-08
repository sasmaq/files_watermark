<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `flatten_pdf` / `flatten_dpi` - rebuild watermarked PDF pages as bitmaps, so the overlay
 * cannot be stripped.
 *
 * `flatten_dpi` has since been retired again by {@see Version1007Date20260908120000}: the
 * render resolution is a constant of {@see \OCA\FilesWatermark\Service\PdfFlattener}
 * rather than a setting. This step still adds it, because the chain has to keep converging
 * from every state the app has shipped, and the next one drops it.
 *
 * These two columns existed once before and were dropped by the migration that removed
 * every external-binary dependency; `Version1002Date20260804120000` still drops them, from
 * instances old enough to carry them, before this step puts them back. That is deliberate
 * rather than wasteful: the intervening squash has to keep converging every historical
 * install on one schema, and the value an instance held two versions ago is not a setting
 * anybody has consented to since - a `flatten_pdf = true` restored silently would start
 * rasterising every download on an instance whose admin never saw the control.
 *
 * **Both default to off**, and off is also what an install gets where the host has no
 * `pdftoppm`: the column is only ever read through the flattener, whose availability
 * probe decides whether the setting can be turned on at all.
 *
 * Additive and guarded like every step here; `SchemaConvergenceTest` drives the whole chain
 * from each starting state the app has shipped and asserts they all land in the same place,
 * so the `hasColumn` checks are load-bearing rather than defensive.
 */
class Version1005Date20260901120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			// Nothing to add a column to. The step that creates the table runs before this
			// one on every path Nextcloud takes, so this is the "table was dropped by hand"
			// case rather than a fresh install.
			return null;
		}

		$table = $schema->getTable('watermark_config');
		$changed = false;

		if (!$table->hasColumn('flatten_pdf')) {
			$table->addColumn('flatten_pdf', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('flatten_dpi')) {
			// A literal, not the renderer's constant. `flatten_dpi` was retired by
			// Version1007Date20260908120000, which drops it again a step later; this one
			// stays only so the chain still converges from an instance that stopped here,
			// and a historical step must not move when a live constant does.
			$table->addColumn('flatten_dpi', Types::INTEGER, [
				'notnull' => false,
				'default' => 150,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
