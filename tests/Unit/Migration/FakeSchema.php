<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Migration;

use OCP\DB\ISchemaWrapper;

/**
 * The slice of {@see ISchemaWrapper} the migrations actually touch.
 *
 * Doctrine is not a dependency of this app — Nextcloud supplies it at runtime — so the
 * real schema objects are unavailable in unit tests. The interface has to be implemented
 * rather than duck-typed, because `changeSchema()` declares `?ISchemaWrapper` as its
 * return type; the methods no migration here calls throw instead of pretending.
 */
class FakeSchema implements ISchemaWrapper {

	/** @var array<string, FakeTable> */
	private array $tables = [];

	public function hasTable($tableName): bool {
		return isset($this->tables[$tableName]);
	}

	/**
	 * Throws on a table that already exists, as Doctrine's `Schema::createTable()` does
	 * (`TableExistsException`).
	 *
	 * This is the fidelity that makes the convergence test worth having. An earlier
	 * version of this fake replaced the table silently, and a mutation removing the
	 * `hasTable()` guard from the migration passed every test — the recreated table
	 * happened to have the right columns, so nothing noticed that a real upgrade would
	 * have aborted.
	 */
	public function createTable($tableName): FakeTable {
		if (isset($this->tables[$tableName])) {
			throw new \RuntimeException(
				"table already exists: $tableName — the migration is missing a hasTable() guard",
			);
		}
		return $this->tables[$tableName] = new FakeTable();
	}

	public function getTable($tableName): FakeTable {
		if (!isset($this->tables[$tableName])) {
			throw new \OutOfBoundsException("no such table: $tableName");
		}
		return $this->tables[$tableName];
	}

	public function dropTable($tableName): void {
		unset($this->tables[$tableName]);
	}

	/** @return array<string, FakeTable> */
	public function getTables(): array {
		return $this->tables;
	}

	/** @return list<string> */
	public function getTableNames(): array {
		return array_keys($this->tables);
	}

	/** @return list<string> */
	public function getTableNamesWithoutPrefix(): array {
		return array_keys($this->tables);
	}

	public function getDatabasePlatform(): never {
		throw new \LogicException('no migration under test inspects the platform');
	}
}
