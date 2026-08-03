<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rewrite stored `{username}` tokens to `{displayname}`, preserving what they rendered.
 *
 * `{username}` used to resolve to the *display* name - `$user->getDisplayName()` - which
 * left the account name unreachable and the token misleading. It now resolves to the uid,
 * and `{displayname}` carries the human-readable name.
 *
 * That is a change of meaning for text an admin already saved, so without this step every
 * existing watermark would quietly switch from "Alice Smith" to "asmith3" on upgrade, with
 * nothing on screen to explain why. Rewriting the token instead keeps the rendered output
 * **byte-identical** and makes the account name an opt-in choice rather than a surprise.
 *
 * No schema change: this is a data step only, hence no `changeSchema()`.
 *
 * **Not idempotent in the strict sense, and it does not need to be.** Re-running it would
 * rewrite a `{username}` an admin had deliberately typed *after* the upgrade - but
 * Nextcloud records applied migrations and never re-runs one, and unlike
 * {@see Version1003Date20260730120000} this file does not have to meet several starting
 * states. It is stated here so nobody "fixes" it by making the rewrite unconditional
 * somewhere it would run twice.
 */
class Version1004Date20260731000000 extends SimpleMigrationStep {

	private const LEGACY_TOKEN = '{username}';
	private const REPLACEMENT = '{displayname}';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'text_template')
			->from('watermark_config')
			->where($select->expr()->isNotNull('text_template'));

		$result = $select->executeQuery();
		$rewrites = [];
		while ($row = $result->fetch()) {
			$template = (string)$row['text_template'];
			if (str_contains($template, self::LEGACY_TOKEN)) {
				$rewrites[(int)$row['id']] = str_replace(self::LEGACY_TOKEN, self::REPLACEMENT, $template);
			}
		}
		$result->closeCursor();

		if ($rewrites === []) {
			// A fresh install lands here, and so does an instance whose templates never
			// named the token. Neither should take a write on upgrade.
			return;
		}

		// One statement per row rather than a chunked IN(): each row gets a *different*
		// value, so there is nothing to batch. The row count is bounded by the number of
		// configured policies, which is small by construction - one global plus one per
		// user who has personalised theirs.
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
			self::LEGACY_TOKEN,
			self::REPLACEMENT,
			count($rewrites),
			self::LEGACY_TOKEN,
		));
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}
}
