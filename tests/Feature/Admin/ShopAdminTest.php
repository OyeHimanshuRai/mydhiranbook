<?php
namespace Tests\Feature\Admin;
use App\Models\Platform\{PlatformInvoice,SubscriptionEvent};
use App\Models\{Shop,User};

class ShopAdminTest extends AdminTestCase
{
    public function test_admin_lists_and_searches_shops(): void
    {
        $this->signIn($this->admin());
        Shop::create(['name'=>'Alpha Pawn']); Shop::create(['name'=>'Beta Loans']);
        $this->get('/admin/shops')->assertOk()->assertSee('Alpha Pawn')->assertSee('Beta Loans');
        $this->get('/admin/shops?q=alpha')->assertOk()->assertSee('Alpha Pawn')->assertDontSee('Beta Loans');
    }
    public function test_shop_detail_shows_subscriptions_and_receipts(): void
    {
        $this->signIn($this->admin());
        $shop=Shop::create(['name'=>'Detail Shop']); $sub=self::activeSubscription($shop);
        User::create(['name'=>'Owner Person','email'=>'own@example.test','password'=>'Owner-pass-12345','shop_id'=>$shop->id,'mobile_number'=>'9876543210']);
        SubscriptionEvent::create(['shop_subscription_id'=>$sub->id,'shop_id'=>$shop->id,'event_type'=>'payment.captured','reason'=>'History marker']);
        $inv=PlatformInvoice::create(['shop_id'=>$shop->id,'shop_subscription_id'=>$sub->id,'plan_id'=>$sub->plan_id,'invoice_number'=>'DHR-2026-000777','invoice_sequence'=>777,'billing_cycle'=>'yearly','billing_period_start'=>today(),'billing_period_end'=>today()->addYear(),'amount_before_tax'=>19999,'total_amount'=>19999,'issued_at'=>now()]);
        $this->get('/admin/shops/'.$shop->id)->assertOk()->assertSee('Owner Person')->assertSee('own@example.test')->assertSee('dhiran_yearly')->assertSee('History marker')->assertSee('DHR-2026-000777')->assertSee('/admin/receipts/'.$inv->id,false);
        $this->get('/admin/receipts/'.$inv->id)->assertOk()->assertSee('DHR-2026-000777');
    }
    public function test_suspend_requires_reason_and_blocks_shop_login_access(): void
    {
        $admin=$this->signIn($this->admin());
        $shop=self::createDhiranShop(['name'=>'Suspend Me']); $owner=self::userWithRole($shop,['name'=>'O','email'=>'o@example.test','password'=>'Owner-pass-12345']); \App\Support\TenantContext::runFor($shop->id,fn()=>\App\Models\Dhiran\DhiranSettings::getForShop($shop->id)->update(['is_enabled'=>true]));
        $this->patch('/admin/shops/'.$shop->id,['status'=>'suspended'])->assertSessionHasErrors('reason');
        $this->assertSame('active',$shop->fresh()->status);
        $this->patch('/admin/shops/'.$shop->id,['status'=>'suspended','reason'=>'Non-payment'])->assertRedirect();
        $this->assertSame('suspended',$shop->fresh()->status);
        $this->assertDatabaseHas('platform_audit_logs',['actor_admin_id'=>$admin->id,'action'=>'shop.updated','target_id'=>$shop->id,'reason'=>'Non-payment']);
        \Illuminate\Support\Facades\Auth::shouldUse('web'); // one app instance serves both sessions in a test
        $this->actingAs($owner)->get('/dhiran/borrowers')->assertForbidden();
        \Illuminate\Support\Facades\Auth::shouldUse('platform_admin');
        $this->patch('/admin/shops/'.$shop->id,['status'=>'active','reason'=>'Paid'])->assertRedirect();
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $this->actingAs($owner->fresh())->get('/dhiran/borrowers')->assertOk(); // fresh: drops the cached suspended shop relation
    }
    public function test_admin_without_permission_403(): void
    {
        $this->limited([]); $shop=Shop::create(['name'=>'Protected']);
        $this->get('/admin/shops')->assertForbidden();
        $this->get('/admin/shops/'.$shop->id)->assertForbidden();
        $this->patch('/admin/shops/'.$shop->id,['status'=>'suspended','reason'=>'x'])->assertForbidden();
        $this->assertSame('active',$shop->fresh()->status);
    }
    public function test_shop_owner_session_cannot_reach_admin(): void
    {
        $shop=Shop::create(['name'=>'S']); $owner=User::create(['name'=>'O','email'=>'o@example.test','password'=>'Owner-pass-12345','shop_id'=>$shop->id]);
        foreach (['/admin','/admin/shops','/admin/plans','/admin/unresolved-payments'] as $url) { $this->actingAs($owner)->get($url)->assertRedirect('/admin/login'); }
        $this->actingAs($owner)->patch('/admin/shops/'.$shop->id,['status'=>'suspended','reason'=>'x'])->assertRedirect('/admin/login');
        $this->assertSame('active',$shop->fresh()->status);
    }
    public function test_first_term_receipt_and_paid_event_follow_the_shop_after_onboarding(): void
    {
        \Illuminate\Support\Facades\Mail::fake(); (new \Database\Seeders\PlanSeeder)->run();
        $rz=new \Tests\Support\FakeRazorpayClient; $this->app->instance(\App\Services\Billing\RazorpayClient::class,$rz);
        $u=User::create(['name'=>'Buyer','email'=>'buyer@example.test','mobile_number'=>'9000000999','password'=>'a-long-test-password','role'=>'owner','terms_accepted_at'=>now(),'email_verified_at'=>now()]);
        $plan=\App\Models\Platform\Plan::where('code','dhiran_yearly')->firstOrFail();
        $rz->seedOrder('order_a',$plan->priceFor('yearly')*100,['plan_id'=>$plan->id,'billing_cycle'=>'yearly','user_id'=>$u->id]); $rz->seedPayment('pay_a','order_a');
        $this->actingAs($u)->post('/subscription/payment/callback',['razorpay_order_id'=>'order_a','razorpay_payment_id'=>'pay_a','razorpay_signature'=>\Tests\Support\FakeRazorpayClient::paymentSignature('order_a','pay_a')]);
        $this->actingAs($u)->post(route('dhiran.onboarding.store'),['name'=>'Sharma Gold','phone'=>'9876543210','address'=>'12 Main Road, Indore'])->assertRedirect('/');
        $shop=Shop::sole(); $inv=PlatformInvoice::sole();
        \Illuminate\Support\Facades\Auth::shouldUse('web'); $this->signIn($this->admin());
        $this->get('/admin/shops/'.$shop->id)->assertOk()->assertSee($inv->invoice_number)->assertSee('subscription.paid');
    }
}
