<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The app's whole schema, in one step, plus the two data fixes it needs.
 *
 * This replaces the previous chain (1000 created the tables, 1001 added the PDF
 * flattening columns, 1002 dropped them again). Every one of those files is gone. That
 * is safe because Nextcloud only runs migrations it has not seen and ignores rows in the
 * `migrations` table whose file no longer exists — which is how core squashes its own —
 * but it makes one demand of this file in exchange:
 *
 * **Every step here must be idempotent**, because it runs against three different
 * starting states and has to land all of them in the same place:
 *
 * 1. a fresh install, with no tables at all;
 * 2. an instance that applied 1000, so the tables exist without the flattening columns;
 * 3. an instance that applied 1000 and 1001, so the tables exist *with* them.
 *
 * `SchemaConvergenceTest` drives all three and asserts they converge. Anything added
 * here later must keep that true — an `addColumn` without a `hasColumn` guard, or a data
 * fix that is not safe to re-run, breaks only the upgrade paths, which are exactly the
 * ones nobody develops against.
 */
class Version1003Date20260730120000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->ensureConfigTable($schema);
		$this->ensureLogTable($schema);
		$this->dropFlattenColumns($schema);

		return $schema;
	}

	/**
	 * Clear `image_path` values that are not store-issued references.
	 *
	 * The upload path once stored whatever the client sent, so a config could point at
	 * any file the web server could read — the vulnerability fixed by validating in
	 * `saveConfig`. Those rows survived the fix and still *look* valid in the admin form
	 * while resolving to no image, so an admin sees a configured logo that never renders.
	 *
	 * Nulling them is the honest state: no image configured. Affected admins have to
	 * re-upload, which they would have had to do anyway.
	 *
	 * The test is {@see WatermarkImageStore::isReference()} rather than a SQL pattern, so
	 * there is exactly one definition of a valid reference and it is the one the renderers
	 * use. It also keeps this portable — the regex involved is not something MySQL,
	 * PostgreSQL and SQLite would agree on.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'image_path')
			->from('watermark_config')
			->where($select->expr()->isNotNull('image_path'));

		$result = $select->executeQuery();
		$legacy = [];
		while ($row = $result->fetch()) {
			$value = (string)$row['image_path'];
			if ($value !== '' && !WatermarkImageStore::isReference($value)) {
				$legacy[] = (int)$row['id'];
			}
		}
		$result->closeCursor();

		if ($legacy === []) {
			return;
		}

		// Chunked: an instance with thousands of stale configs would otherwise build an
		// IN() list long enough to hit a parameter or statement-length limit, and which
		// limit differs per database.
		foreach (array_chunk($legacy, 500) as $chunk) {
			$update = $this->db->getQueryBuilder();
			$update->update('watermark_config')
				->set('image_path', 'NULL')
				->where($update->expr()->in(
					'id',
					$update->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY),
				));
			$update->executeStatement();
		}

		$output->info(sprintf(
			'files_watermark: cleared %d watermark image reference(s) that predate upload validation; '
			. 'those configs now have no image and need one re-uploading.',
			count($legacy),
		));
	}

	private function ensureConfigTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('watermark_config')) {
			return;
		}

		$table = $schema->createTable('watermark_config');
		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('type', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'text',
		]);
		$table->addColumn('text_template', Types::TEXT, [
			'notnull' => false,
			'default' => null,
		]);
		// Not a path despite the name: an opaque reference issued by WatermarkImageStore,
		// resolved inside the app's own appdata folder. See postSchemaChange().
		$table->addColumn('image_path', Types::STRING, [
			'notnull' => false,
			'length' => 512,
			'default' => null,
		]);
		$table->addColumn('opacity', Types::SMALLINT, [
			'notnull' => true,
			'default' => 40,
		]);
		$table->addColumn('font_size', Types::SMALLINT, [
			'notnull' => true,
			'default' => 24,
		]);
		$table->addColumn('color', Types::STRING, [
			'notnull' => true,
			'length' => 7,
			'default' => '#808080',
		]);
		$table->addColumn('rotation', Types::SMALLINT, [
			'notnull' => true,
			'default' => 45,
		]);
		$table->addColumn('trigger', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'default' => 'on_demand',
		]);
		// Comma-separated MIME types to watermark; empty = all supported types
		$table->addColumn('mime_types', Types::TEXT, [
			'notnull' => false,
			'default' => null,
		]);
		// Nextcloud system-tag ID for per-folder targeting; NULL = apply globally
		$table->addColumn('folder_tag', Types::STRING, [
			'notnull' => false,
			'length' => 64,
			'default' => null,
		]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
	}

	private function ensureLogTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('watermark_log')) {
			return;
		}

		$table = $schema->createTable('watermark_log');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('file_path', Types::TEXT, ['notnull' => true]);
		$table->addColumn('trigger', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('config_id', Types::INTEGER, [
			'notnull' => false,
			'default' => null,
		]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id'], 'wm_log_user_idx');
		$table->addIndex(['file_id'], 'wm_log_file_idx');
		$table->addIndex(['created_at'], 'wm_log_created_idx');
	}

	/**
	 * Remove the PDF flattening settings.
	 *
	 * Flattening rasterised every watermarked page, which needed an external renderer and
	 * therefore a process spawn; the app no longer shells out to anything, so the feature
	 * is gone. Dropped rather than left behind, because a stored `flatten_pdf = true` that
	 * no code consults reads as a setting being quietly ignored.
	 *
	 * One-way: Nextcloud migrations have no `down()`, and restoring the columns would not
	 * restore the feature.
	 */
	private function dropFlattenColumns(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('watermark_config')) {
			return;
		}

		$table = $schema->getTable('watermark_config');
		foreach (['flatten_pdf', 'flatten_dpi'] as $column) {
			if ($table->hasColumn($column)) {
				$table->dropColumn($column);
			}
		}
	}
}
