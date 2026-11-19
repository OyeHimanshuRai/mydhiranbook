<?php
namespace App\Support;

use Illuminate\Foundation\Application;
use PDO;
use RuntimeException;

final class RuntimeGuard
{
    private static function refuse(bool $safe, string $reason): void
    {
        if (!$safe) {
            throw new RuntimeException('Dhiran runtime target refused: '.$reason);
        }
    }

    public static function validateDatabase(array $db, string $environment, string $root): void
    {
        $expected = $environment === 'testing' ? ['dhiran_test', 'dhiran_test'] : ['dhiran_local', 'dhiran_app'];
        self::refuse(in_array($environment, ['local', 'testing'], true), 'only local/testing environments are allowed');
        self::refuse(($db['driver'] ?? '') === 'pgsql' && empty($db['url']), 'only the dedicated PostgreSQL connection is allowed');
        self::refuse(($db['host'] ?? '') === $root.'/.runtime/postgres/socket' && (string)($db['port'] ?? '') === '55439', 'unexpected PostgreSQL socket or port');
        self::refuse([$db['database'] ?? '', $db['username'] ?? ''] === $expected, 'unexpected database or role');
    }

    public static function verify(Application $app): void
    {
        $root = '/home/himanshu/Desktop/Dhiran ERP';
        self::refuse(realpath($app->basePath()) === $root && is_dir($root.'/.git'), 'workspace boundary');
        $manifest = json_decode(file_get_contents($root.'/.runtime/targets.json'), true, flags: JSON_THROW_ON_ERROR);
        self::refuse(($manifest['root'] ?? '') === $root, 'target manifest root');
        $db = config('database.connections.pgsql');
        self::validateDatabase($db, $app->environment(), $root);
        $target = $manifest[$app->environment()];
        self::refuse(hash_equals($target['password'], (string)$db['password']), 'credentials differ from the dedicated target');
        self::refuse(config('database.default') === 'pgsql' && array_keys(config('database.connections')) === ['pgsql'], 'additional database targets');
        $storage = $app->environment('testing') ? $root.'/.runtime/testing/storage' : $root.'/storage';
        self::refuse(realpath($app->storagePath()) === $storage, 'storage outside Dhiran');
        foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
            self::refuse(realpath($storage.'/'.$path) === $storage.'/'.$path, 'symlink or missing private storage');
        }
        self::refuse(config('filesystems.default') === 'local' && count(config('filesystems.disks')) === 1 && config('filesystems.disks.local.root') === $storage.'/app/private', 'filesystem target');
        self::refuse(config('cache.default') === 'file' && config('cache.stores.file.path') === $storage.'/framework/cache/data', 'cache target');
        self::refuse(config('session.driver') === 'file' && config('session.files') === $storage.'/framework/sessions' && config('session.domain') === null, 'session target');
        self::refuse(config('queue.default') === 'database' && count(config('queue.connections')) === 1 && config('queue.connections.database.connection') === 'pgsql' && config('queue.connections.database.queue') === 'dhiran-'.$app->environment(), 'queue target');
        self::refuse(config('mail.default') === 'log' && count(config('mail.mailers')) === 1, 'mail must stay local');
        self::refuse(str_starts_with((string)config('app.key'), 'base64:'), 'missing local application key');
    }

    public static function verifyConnection(PDO $pdo, string $environment, string $root): array
    {
        $manifest = json_decode(file_get_contents($root.'/.runtime/targets.json'), true, flags: JSON_THROW_ON_ERROR);
        $row = $pdo->query("SELECT current_database() AS database, session_user AS username, inet_server_addr() AS address, current_setting('port') AS port, (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier, (SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls FROM pg_roles WHERE rolname = session_user) AS elevated")->fetch(PDO::FETCH_ASSOC);
        $expected = $manifest[$environment];
        self::refuse($row['database'] === $expected['database'] && $row['username'] === $expected['username'], 'connected database identity');
        self::refuse($row['address'] === null && $row['port'] === '55439' && $row['system_identifier'] === $manifest['system_identifier'], 'connected PostgreSQL instance');
        self::refuse(!$row['elevated'], 'database role has elevated privileges');
        return ['environment'=>$environment, 'database'=>$row['database'], 'role'=>$row['username'], 'transport'=>'workspace Unix socket', 'cluster'=>$row['system_identifier']];
    }
}
