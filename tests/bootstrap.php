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

	// tc-lib-pdf finds its font metrics through the K_PATH_FONTS constant, and
	// resources/fonts is where this app keeps them - Composer ships none. Claimed here
	// so it is set before any test touches the renderer, mirroring what Application's
	// constructor does at runtime. A constant cannot be redefined, so this must not be
	// left to whichever test happens to run first.
	\OCA\FilesWatermark\Service\PdfFontPath::register();

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

namespace {
	/**
	 * The server container, as far as `OCP\AppFramework\Http\Response` needs it.
	 *
	 * `Response::getHeaders()` reaches for `\OC::$server->get(IRequest::class)` to stamp an
	 * `X-Request-Id` on every response. That is a server internal the ocp package does not
	 * ship, so any test that reads a response's headers - which is the only way to assert a
	 * `Cache-Control` this app sets deliberately - dies on "Class OC not found" rather than
	 * on anything to do with the code under test.
	 *
	 * Two services are asked for and three methods are answered - `getId()` on the request,
	 * and `getUser()` on the session, which stamps an `X-User-Id` when somebody is logged
	 * in. One anonymous object serves both: it is a stub for a container lookup, not a
	 * model of one, and giving it a second class would only make it look like more.
	 */
	if (!class_exists(OC::class)) {
		class OC {
			public static object $server;
		}

		OC::$server = new class() {
			public function get(string $service): object {
				return new class() {
					public function getId(): string {
						return 'test-request';
					}

					/** Nobody is logged in, so no `X-User-Id` is stamped. */
					public function getUser(): ?object {
						return null;
					}
				};
			}
		};
	}
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
