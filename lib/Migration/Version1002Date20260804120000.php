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
 * The app's whole schema and every data fix it needs, in one step.
 *
 * This replaces the entire previous chain - 1003 (which was itself a squash of 1000-1002),
 * 1004, 1005, 1006, 1007 and 1008. All six files are gone. That is safe because Nextcloud
 * only runs migrations it has not seen, and ignores rows in the `migrations` table whose
 * class no longer exists - which is how core squashes its own. The recorded version string
 * is derived from the class name, so this file is a name no instance has recorded and it
 * runs exactly once wherever it lands.
 *
 * **That is also what makes idempotency non-negotiable here**, and more so than it was for
 * 1003: this step runs against instances that already have the finished schema. It meets
 * five starting states and has to land all of them in the same place:
 *
 * 1. a fresh install, with no tables at all;
 * 2. applied 1000 - tables exist, no flattening columns, scope columns present;
 * 3. applied 1000 + 1001 - as above, *with* the flattening columns;
 * 4. applied 1003 - tables as that step created them, scope columns and `position` present;
 * 5. applied 1003 through 1008 - the current schema, nothing left to do.
 *
 * `SchemaConvergenceTest` drives all five and asserts they converge. Anything added here
 * later must keep that true - an `addColumn` without a `hasColumn` guard, or a data fix
 * that is not safe to re-run, breaks only the upgrade paths, which are exactly the ones
 * nobody develops against.
 *
 * ---------------------------------------------------------------------------
 * THE ONE STEP THAT IS NOT NATURALLY IDEMPOTENT, AND HOW IT IS GATED.
 *
 * The `{username}` → `{displayname}` rewrite (formerly 1004) must not run twice. It exists
 * because the token changed meaning - it used to resolve to the display name and now
 * resolves to the uid - so rewriting preserved what every stored template rendered. Run a
 * second time, it would rewrite a `{username}` an admin typed *deliberately* after that
 * change, silently turning an account name back into a display name. 1004 could rely on
 * Nextcloud never re-running it. This file cannot: it runs on instances that already
 * applied 1004.
 *
 * So it is gated on `log_delivery`, the last column the old chain added (1007). An instance
 * that has it got at least as far as 1007, therefore ran 1004, therefore must not be
 * rewritten again. The check has to happen in `preSchemaChange`, before `changeSchema()`
 * adds that very column - hence {@see $rewriteLegacyToken}, set there and read in
 * `postSchemaChange`.
 *
 * **The gate is a proxy, not a proof, and the residual window is stated rather than
 * hidden:** an instance that stopped between 1004 and 1007 has already been rewritten and
 * has no `log_delivery`, so it would be rewritten again. Nothing in the database
 * distinguishes a token stored before the meaning changed from one typed after it. The
 * window is every install that upgraded to exactly 1.4.0-1.6.0 and then typed a fresh
 * `{username}`, which for an app that has not been released yet is empty.
 * ---------------------------------------------------------------------------
 */
class Version1002Date20260804120000 extends SimpleMigrationStep {

	/**
	 * Whether this instance predates `log_delivery` and so still needs the token rewrite.
	 *
	 * Decided in `preSchemaChange` because `changeSchema` is what adds the column the
	 * decision reads - see the class docblock. Defaults to false so that a run which
	 * somehow skips `preSchemaChange` does nothing rather than rewriting blind.
	 */
	private bool $rewriteLegacyToken = false;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Two things that have to happen while the old schema is still standing.
	 *
	 * The per-user delete is the one with teeth: dropping `user_id` first would leave those
	 * rows in the table as ordinary ones, indistinguishable from the global config - and
	 * `findGlobal()` takes the first row it finds, so a former per-user override could
	 * silently become the server-wide policy. Deleting first is what makes the outcome
	 * deterministic.
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_config')) {
			// A fresh install: no rows to delete, and no stored templates to rewrite.
			return;
		}

		$table = $schema->getTable('watermark_config');
		$this->rewriteLegacyToken = !$table->hasColumn('log_delivery');

		if (!$table->hasColumn('user_id')) {
			return;
		}

		$delete = $this->db->getQueryBuilder();
		$delete->delete('watermark_config')
			->where($delete->expr()->isNotNull('user_id'));
		$removed = $delete->executeStatement();

		if ($removed > 0) {
			$output->info(sprintf(
				'files_watermark: removed %d per-user watermark policy/policies; those users now '
					. 'follow the global policy, which is the only one the app has.',
				$removed,
			));
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->ensureConfigTable($schema);
		$this->ensureLogTable($schema);
		$this->dropRetiredColumns($schema);
		$this->ensureLogDeliveryColumn($schema);

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->clearLegacyImagePaths($output);

		if ($this->rewriteLegacyToken) {
			$this->rewriteUsernameToken($output);
		}
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
		// resolved inside the app's own appdata folder. See clearLegacyImagePaths().
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
			'default' => '#d3d3d3',
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
		// Delivery audit rows are on by default; see ensureLogDeliveryColumn().
		$table->addColumn('log_delivery', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
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
	 * Every column an earlier version created and a later one retired.
	 *
	 *  - `flatten_pdf` / `flatten_dpi` - PDF flattening rasterised every watermarked page,
	 *    which needed an external renderer and therefore a process spawn. The app no longer
	 *    shells out to anything.
	 *  - `group_id` - accepted, validated, indexed and stored, and never read by anything.
	 *    A group policy did nothing at all while looking like a supported feature.
	 *  - `user_id` - per-user policies, genuinely read but reachable only by `curl`. The
	 *    policy is now one config, set by an admin, server-wide. Its rows are deleted in
	 *    `preSchemaChange`, above.
	 *  - `position` - accepted, stored, and never consulted by a renderer: both watermarkers
	 *    tile the whole page from `TileLattice::positions()`. Every row in the wild holds
	 *    `diagonal`, the only value any UI offered.
	 *
	 * All one-way. Nextcloud migrations have no `down()`, and restoring any of these
	 * columns would not restore a feature - three of the four never had one.
	 *
	 * **Indexes go before the columns they cover.** A column dropped out from under a live
	 * index leaves the index referencing nothing, which the platforms disagree about: MySQL
	 * quietly rewrites it, PostgreSQL drops it, and Doctrine's own diff can emit DDL neither
	 * accepts.
	 */
	private function dropRetiredColumns(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('watermark_config')) {
			return;
		}

		$table = $schema->getTable('watermark_config');

		foreach (['wm_config_group_idx', 'wm_config_user_idx'] as $index) {
			if ($table->hasIndex($index)) {
				$table->dropIndex($index);
			}
		}

		foreach (['flatten_pdf', 'flatten_dpi', 'group_id', 'user_id', 'position'] as $column) {
			if ($table->hasColumn($column)) {
				$table->dropColumn($column);
			}
		}
	}

	/**
	 * Add `log_delivery`, the switch that decides whether a delivery writes an audit row.
	 *
	 * **It governs the delivery rows only.** The mark/unmark rows are not history an admin
	 * may decline to keep - they are how the app knows a file carries a mark, which the
	 * Files-list badge reads - and they are written regardless of this column.
	 *
	 * ---------------------------------------------------------------------------
	 * IT DEFAULTS TO **ON**, AND IT DID NOT ALWAYS.
	 *
	 * The column was introduced opt-out at a time when two of the four triggers
	 * (`on_demand`, `on_upload`) burned the watermark into the file and produced no delivery
	 * rows at all. An install with a delivery trigger was the minority case, the rows were an
	 * extra, and the argument for off was unbounded growth: a row per member of every
	 * archive, every time anyone downloaded it, forever.
	 *
	 * The [trigger rework](../../doc/development.md) removed that asymmetry. **Every** marked
	 * file now renders on every fetch, whichever trigger marked it, so *"who received a copy
	 * of this document"* is a question every install can answer and the default decides
	 * whether it does. A watermark exists to trace a leaked document back to the person who
	 * received it; an install that records the policy and not the deliveries has the audit
	 * trail for the half nobody needs to reconstruct.
	 *
	 * The growth argument did not go away, it got a bound and a tool: `archive_max_members`
	 * caps the rows one request can write, and `occ files_watermark:prune-log --days` is the
	 * retention. Previews render per viewer and write **no** rows - that volume would be per
	 * thumbnail per person, which is the case where the argument still holds.
	 * ---------------------------------------------------------------------------
	 *
	 * **An instance that already has the column keeps whatever is stored in it**, on or off.
	 * This is a default, and a default only decides what happens in the absence of a choice;
	 * rewriting rows would overwrite admins who ticked the box off on purpose, and there is
	 * no way to tell those apart from the ones who never opened the page. The release note
	 * says to tick it, which is the honest version of the same thing.
	 *
	 * Separate from `ensureConfigTable()` rather than folded into it, because the two answer
	 * different states: a fresh install gets the column with the table, and every upgrade
	 * path gets it added to a table that already exists. **Both must declare the same
	 * default** or `SchemaConvergenceTest` fails - which is the point of that test.
	 */
	private function ensureLogDeliveryColumn(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('watermark_config')) {
			return;
		}

		$table = $schema->getTable('watermark_config');
		if ($table->hasColumn('log_delivery')) {
			return;
		}

		// True, so an instance old enough to predate the column gets delivery logging back:
		// before the switch existed, every delivery was recorded unconditionally.
		$table->addColumn('log_delivery', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
	}

	/**
	 * Clear `image_path` values that are not store-issued references.
	 *
	 * The upload path once stored whatever the client sent, so a config could point at any
	 * file the web server could read - the vulnerability fixed by validating in
	 * `saveConfig`. Those rows survived the fix and still *look* valid in the admin form
	 * while resolving to no image, so an admin sees a configured logo that never renders.
	 *
	 * Nulling them is the honest state: no image configured. Affected admins have to
	 * re-upload, which they would have had to do anyway.
	 *
	 * The test is {@see WatermarkImageStore::isReference()} rather than a SQL pattern, so
	 * there is exactly one definition of a valid reference and it is the one the renderers
	 * use. It also keeps this portable - the regex involved is not something MySQL,
	 * PostgreSQL and SQLite would agree on.
	 *
	 * Unguarded, unlike the token rewrite: re-running it finds nothing, because every value
	 * `saveConfig` has been able to store since the fix already passes `isReference()`.
	 */
	private function clearLegacyImagePaths(IOutput $output): void {
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

	/**
	 * Rewrite stored `{username}` tokens to `{displayname}`, preserving what they rendered.
	 *
	 * `{username}` used to resolve to the *display* name - `$user->getDisplayName()` - which
	 * left the account name unreachable and the token misleading. It now resolves to the
	 * uid, and `{displayname}` carries the human-readable name.
	 *
	 * That is a change of meaning for text an admin already saved, so without this every
	 * existing watermark would quietly switch from "Alice Smith" to "asmith3" on upgrade,
	 * with nothing on screen to explain why. Rewriting the token instead keeps the rendered
	 * output **byte-identical** and makes the account name an opt-in choice.
	 *
	 * **Called only when `preSchemaChange` found no `log_delivery` column** - see the class
	 * docblock. Making this unconditional is the one change to this file that breaks an
	 * instance rather than a test.
	 */
	private function rewriteUsernameToken(IOutput $output): void {
		$legacyToken = '{username}';
		$replacement = '{displayname}';

		$select = $this->db->getQueryBuilder();
		$select->select('id', 'text_template')
			->from('watermark_config')
			->where($select->expr()->isNotNull('text_template'));

		$result = $select->executeQuery();
		$rewrites = [];
		while ($row = $result->fetch()) {
			$template = (string)$row['text_template'];
			if (str_contains($template, $legacyToken)) {
				$rewrites[(int)$row['id']] = str_replace($legacyToken, $replacement, $template);
			}
		}
		$result->closeCursor();

		if ($rewrites === []) {
			// An instance whose templates never named the token. It should not take a write.
			return;
		}

		// One statement per row rather than a chunked IN(): each row gets a *different*
		// value, so there is nothing to batch. The row count is bounded by the number of
		// configured policies, which is small by construction.
		foreach ($rewrites as $id => $template) {
			$update = $this->db->getQueryBuilder();
			$update->update('watermark_config')
				->set('text_template', $update->createNamedParameter($template))
				->where($update->expr()->eq('id', $update->createNamedParameter($id)));
			$update->executeStatement();
		}

		$output->info(sprintf(
			'files_watermark: rewrote %s to %s in %d watermark template(s); they render exactly '
				. 'what they rendered before. %s now resolves to the account name.',
			$legacyToken,
			$replacement,
			count($rewrites),
			$legacyToken,
		));
	}
}
