<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `watermark_rendition` - the size a marked file's download is *promised* to be.
 *
 * ---------------------------------------------------------------------------
 * WHY A SIZE HAS TO BE WRITTEN DOWN BEFORE THE DOWNLOAD HAPPENS.
 *
 * A marked file is never served as it is stored, so the bytes a client receives are longer
 * than the ones PROPFIND measured. A browser neither knows nor cares. A **sync client**
 * checks, in three separate places, and the Windows client with virtual files cannot even
 * get that far: it sizes its CfAPI placeholder from PROPFIND and hydration then writes past
 * the end of it.
 *
 * The fix is for PROPFIND to advertise a length the download will actually have. Nothing
 * can predict that length from the stored file - it depends on the watermark, the config,
 * the page count and the reader's own name - so it is *measured once, by rendering*, and
 * kept here. Every later download of that file by that user renders live and is padded up
 * to the number in this table, so the promise holds without freezing the watermark.
 *
 * `reserved_size` is therefore a render's length **plus slack**, not a render's length. The
 * slack is what lets the timestamp inside the watermark keep moving: only its digits change
 * between renders, which moves the compressed output by a few bytes, and the padding absorbs
 * the difference.
 * ---------------------------------------------------------------------------
 *
 * Keyed by (`file_id`, `uid`) because the length depends on the reader - their display name
 * is drawn into every tile - and a row measured for one user promises nothing about another.
 *
 * `signature` is what makes a row stale: it hashes the inputs that change the render's size
 * (the stored file's etag and the resolved config). A row whose signature no longer matches
 * is ignored and re-measured rather than deleted on a write path, so nothing about serving a
 * download depends on an invalidation hook having fired first.
 *
 * Additive and guarded like every step here; `SchemaConvergenceTest` drives the whole chain
 * from each starting state the app has shipped and asserts they all land in the same place.
 */
class Version1008Date20260915120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('watermark_rendition')) {
			return null;
		}

		$table = $schema->createTable('watermark_rendition');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('file_id', Types::BIGINT, [
			'notnull' => true,
		]);
		// 64 to match `watermark_mark.marked_by`, which holds the same kind of value.
		$table->addColumn('uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		// A hex digest of the inputs that decide the render's length. 64 leaves room for
		// sha256 should the digest ever change; today's is shorter and fits regardless.
		$table->addColumn('signature', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('reserved_size', Types::BIGINT, [
			'notnull' => true,
		]);
		// The etag PROPFIND reports and the download echoes - a digest of the stored etag
		// and the reserved size, so it changes exactly when one of those does and the client
		// re-reads the metadata it had cached.
		$table->addColumn('etag', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('created_at', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('updated_at', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);

		$table->setPrimaryKey(['id']);
		// One row per reader per file, enforced rather than checked - two workers measuring
		// the same file for the same user at once is ordinary, and the mapper leans on this
		// instead of a read-then-write with a window in the middle.
		$table->addUniqueIndex(['file_id', 'uid'], 'wm_rendition_file_uid_idx');
		// Dropping every reader's row when one file changes or is deleted.
		$table->addIndex(['file_id'], 'wm_rendition_file_idx');

		return $schema;
	}
}
