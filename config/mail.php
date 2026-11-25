<?php
$default = env('MAIL_MAILER', 'log');
return [
    'default' => $default,
    'mailers' => ['log' => ['transport' => 'log', 'channel' => 'daily']] + ($default === 'log' ? [] : [
        'smtp' => ['transport' => 'smtp', 'host' => env('MAIL_HOST'), 'port' => (int) env('MAIL_PORT', 587), 'username' => env('MAIL_USERNAME'), 'password' => env('MAIL_PASSWORD'), 'scheme' => env('MAIL_SCHEME'), 'timeout' => null],
    ]),
    'from' => ['address' => env('MAIL_FROM_ADDRESS', 'local@dhiran.test'), 'name' => env('MAIL_FROM_NAME', 'MyDhiranBook')],
];
