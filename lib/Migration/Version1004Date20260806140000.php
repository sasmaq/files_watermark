<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `watermark_internal_shares` / `watermark_external_shares` - watermark what leaves through
 * a share, marked or not.
 *
 * Both are policy read at **delivery**, not a trigger: they widen the set of fetches that
 * produce a watermark, and they mark nothing. That is why they are two booleans on the
 * config rather than two more values in the `trigger` column - a trigger decides which files
 * carry a mark, and these decide nothing of the sort. An admin can have `on_demand` marking
 * and still have every internally shared file watermarked on its way out.
 *
 * **Both default to off, including on upgrade.** Defaulting them on would silently start
 * watermarking every shared file on an instance that never asked for it, and - because a
 * file this app cannot render is refused rather than served clean - could turn an ordinary
 * download into a 403 on upgrade day. Two ticks in the settings is the right price for that.
 *
 * Additive and guarded like every step here; `SchemaConvergenceTest` drives the whole chain
 * from each starting state the app has shipped and asserts they all land in the same place,
 * so the `hasColumn` checks are load-bearing rather than defensive.
 */
class Version1004Date20260806140000 extends SimpleMigrationStep {

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

		foreach (['watermark_internal_shares', 'watermark_external_shares'] as $column) {
			if ($table->hasColumn($column)) {
				continue;
			}

			$table->addColumn($column, Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
