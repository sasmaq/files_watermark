<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Migration;

/**
 * A table that records column names and nothing else.
 *
 * Column *types* and options are deliberately ignored: the migrations under test branch
 * only on whether a column exists, and asserting on Doctrine option arrays would pin the
 * schema's shape rather than the migration's behaviour. Indexes and primary keys are
 * accepted and discarded for the same reason.
 */
class FakeTable {

	/** @var list<string> */
	private array $columns = [];

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
		return $this;
	}
}
