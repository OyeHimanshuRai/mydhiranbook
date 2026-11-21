<?php
namespace Tests\Unit;
use App\Support\LocalPostgresConnector;
use PHPUnit\Framework\TestCase;
class LocalPostgresConnectorTest extends TestCase
{
    private function dsn(string $host): string
    {
        $c=new class extends LocalPostgresConnector { public function dsn(array $c){ return $this->getDsn($c); } };
        return $c->dsn(['host'=>$host,'port'=>5432,'database'=>'d']);
    }
    public function test_tcp_host_and_socket_path_dsn(): void
    {
        $this->assertSame("pgsql:host='db.example.com';dbname='d';port=5432",$this->dsn('db.example.com'));
        $this->assertSame("pgsql:host='10.0.0.5';dbname='d';port=5432",$this->dsn('10.0.0.5'));
        $this->assertSame("pgsql:host='/a b/socket';dbname='d';port=5432",$this->dsn('/a b/socket'));
    }
}
