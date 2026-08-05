<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Migration;

/**
 * A table that records column names and nothing else.
 *
 * Column *types* and options are deliberately ignored: the migrations under test branch
 * only on whether a column exists, and asserting on Doctrine option arrays would pin the
 * schema's shape rather than the migration's behaviour. Primary keys are accepted and
 * discarded for the same reason.
 *
 * Index *names* are the one exception, tracked because a migration branches on them:
 * dropping `group_id` has to drop `wm_config_group_idx` first, and only on instances that
 * have it.
 */
class FakeTable {

	/** @var list<string> */
	private array $columns = [];

	/** @var list<string> */
	private array $indexes = [];

	public function addColumn(string $name, string $type, array $options = []): self {
		$this->columns[] = $name;
		return $this;
	}

	public function hasColumn(string $name): bool {
		return in_array($name, $this->columns, true);
	}

	public function dropColumn(string $name): self {
		$this->columns = array_values(array_filter(
			$this->columns,
			static fn (string $column): bool => $column !== $name,
		));
		return $this;
	}

	/** @return list<string> */
	public function columnNames(): array {
		return $this->columns;
	}

	public function setPrimaryKey(array $columns, $indexName = null): self {
		return $this;
	}

	public function addIndex(array $columns, $indexName = null): self {
		if ($indexName !== null) {
			$this->indexes[] = $indexName;
		}
		return $this;
	}

	/**
	 * Recorded in the same list as an ordinary index: uniqueness is a constraint the
	 * database enforces, and no migration here branches on whether an index has it.
	 */
	public function addUniqueIndex(array $columns, $indexName = null): self {
		return $this->addIndex($columns, $indexName);
	}

	public function hasIndex(string $indexName): bool {
		return in_array($indexName, $this->indexes, true);
	}

	public function dropIndex(string $indexName): self {
		$this->indexes = array_values(array_filter(
			$this->indexes,
			static fn (string $index): bool => $index !== $indexName,
		));
		return $this;
	}

	/** @return list<string> */
	public function indexNames(): array {
		return $this->indexes;
	}
}
