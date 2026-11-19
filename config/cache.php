<?php
return [
    'default' => 'file', 'prefix' => 'dhiran_', 'stores' => ['file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data'), 'lock_path' => storage_path('framework/cache/data')], 'array' => ['driver' => 'array', 'serialize' => false]]
];
