<?php
namespace App\Support;

use Illuminate\Database\Connectors\PostgresConnector;

/** libpq requires quotes around this workspace's socket path because it contains a space. */
class LocalPostgresConnector extends PostgresConnector
{
    protected function getDsn(array $config)
    {
        $config['host'] = "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $config['host'])."'";
        return parent::getDsn($config);
    }
}
