<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads the Composer autoloader (including the nextcloud/ocp stubs) and defines
 * a few server-internal symbols that some OCP interfaces reference but that are
 * not shipped in the nextcloud/ocp package (e.g. OCP\Files\IRootFolder extends
 * \OC\Hooks\Emitter). Stubbing them here lets PHPUnit build mocks of those OCP
 * interfaces without a full Nextcloud server.
 */

namespace {
	// Plain require (not require_once): the phpunit binary has already required
	// the autoloader, so require_once would return true instead of the loader.
	// Composer's autoload.php always returns the cached ClassLoader instance.
	$loader = require __DIR__ . '/../vendor/autoload.php';

	// Register the nextcloud/ocp stub interfaces for the test run only. These
	// are deliberately NOT in composer's autoload(-dev) so they never shadow
	// the real OCP classes at Nextcloud runtime (which causes fatal
	// signature-mismatch errors against core).
	if ($loader instanceof \Composer\Autoload\ClassLoader) {
		$loader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
		$loader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
	}

	// Nextcloud *server* classes that lib/Dav/ depends on (OCA\DAV\Connector\Sabre\*,
	// OC\Streamer). Sabre itself is a real require-dev package, so only these are
	// stubbed. Kept out of composer autoload for the same reason as the OCP stubs.
	require_once __DIR__ . '/stubs/CoreStubs.php';
}

namespace OC\Hooks {
	if (!interface_exists(Emitter::class)) {
		interface Emitter {
			public function listen($scope, $method, callable $callback);

			public function removeListener($scope = null, $method = null, ?callable $callback = null);
		}
	}
}

namespace Doctrine\DBAL {
	// OCP\DB\QueryBuilder\IQueryBuilder defines class constants that reference
	// these Doctrine symbols. Doctrine DBAL is not a test dependency, so stub
	// just enough for the interface to load when a QueryBuilder is mocked.
	if (!class_exists(ParameterType::class)) {
		final class ParameterType {
			public const NULL = 0;
			public const INTEGER = 1;
			public const STRING = 2;
			public const LARGE_OBJECT = 3;
			public const BINARY = 4;
			public const ASCII = 5;
			public const BOOLEAN = 6;
		}
	}
	if (!class_exists(ArrayParameterType::class)) {
		final class ArrayParameterType {
			public const INTEGER = 101;
			public const STRING = 102;
			public const ASCII = 117;
			public const BINARY = 116;
		}
	}
}

namespace Doctrine\DBAL\Query\Expression {
	if (!class_exists(ExpressionBuilder::class)) {
		final class ExpressionBuilder {
			public const EQ = '=';
			public const NEQ = '<>';
			public const LT = '<';
			public const LTE = '<=';
			public const GT = '>';
			public const GTE = '>=';
		}
	}
}

namespace Doctrine\DBAL\Types {
	if (!class_exists(Types::class)) {
		final class Types {
			public const BOOLEAN = 'boolean';
			public const DATE_MUTABLE = 'date';
			public const DATE_IMMUTABLE = 'date_immutable';
			public const DATETIME_MUTABLE = 'datetime';
			public const DATETIME_IMMUTABLE = 'datetime_immutable';
			public const DATETIMETZ_MUTABLE = 'datetimetz';
			public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
			public const TIME_MUTABLE = 'time';
			public const TIME_IMMUTABLE = 'time_immutable';
		}
	}
}
