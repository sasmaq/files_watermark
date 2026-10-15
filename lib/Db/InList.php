<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Db;

/**
 * Splits an id list into pieces an `IN (...)` can carry on every database Nextcloud supports.
 *
 * The batched lookups here were one query for the whole list, and the lists are not small:
 * a folder download asks about every file in the tree. Oracle refuses an `IN` of more than
 * 1000 values, and MySQL and PostgreSQL refuse a statement with more than 65535 bound
 * parameters - so a large enough folder answered 500 instead of an archive. A thousand per
 * query keeps every backend inside its limit and is still one query per thousand files.
 */
final class InList {

	public const MAX = 1000;

	/**
	 * The distinct ids, as ints, in chunks of at most {@see MAX}.
	 *
	 * @param array<int|string> $ids
	 * @return list<list<int>>
	 */
	public static function chunks(array $ids): array {
		return array_chunk(array_values(array_unique(array_map('intval', $ids))), self::MAX);
	}
}
