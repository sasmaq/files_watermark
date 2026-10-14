<?php

declare(strict_types=1);

return [
	'routes' => [
		// API v1
		['name' => 'api#getConfig',      'url' => '/api/v1/config',      'verb' => 'GET'],
		['name' => 'api#saveConfig',     'url' => '/api/v1/config',      'verb' => 'POST'],
		['name' => 'api#deleteConfig',   'url' => '/api/v1/config/{id}', 'verb' => 'DELETE'],
		// Uploads the watermark logo; returns the reference to store on a config
		['name' => 'api#uploadImage',    'url' => '/api/v1/image',       'verb' => 'POST'],
		['name' => 'api#applyWatermark', 'url' => '/api/v1/apply',       'verb' => 'POST'],
		// Takes the mark off a file - its owner only. Nothing was overwritten, so nothing is restored.
		['name' => 'api#removeWatermark', 'url' => '/api/v1/remove',     'verb' => 'POST'],
		['name' => 'api#getLog',         'url' => '/api/v1/log',         'verb' => 'GET'],
		['name' => 'api#getWatermarkedStatus', 'url' => '/api/v1/watermarked', 'verb' => 'GET'],

		// There is deliberately no download route. Every download goes through DAV, where the
		// interceptors watermark it and core's share checks still apply; `/api/v1/download` used
		// to sit beside them, unused by the UI, serving unmarked files with no view-only check.

		// Settings pages
		['name' => 'settings#adminIndex', 'url' => '/settings/admin', 'verb' => 'GET'],
	],
];
