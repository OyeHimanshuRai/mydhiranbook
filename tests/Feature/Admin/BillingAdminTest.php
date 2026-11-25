<?php
namespace Tests\Feature\Admin;
use App\Models\Platform\{Plan,ShopSubscription,SubscriptionEvent};
use App\Models\Shop;
use Database\Seeders\PlanSeeder;

class BillingAdminTest extends AdminTestCase
{
    private function grant(Shop $shop, string $plan, string $cycle, string $endsAt, string $reason='Goodwill'): \Illuminate\Testing\TestResponse
    {
        return $this->post('/admin/shops/'.$shop->id.'/subscription',['plan'=>$plan,'billing_cycle'=>$cycle,'ends_at'=>$endsAt,'reason'=>$reason]);
    }
    public function test_manual_grant_requires_reason_audited_and_recomputes_grace(): void
    {
        (new PlanSeeder)->run(); $admin=$this->signIn($this->admin()); $shop=Shop::create(['name'=>'Granted']);
        $this->grant($shop,'dhiran_monthly','monthly',today()->addMonth()->toDateString(),'')->assertSessionHasErrors('reason');
        $this->assertSame(0,ShopSubscription::count());
        $end=today()->addMonth();
        $this->grant($shop,'dhiran_monthly','monthly',$end->toDateString())->assertRedirect();
        $sub=ShopSubscription::where('shop_id',$shop->id)->sole();
        $this->assertSame([$end->toDateString(),$end->toDateString(),'monthly','active'],[$sub->ends_at->toDateString(),$sub->grace_ends_at->toDateString(),$sub->billing_cycle,$sub->status]);
        $this->assertNull($sub->price_paid); $this->assertNull($sub->razorpay_payment_id);
        $this->assertTrue($shop->fresh()->hasEdition('dhiran'));
        // upgrade: same live row, plan + cycle + grace follow the plan
        $end2=today()->addYear(); $this->grant($shop,'dhiran_yearly','yearly',$end2->toDateString())->assertRedirect();
        $this->assertSame(1,ShopSubscription::where('shop_id',$shop->id)->count());
        $sub->refresh(); $this->assertSame(['yearly',$end2->toDateString()],[$sub->billing_cycle,$sub->grace_ends_at->toDateString()]);
        $this->assertSame(2,SubscriptionEvent::where('shop_id',$shop->id)->count());
        $this->assertDatabaseHas('subscription_events',['shop_subscription_id'=>$sub->id,'admin_id'=>$admin->id,'reason'=>'Goodwill']);
        $this->assertDatabaseHas('platform_audit_logs',['actor_admin_id'=>$admin->id,'action'=>'subscription.updated','target_id'=>$shop->id]);
        // a plan cannot be granted for a cycle it does not sell
        $this->grant($shop,'dhiran_monthly','yearly',$end2->toDateString())->assertSessionHasErrors('billing_cycle');
    }
    public function test_grant_cannot_shorten_access_use_terminate(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin()); $shop=self::createDhiranShop(['name'=>'Shorten']);
        $sub=$shop->currentSubscription(); $before=$sub->only('ends_at','grace_ends_at','starts_at');
        $this->grant($shop,'dhiran_yearly','yearly',today()->subDays(30)->toDateString(),'Chargeback')->assertSessionHasErrors(['ends_at'=>'To shorten or revoke access, terminate the subscription.']);
        $this->grant($shop,'dhiran_yearly','yearly',today()->addMonths(6)->toDateString())->assertSessionHasErrors('ends_at');
        $this->assertEquals($before,$sub->fresh()->only('ends_at','grace_ends_at','starts_at'));
        $this->assertTrue($shop->fresh()->hasEdition('dhiran'));
        $this->post('/admin/shops/'.$shop->id.'/subscription/terminate',['reason'=>'Chargeback'])->assertRedirect();
        $this->assertFalse($shop->fresh()->hasEdition('dhiran'));
    }
    public function test_grant_extends_when_shop_has_two_live_rows(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin()); $shop=Shop::create(['name'=>'Two']);
        $plan=Plan::where('code','dhiran_yearly')->first();
        $mk=fn($end)=>ShopSubscription::create(['shop_id'=>$shop->id,'plan_id'=>$plan->id,'status'=>'active','billing_cycle'=>'yearly','starts_at'=>$end->copy()->subYear(),'ends_at'=>$end,'grace_ends_at'=>$end->copy()->addDays(14),'price_paid'=>19999,'actor_type'=>'self_service']);
        $old=$mk(today()->addDays(10)); $new=$mk(today()->addDays(40));
        $snap=[$old->fresh()->ends_at->toDateString(),$new->fresh()->ends_at->toDateString()];
        $this->grant($shop,'dhiran_yearly','yearly',today()->addDays(20)->toDateString())->assertSessionHasErrors('ends_at');
        $this->assertSame($snap,[$old->fresh()->ends_at->toDateString(),$new->fresh()->ends_at->toDateString()]);
        $end=today()->addDays(100); $this->grant($shop,'dhiran_yearly','yearly',$end->toDateString())->assertRedirect();
        $new->refresh(); $this->assertSame([$end->toDateString(),$end->toDateString()],[$new->ends_at->toDateString(),$new->grace_ends_at->toDateString()]);
        $this->assertSame($snap[0],$old->fresh()->ends_at->toDateString());
        $this->assertTrue($shop->fresh()->hasEdition('dhiran'));
    }
    public function test_termination_sets_cancelled_and_never_entitled(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin()); $shop=self::createDhiranShop(['name'=>'Term']);
        $this->post('/admin/shops/'.$shop->id.'/subscription/terminate',['reason'=>''])->assertSessionHasErrors('reason');
        $this->post('/admin/shops/'.$shop->id.'/subscription/terminate',['reason'=>'Fraud'])->assertRedirect();
        $this->assertSame('cancelled',ShopSubscription::where('shop_id',$shop->id)->sole()->status);
        $this->assertFalse($shop->fresh()->hasEdition('dhiran'));
        $this->assertDatabaseHas('platform_audit_logs',['action'=>'subscription.terminated','target_id'=>$shop->id]);
    }
    public function test_dashboard_counts(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin());
        self::createDhiranShop(['name'=>'Live']); $b=Shop::create(['name'=>'Susp','status'=>'suspended']); self::activeSubscription($b,'active',today()->subDays(40));
        $plan=Plan::where('code','dhiran_yearly')->first();
        Shop::create(['name'=>'Waiting','status'=>'pending']); ShopSubscription::create(['shop_id'=>null,'plan_id'=>$plan->id,'status'=>'active','billing_cycle'=>'yearly','starts_at'=>today(),'ends_at'=>today()->addYear(),'actor_type'=>'self_service']);
        $r=$this->get('/admin')->assertOk()->assertDontSee('Collected this month')->assertDontSee('Unresolved payments')->assertDontSee('/admin/unresolved-payments',false);
        $m=$r->viewData('stats');
        $this->assertSame([3,1,1,1,1],[$m['shops'],$m['active'],$m['suspended'],$m['entitled'],$m['pending']]);
        $this->get('/admin/unresolved-payments')->assertNotFound();
    }
    public function test_admin_without_permission_403(): void
    {
        (new PlanSeeder)->run(); $this->limited(['can_manage_shops','can_suspend_shop','can_view_audit_logs']); $shop=Shop::create(['name'=>'P']);
        $this->grant($shop,'dhiran_monthly','monthly',today()->addMonth()->toDateString())->assertForbidden();
        $this->post('/admin/shops/'.$shop->id.'/subscription/terminate',['reason'=>'x'])->assertForbidden();
        $this->assertSame(0,ShopSubscription::count());
    }
}
