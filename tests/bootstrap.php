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
}

namespace OC\Hooks {
    if (!interface_exists(Emitter::class)) {
        interface Emitter {
            public function listen($scope, $method, callable $callback);

            public function removeListener($scope = null, $method = null, ?callable $callback = null);
        }
    }
}
