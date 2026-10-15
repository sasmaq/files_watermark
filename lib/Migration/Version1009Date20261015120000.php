<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `dynamic_font_size` - size the watermark text to each page or image rather than drawing it
 * at the configured `font_size` ({@see \OCA\FilesWatermark\Service\FontSize}).
 *
 * **Off by default**, so an upgrade draws every watermark exactly as it did before: the
 * column changes nothing until an admin switches it on. `font_size` is left alone either way -
 * it is what the renderers go back to when this is switched off again.
 *
 * Additive and guarded like every step here; `SchemaConvergenceTest` drives the whole chain
 * from each starting state the app has shipped and asserts they all land in the same place.
 */
class Version1009Date20261015120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			// The same "table was dropped by hand" case as Version1005Date20260901120000:
			// the step that creates the table runs first on every path Nextcloud takes.
			return null;
		}

		$table = $schema->getTable('watermark_config');
		if ($table->hasColumn('dynamic_font_size')) {
			return null;
		}

		$table->addColumn('dynamic_font_size', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}
}
