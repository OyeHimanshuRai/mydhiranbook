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

    private function prod(array $override = []): array
    {
        $c = [
            'app' => ['env' => 'production', 'debug' => false, 'url' => 'https://dhiran.example.com', 'key' => 'base64:abc'],
            'database' => ['default' => 'pgsql', 'connections' => ['pgsql' => ['driver' => 'pgsql']]],
            'queue' => ['default' => 'database'],
            'session' => ['secure' => true],
            'mail' => ['default' => 'smtp'],
            'services' => ['razorpay' => ['key' => 'k', 'secret' => 's', 'webhook_secret' => 'w']],
        ];
        foreach ($override as $path => $value) {
            \Illuminate\Support\Arr::set($c, $path, $value);
        }
        return $c;
    }

    private function refused(array $config): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dhiran runtime target refused');
        RuntimeGuard::verifyConfig($config, dirname(__DIR__, 2));
    }

    public function test_production_accepts_valid_config(): void
    {
        RuntimeGuard::verifyConfig($this->prod(), dirname(__DIR__, 2));
        $this->addToAssertionCount(1);
    }

    public function test_production_refuses_debug_true(): void { $this->refused($this->prod(['app.debug' => true])); }
    public function test_production_refuses_http_app_url(): void { $this->refused($this->prod(['app.url' => 'http://dhiran.example.com'])); }
    public function test_production_refuses_log_or_array_mailer(): void
    {
        foreach (['log', 'array'] as $mailer) {
            try {
                RuntimeGuard::verifyConfig($this->prod(['mail.default' => $mailer]), dirname(__DIR__, 2));
                $this->fail("$mailer mailer accepted");
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
    public function test_production_refuses_missing_razorpay_webhook_secret(): void { $this->refused($this->prod(['services.razorpay.webhook_secret' => ''])); }
    public function test_production_refuses_sync_queue(): void { $this->refused($this->prod(['queue.default' => 'sync'])); }
    public function test_production_refuses_insecure_session_cookie(): void { $this->refused($this->prod(['session.secure' => false])); }
    public function test_production_refuses_missing_key_and_non_pgsql(): void
    {
        try {
            RuntimeGuard::verifyConfig($this->prod(['app.key' => '']), dirname(__DIR__, 2));
            $this->fail('empty key accepted');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->refused($this->prod(['database.connections.pgsql.driver' => 'mysql']));
    }

    public function test_local_checks_unchanged(): void
    {
        $this->refused($this->prod(['app.env' => 'staging']));
    }
}
