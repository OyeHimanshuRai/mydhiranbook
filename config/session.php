<?php
return [
    'platform_admin_cookie' => 'dhiran_admin_'.env('APP_ENV', 'local').'_session', 'tenant_cookie' => 'dhiran_'.env('APP_ENV', 'local').'_session', 'driver' => env('SESSION_DRIVER', 'file'), 'lifetime' => 120, 'expire_on_close' => false, 'encrypt' => true, 'files' => storage_path('framework/sessions'), 'connection' => null, 'table' => 'sessions', 'store' => null, 'lottery' => [2, 100], 'cookie' => env('SESSION_COOKIE', 'dhiran_local_session'), 'path' => '/', 'domain' => env('SESSION_DOMAIN'), 'secure' => (bool) env('SESSION_SECURE_COOKIE', false), 'http_only' => true, 'same_site' => 'lax', 'partitioned' => false
];
