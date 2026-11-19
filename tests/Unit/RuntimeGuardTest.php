<?php
namespace Tests\Unit;
use App\Support\RuntimeGuard;
use PHPUnit\Framework\TestCase;
class RuntimeGuardTest extends TestCase
{
    public function test_refuses_a_database_outside_dhiran_before_connecting(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dhiran runtime target refused');
        RuntimeGuard::validateDatabase(['driver'=>'pgsql','host'=>'/tmp','port'=>'5432','database'=>'jewelflow','username'=>'postgres','url'=>null], 'testing', dirname(__DIR__, 2));
    }
    public function test_refuses_an_erp_connection_url_even_with_safe_database_fields(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dhiran runtime target refused');
        RuntimeGuard::validateDatabase(['driver'=>'pgsql','host'=>dirname(__DIR__,2).'/.runtime/postgres/socket','port'=>'55439','database'=>'dhiran_test','username'=>'dhiran_test','url'=>'postgres://other/erp'], 'testing', dirname(__DIR__, 2));
    }
}
