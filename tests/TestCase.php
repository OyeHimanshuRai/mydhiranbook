<?php
namespace Tests;
use App\Support\TenantContext;
use App\Support\RuntimeGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
abstract class TestCase extends BaseTestCase
{
    /** Source fixtures use Dhiran's own trial subscription in place of ERP editions. */
    protected static function createDhiranShop(array $attributes): \App\Models\Shop
    {
        $shop=\App\Models\Shop::create($attributes);
        \Illuminate\Support\Facades\DB::table('subscriptions')->insert(['shop_id'=>$shop->id,'plan'=>'synthetic-trial','status'=>'trial','expires_at'=>today()->addDays(30),'created_at'=>now(),'updated_at'=>now()]);
        return $shop;
    }
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
