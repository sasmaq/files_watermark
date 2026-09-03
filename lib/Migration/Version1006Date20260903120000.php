<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `origin_owner` / `origin_file_id` - where a mark came from, so a copy cannot shed it.
 *
 * ---------------------------------------------------------------------------
 * WHY A MARK NEEDS A PROVENANCE AT ALL.
 *
 * Until now a mark said only "this file id is watermarked". That was enough while marks
 * were placed on files somebody already owned, and it stopped being enough the moment
 * marks started being *inherited*: copying a marked file makes the copier the owner of the
 * copy, and `ApiController::removeWatermark` lets an owner unmark what they own. Propagating
 * the mark onto the copy without recording where it came from would have handed the
 * recipient the mark and the Remove button in the same gesture.
 *
 * `origin_owner` is the uid whose file the protection descends from. It is what the remove
 * gate consults instead of ownership once it is set - see `ApiController::removeWatermark`.
 * `origin_file_id` is audit only: which file this mark was inherited from, so a chain of
 * copies can be read back.
 * ---------------------------------------------------------------------------
 *
 * **Both are nullable, and null is the ordinary case.** A mark placed directly on a file -
 * every mark that exists before this step runs, and every one placed by Apply or by the
 * upload listener afterwards - carries neither column, and the remove gate falls back to the
 * ownership check it has always used. Only an inherited mark sets them. That is what makes
 * this step safe to run against an instance mid-flight: nothing already marked changes
 * behaviour.
 *
 * Additive and guarded like every step here; `SchemaConvergenceTest` drives the whole chain
 * from each starting state the app has shipped and asserts they all land in the same place,
 * so the `hasColumn` checks are load-bearing rather than defensive.
 */
class Version1006Date20260903120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('watermark_mark')) {
			// Nothing to add a column to. The step that creates the table runs before this
			// one on every path Nextcloud takes, so this is the "table was dropped by hand"
			// case rather than a fresh install.
			return null;
		}

		$table = $schema->getTable('watermark_mark');
		$changed = false;

		if (!$table->hasColumn('origin_owner')) {
			// 64 to match `marked_by`, which holds the same kind of value - a uid.
			$table->addColumn('origin_owner', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('origin_file_id')) {
			// BIGINT to match `file_id`, which is what this holds.
			$table->addColumn('origin_file_id', Types::BIGINT, [
				'notnull' => false,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
