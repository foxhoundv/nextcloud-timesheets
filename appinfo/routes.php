<?php

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'admin#save', 'url' => '/settings', 'verb' => 'POST'],
        ['name' => 'access#save', 'url' => '/settings/access', 'verb' => 'POST'],
        ['name' => 'access#search', 'url' => '/settings/access/search', 'verb' => 'GET'],
        ['name' => 'shift#clockIn', 'url' => '/api/v1/shifts/clock-in', 'verb' => 'POST'],
        ['name' => 'shift#clockOut', 'url' => '/api/v1/shifts/clock-out', 'verb' => 'POST'],
    ],
];