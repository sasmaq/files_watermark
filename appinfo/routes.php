<?php

declare(strict_types=1);

return [
    'routes' => [
        // API v1
        ['name' => 'api#getConfig',    'url' => '/api/v1/config',       'verb' => 'GET'],
        ['name' => 'api#saveConfig',   'url' => '/api/v1/config',       'verb' => 'POST'],
        ['name' => 'api#deleteConfig', 'url' => '/api/v1/config/{id}',  'verb' => 'DELETE'],
        ['name' => 'api#applyWatermark', 'url' => '/api/v1/apply',      'verb' => 'POST'],
        ['name' => 'api#getLog',       'url' => '/api/v1/log',          'verb' => 'GET'],

        // Settings pages
        ['name' => 'settings#adminIndex', 'url' => '/settings/admin', 'verb' => 'GET'],
    ],
];
