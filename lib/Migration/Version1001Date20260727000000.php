<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the flattened-PDF settings.
 *
 * `flatten_pdf` defaults to false, and deliberately so: rasterising destroys the
 * text layer, which takes selection, copy/paste, search and screen-reader access
 * with it. That is a possible WCAG / EN 301 549 problem for a document-management
 * product, so it is never on unless an admin asks for it.
 */
class Version1001Date20260727000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			return null;
		}

		$table = $schema->getTable('watermark_config');

		if (!$table->hasColumn('flatten_pdf')) {
			$table->addColumn('flatten_pdf', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
		}

		if (!$table->hasColumn('flatten_dpi')) {
			$table->addColumn('flatten_dpi', Types::SMALLINT, [
				'notnull' => true,
				'default' => 150,
			]);
		}

		return $schema;
	}
}
