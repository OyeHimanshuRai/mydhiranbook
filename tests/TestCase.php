<?php
namespace Tests;
use App\Support\TenantContext;
use App\Support\RuntimeGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void { TenantContext::clear(); parent::setUp(); \Illuminate\Support\Facades\Cache::flush(); }
    protected function tearDown(): void { TenantContext::clear(); parent::tearDown(); }
    protected function refreshApplication(): void
    {
        parent::refreshApplication();
        if (!$this->app->environment('testing')) { throw new \RuntimeException('Tests require dhiran_test'); }
        RuntimeGuard::verify($this->app);
        RuntimeGuard::verifyConnection(\Illuminate\Support\Facades\DB::connection()->getPdo(), 'testing', base_path());
    }
}
