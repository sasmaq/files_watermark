<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops the flattened-PDF settings added by
 * {@see Version1001Date20260727000000}.
 *
 * Flattening rebuilt every watermarked page as a bitmap, which needed an external
 * rasteriser (`pdftoppm`) and therefore a process spawn. The app no longer shells
 * out to anything, so the feature is gone and these columns with it.
 *
 * Dropped rather than left in place: a stored `flatten_pdf = true` that nothing
 * reads any more is worse than no column at all, because it reads as a setting that
 * is quietly being ignored. Any admin who had it enabled loses a feature, which is
 * the point of the change, not a side effect of it.
 *
 * One-way. There is no `down()` in Nextcloud's migration model, and re-adding the
 * columns would not bring the feature back.
 */
class Version1002Date20260730000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			return null;
		}

		$table = $schema->getTable('watermark_config');
		$changed = false;

		foreach (['flatten_pdf', 'flatten_dpi'] as $column) {
			if ($table->hasColumn($column)) {
				$table->dropColumn($column);
				$changed = true;
			}
		}

		// Returning null when nothing changed keeps the migration a no-op on a fresh
		// install, where 1001 and 1002 both run and the columns never existed.
		return $changed ? $schema : null;
	}
}
