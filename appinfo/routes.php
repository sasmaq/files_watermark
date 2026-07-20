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
		// Restores the pre-watermark original preserved at apply time
		['name' => 'api#removeWatermark', 'url' => '/api/v1/remove',     'verb' => 'POST'],
		['name' => 'api#getLog',         'url' => '/api/v1/log',         'verb' => 'GET'],
		['name' => 'api#getWatermarkedStatus', 'url' => '/api/v1/watermarked', 'verb' => 'GET'],

		// On-download watermark endpoint — streams a watermarked temp copy, original untouched
		['name' => 'download#download',  'url' => '/api/v1/download',    'verb' => 'GET'],

		// Settings pages
		['name' => 'settings#adminIndex', 'url' => '/settings/admin', 'verb' => 'GET'],
	],
];
