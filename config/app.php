<?php
return [
    'name' => env('APP_NAME', 'MyDhiranBook'), 'env' => env('APP_ENV', 'local'), 'debug' => (bool) env('APP_DEBUG', false), 'url' => env('APP_URL', 'http://127.0.0.1:8787'), 'timezone' => 'Asia/Kolkata', 'locale' => 'en', 'fallback_locale' => 'en', 'faker_locale' => 'en_IN', 'key' => env('APP_KEY'), 'cipher' => 'AES-256-CBC', 'maintenance' => ['driver' => 'file'], 'trusted_proxies' => env('TRUSTED_PROXIES', '')
];
