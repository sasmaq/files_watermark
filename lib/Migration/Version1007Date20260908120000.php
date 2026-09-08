<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop `flatten_dpi` - the render resolution is no longer a setting.
 *
 * Flattening itself stays. What goes is the choice of resolution, which was a slider from
 * 72 to 600 DPI and is now the constant
 * {@see \OCA\FilesWatermark\Service\PdfFlattener::RENDER_DPI}. The range was the problem:
 * its low end produced pages no admin would have accepted had they seen one, and its high
 * end multiplied the cost of a feature that already rebuilds every page on every fetch.
 * 150 - the default every install had, and what the help text recommended - is what every
 * flatten now uses.
 *
 * **No data is migrated, because there is nothing to migrate to.** The column held a
 * number that no longer has anywhere to be read; an instance that had raised it to 300
 * renders at 150 from the upgrade on, which is the point of removing the choice rather
 * than a loss of one.
 *
 * One-way, like every other step here - Nextcloud migrations have no `down()`. Restoring
 * the column would not restore the feature, since nothing reads it any more.
 *
 * `Version1005Date20260901120000` still *adds* this column, one step earlier, and that is
 * deliberate rather than wasteful for the same reason it was when `Version1002` dropped
 * the pair and `Version1005` put them back: the chain has to converge on one schema from
 * every state the app has shipped, and `SchemaConvergenceTest` drives it from each of them
 * to prove it does. The guard below is load-bearing on the paths where the column was
 * never created.
 */
class Version1007Date20260908120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			// The step that creates the table runs before this one on every path Nextcloud
			// takes, so this is the "table was dropped by hand" case rather than a fresh
			// install.
			return null;
		}

		$table = $schema->getTable('watermark_config');
		if (!$table->hasColumn('flatten_dpi')) {
			return null;
		}

		$table->dropColumn('flatten_dpi');

		return $schema;
	}
}
