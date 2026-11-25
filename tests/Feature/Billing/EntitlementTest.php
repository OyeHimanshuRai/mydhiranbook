<?php
namespace Tests\Feature\Billing;
use App\Models\Platform\Plan;
use App\Models\Shop;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop { return Shop::create(['name'=>'Synthetic billing shop']); }

    public function test_active_subscription_grants_dhiran(): void
    {
        $shop=$this->shop(); self::activeSubscription($shop);
        $this->assertTrue($shop->hasEdition('dhiran')); $this->assertFalse($shop->isLapsed()); $this->assertFalse($shop->hasEdition('retailer'));
    }
    public function test_grace_subscription_grants_dhiran(): void
    {
        $shop=$this->shop(); self::activeSubscription($shop,'grace',today()->subDay());
        $this->assertTrue($shop->hasEdition('dhiran'));
    }
    public function test_expired_subscription_is_lapsed(): void
    {
        $shop=$this->shop(); self::activeSubscription($shop,'expired',today()->subDays(30));
        $this->assertFalse($shop->hasEdition('dhiran')); $this->assertTrue($shop->isLapsed());
        $past=$this->shop(); self::activeSubscription($past,'active',today()->subDays(30));
        $this->assertTrue($past->isLapsed());
        $pastGrace=$this->shop(); self::activeSubscription($pastGrace,'grace',today()->subDays(20));
        $this->assertTrue($pastGrace->isLapsed());
    }
    public function test_active_past_ends_at_within_grace_window_still_entitled(): void
    {
        $shop=$this->shop(); $sub=self::activeSubscription($shop,'active',today()->subDays(2));
        $sub->update(['grace_ends_at'=>today()->addDays(5)]);
        $this->assertTrue($shop->hasEdition('dhiran'));
    }
    public function test_active_past_grace_ends_at_not_entitled(): void
    {
        $shop=$this->shop(); $sub=self::activeSubscription($shop,'active',today()->subDays(10));
        $sub->update(['grace_ends_at'=>today()->subDay()]);
        $this->assertFalse($shop->hasEdition('dhiran'));
    }
    public function test_no_subscription_is_not_entitled(): void
    {
        $shop=$this->shop();
        $this->assertFalse($shop->hasEdition('dhiran')); $this->assertTrue($shop->isLapsed());
        $this->assertSame(-1,$shop->staffLimit()); $this->assertSame(-1,$shop->itemLimit());
    }
    public function test_staff_and_item_limits_come_from_plan(): void
    {
        $shop=$this->shop(); $sub=self::activeSubscription($shop);
        $this->assertSame([7,3000],[$shop->staffLimit(),$shop->itemLimit()]);
        $sub->update(['plan_id'=>Plan::where('code','dhiran_monthly')->value('id'),'billing_cycle'=>'monthly']);
        $shop=$shop->fresh(); $this->assertSame([3,1000],[$shop->staffLimit(),$shop->itemLimit()]);
    }
    public function test_plan_seeder_values(): void
    {
        (new PlanSeeder)->run(); (new PlanSeeder)->run();
        $this->assertSame(2,Plan::count());
        $m=Plan::where('code','dhiran_monthly')->firstOrFail(); $y=Plan::where('code','dhiran_yearly')->firstOrFail();
        $this->assertSame([1852,7],[(int)$m->price_monthly,$m->grace_days]);
        $this->assertSame([19999,14],[(int)$y->price_yearly,$y->grace_days]);
    }
    public function test_registration_creates_no_trial(): void
    {
        $this->post('/register',['name'=>'Demo Owner','email'=>'notrial@example.test','mobile'=>'9812300077','terms'=>'1','password'=>'Local-pass-12345','password_confirmation'=>'Local-pass-12345'])->assertRedirect('/');
        $this->assertDatabaseCount('shops',0);
        $this->assertDatabaseCount('shop_subscriptions',0);
    }
}
