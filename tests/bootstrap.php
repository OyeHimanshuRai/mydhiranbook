<?php
require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
Dotenv\Dotenv::createImmutable($root, '.env.testing')->load();
$targets = json_decode(file_get_contents($root.'/.runtime/targets.json'), true, flags: JSON_THROW_ON_ERROR);
$db = ['driver'=>'pgsql','host'=>$_ENV['DB_HOST'],'port'=>$_ENV['DB_PORT'],'database'=>$_ENV['DB_DATABASE'],'username'=>$_ENV['DB_USERNAME'],'url'=>$_ENV['DB_URL'] ?? null];
App\Support\RuntimeGuard::validateDatabase($db, 'testing', $root);
$pdo = new PDO("pgsql:host='".$db['host']."';port=".$db['port'].";dbname=".$db['database'], $db['username'], $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
App\Support\RuntimeGuard::verifyConnection($pdo, 'testing', $root);
unset($pdo);
