<?php
namespace Tests;
use App\Support\TenantContext;
use App\Support\RuntimeGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
abstract class TestCase extends BaseTestCase
{
    /** Give a shop a paid Dhiran subscription (yearly plan) in the given status. */
    protected static function activeSubscription(\App\Models\Shop $shop, string $status='active', ?\Illuminate\Support\Carbon $endsAt=null): \App\Models\Platform\ShopSubscription
    {
        (new \Database\Seeders\PlanSeeder)->run();
        $plan=\App\Models\Platform\Plan::where('code','dhiran_yearly')->firstOrFail();
        $endsAt??=today()->addYear();
        return \App\Models\Platform\ShopSubscription::create(['shop_id'=>$shop->id,'plan_id'=>$plan->id,'status'=>$status,'billing_cycle'=>'yearly','starts_at'=>$endsAt->copy()->subYear(),'ends_at'=>$endsAt,'grace_ends_at'=>$endsAt->copy()->addDays($plan->grace_days),'price_paid'=>$plan->price_yearly,'actor_type'=>'system']);
    }
    /** Source fixtures get an active paid subscription in place of ERP editions. */
    protected static function createDhiranShop(array $attributes): \App\Models\Shop
    {
        $shop=\App\Models\Shop::create($attributes);
        self::activeSubscription($shop);
        return $shop;
    }
    /** A shop user holding the shop's real role (users.role grants nothing). */
    protected static function userWithRole(\App\Models\Shop $shop, array $attributes, string $role='owner'): \App\Models\User
    {
        $roles=app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        return \App\Models\User::create($attributes+['shop_id'=>$shop->id,'role_id'=>$roles[$role]->id]);
    }
    /** One test session is reused across requests, so a bound password hash from the previous user would log the next one out (AuthenticateSession). */
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        $this->app['session']->forget('password_hash_web');
        return parent::actingAs($user, $guard);
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
