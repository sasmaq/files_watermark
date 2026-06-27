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
    require_once __DIR__ . '/../vendor/autoload.php';
}

namespace OC\Hooks {
    if (!interface_exists(Emitter::class)) {
        interface Emitter {
            public function listen($scope, $method, callable $callback);

            public function removeListener($scope = null, $method = null, ?callable $callback = null);
        }
    }
}
