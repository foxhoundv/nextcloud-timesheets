<?php

return [
    'routes' => [
        ['name' => 'job#clockIn', 'url' => '/api/v1/jobs/clock-in', 'verb' => 'POST'],
        ['name' => 'job#clockOut', 'url' => '/api/v1/jobs/clock-out', 'verb' => 'POST'],
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'adminPage#index', 'url' => '/admin', 'verb' => 'GET'],
        ['name' => 'admin#save', 'url' => '/settings', 'verb' => 'POST'],
        ['name' => 'recordReset#status', 'url' => '/settings/records/status', 'verb' => 'POST'],
        ['name' => 'recordReset#approve', 'url' => '/settings/records/approve', 'verb' => 'POST'],
        ['name' => 'recordReset#cancel', 'url' => '/settings/records/cancel', 'verb' => 'POST'],
        ['name' => 'location#save', 'url' => '/settings/locations', 'verb' => 'POST'],
        ['name' => 'storage#save', 'url' => '/settings/storage', 'verb' => 'POST'],
        ['name' => 'storage#payroll', 'url' => '/reports/payroll', 'verb' => 'POST'],
        ['name' => 'access#save', 'url' => '/settings/access', 'verb' => 'POST'],
        ['name' => 'access#search', 'url' => '/settings/access/search', 'verb' => 'GET'],
        ['name' => 'shift#clockIn', 'url' => '/api/v1/shifts/clock-in', 'verb' => 'POST'],
        ['name' => 'shift#clockOut', 'url' => '/api/v1/shifts/clock-out', 'verb' => 'POST'],
    ],
];