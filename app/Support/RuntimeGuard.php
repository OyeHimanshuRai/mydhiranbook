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
        $config = config()->all();
        $config['runtime'] = ['storage_path' => realpath($app->storagePath()), 'base_path' => realpath($app->basePath())];
        self::verifyConfig($config, $app->basePath());
    }

    public static function isLocal(string $environment): bool
    {
        return in_array($environment, ['local', 'testing'], true);
    }

    public static function verifyConfig(array $config, string $basePath): void
    {
        $env = (string) data_get($config, 'app.env');
        self::refuse(self::isLocal($env) || $env === 'production', 'unknown environment');
        $env === 'production' ? self::verifyProduction($config) : self::verifyLocal($config, $env);
    }

    private static function verifyProduction(array $config): void
    {
        $get = fn (string $key) => data_get($config, $key);
        self::refuse($get('app.debug') === false, 'APP_DEBUG must be false in production');
        self::refuse(str_starts_with((string) $get('app.url'), 'https://'), 'APP_URL must be https in production');
        self::refuse((string) $get('app.key') !== '', 'missing application key');
        self::refuse($get('database.default') === 'pgsql' && $get('database.connections.pgsql.driver') === 'pgsql', 'production requires PostgreSQL');
        self::refuse(!in_array($get('queue.default'), ['sync', 'null', null, ''], true), 'production requires a persistent queue');
        self::refuse($get('session.secure') === true, 'session cookie must be secure in production');
        self::refuse(!in_array($get('mail.default'), ['log', 'array', null, ''], true), 'production requires a real mailer');
        // Trusting nobody (empty) or everybody ('*') is only right when nothing sits in front of the app: say so with DIRECT_EXPOSURE=true,
        // otherwise name the proxy addresses (TRUSTED_PROXIES=127.0.0.1 for a proxy on the same host). '*' lets any client forge its IP and scheme.
        $proxies = trim((string) $get('app.trusted_proxies'));
        self::refuse(($proxies !== '' && $proxies !== '*') || $get('app.direct_exposure') === true, 'set TRUSTED_PROXIES to the proxy address(es) (for example 127.0.0.1), or DIRECT_EXPOSURE=true if the app faces the internet with no proxy; empty or * is refused');
    }

    private static function verifyLocal(array $config, string $environment): void
    {
        $get = fn (string $key) => data_get($config, $key);
        $root = '/home/himanshu/Desktop/Dhiran ERP';
        self::refuse($get('runtime.base_path') === $root && is_dir($root.'/.git'), 'workspace boundary');
        $manifest = json_decode(file_get_contents($root.'/.runtime/targets.json'), true, flags: JSON_THROW_ON_ERROR);
        self::refuse(($manifest['root'] ?? '') === $root, 'target manifest root');
        $db = $get('database.connections.pgsql');
        self::validateDatabase($db, $environment, $root);
        $target = $manifest[$environment];
        self::refuse(hash_equals($target['password'], (string) $db['password']), 'credentials differ from the dedicated target');
        self::refuse($get('database.default') === 'pgsql' && array_keys($get('database.connections')) === ['pgsql'], 'additional database targets');
        $storage = $environment === 'testing' ? $root.'/.runtime/testing/storage' : $root.'/storage';
        self::refuse($get('runtime.storage_path') === $storage, 'storage outside Dhiran');
        foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
            self::refuse(realpath($storage.'/'.$path) === $storage.'/'.$path, 'symlink or missing private storage');
        }
        self::refuse($get('filesystems.default') === 'local' && count($get('filesystems.disks')) === 1 && $get('filesystems.disks.local.root') === $storage.'/app/private', 'filesystem target');
        self::refuse($get('cache.default') === 'file' && $get('cache.stores.file.path') === $storage.'/framework/cache/data', 'cache target');
        self::refuse($get('session.driver') === 'file' && $get('session.files') === $storage.'/framework/sessions' && $get('session.domain') === null, 'session target');
        self::refuse($get('queue.default') === 'database' && count($get('queue.connections')) === 1 && $get('queue.connections.database.connection') === 'pgsql' && $get('queue.connections.database.queue') === 'dhiran-'.$environment, 'queue target');
        self::refuse($get('mail.default') === 'log' && count($get('mail.mailers')) === 1, 'mail must stay local');
        self::refuse(str_starts_with((string) $get('app.key'), 'base64:'), 'missing local application key');
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
