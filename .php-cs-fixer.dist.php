<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
    ->getFinder()
    ->ignoreVCSIgnored(true)
    ->notPath('build')
    ->notPath('l10n')
    ->notPath('node_modules')
    ->notPath('src')
    ->notPath('vendor')
    // Third-party font metrics copied verbatim from TCPDF (see the README there).
    // Reformatting them would make an upstream diff impossible to read and gains
    // nothing — nobody edits these by hand.
    ->notPath('resources/fonts')
    ->in(__DIR__);

return $config;
