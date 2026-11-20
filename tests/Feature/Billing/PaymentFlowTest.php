<?php
namespace Tests\Feature\Billing;

use App\Mail\UnresolvedPaymentAlert;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); }

    private function payCallback(array $triple) { return $this->post('/subscription/payment/callback', $triple); }

    public function test_callback_with_valid_signature_creates_pending_subscription_without_shop(): void
    {
        $user = $this->shoplessUser(); $triple = $this->paidOrder($user);
        $this->actingAs($user)->payCallback($triple)->assertRedirect(route('subscription.status'));
        $sub = ShopSubscription::sole();
        $this->assertNull($sub->shop_id); $this->assertSame($user->id, $sub->user_id); $this->assertSame('active', $sub->status);
        $this->assertSame('yearly', $sub->billing_cycle); $this->assertSame($triple['razorpay_payment_id'], $sub->razorpay_payment_id);
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.paid')->count());
    }

    public function test_callback_for_existing_shop_renews_and_extends_ends_at(): void
    {
        $owner = $this->shopUser(); $shop = $owner->shop;
        $old = self::activeSubscription($shop, 'active', today()->addDays(10));
        $this->actingAs($owner)->payCallback($this->paidOrder($owner))->assertRedirect(route('subscription.status'));
        $this->assertSame(2, ShopSubscription::where('shop_id', $shop->id)->count());
        $new = ShopSubscription::where('shop_id', $shop->id)->where('id', '!=', $old->id)->sole();
        $this->assertSame(today()->addDays(11)->toDateString(), $new->starts_at->toDateString());
        $this->assertSame(today()->addDays(10)->addYearNoOverflow()->toDateString(), $new->ends_at->toDateString());
        $this->assertSame($shop->id, $new->shop_id);
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.renewed')->count());
        $this->assertTrue($shop->hasEdition('dhiran'));
    }

    public function test_renewing_after_lapse_starts_a_fresh_term_today(): void
    {
        $owner = $this->shopUser();
        self::activeSubscription($owner->shop, 'expired', today()->subDays(60));
        $this->actingAs($owner)->payCallback($this->paidOrder($owner));
        $new = ShopSubscription::where('shop_id', $owner->shop_id)->orderByDesc('id')->first();
        $this->assertSame(today()->toDateString(), $new->starts_at->toDateString());
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
    }

    public function test_invalid_signature_rejected_no_rows(): void
    {
        $user = $this->shoplessUser(); $triple = $this->paidOrder($user); $triple['razorpay_signature'] = 'forged';
        $this->actingAs($user)->payCallback($triple)->assertRedirect(route('subscription.payment'));
        $this->assertSame(0, ShopSubscription::count()); $this->assertSame(0, SubscriptionEvent::count());
    }

    public function test_amount_mismatch_records_unresolved_and_grants_nothing(): void
    {
        $user = $this->shoplessUser(); $triple = $this->paidOrder($user, null, 'captured', 100);
        $this->actingAs($user)->payCallback($triple)->assertRedirect(route('subscription.payment'));
        $this->assertSame(0, ShopSubscription::count());
        $event = SubscriptionEvent::where('event_type', 'payment.unresolved')->sole();
        $this->assertSame($triple['razorpay_payment_id'], $event->after['payment_id']);
        $this->assertNull($event->shop_subscription_id);
    }

    public function test_uncaptured_payment_grants_nothing(): void
    {
        $user = $this->shoplessUser();
        $this->actingAs($user)->payCallback($this->paidOrder($user, null, 'authorized'));
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_foreign_currency_order_is_not_applied(): void
    {
        $user = $this->shoplessUser(); $triple = $this->paidOrder($user);
        $this->razorpay->orders[$triple['razorpay_order_id']]->currency = 'USD';
        $this->actingAs($user)->payCallback($triple);
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_callback_owned_by_other_user_rejected(): void
    {
        $payer = $this->shoplessUser(); $thief = $this->shoplessUser(); $triple = $this->paidOrder($payer);
        $this->actingAs($thief)->payCallback($triple)->assertRedirect(route('subscription.payment'));
        $this->assertSame(0, ShopSubscription::count());
        $this->actingAs($payer)->payCallback($triple); // the real payer can still claim it
        $this->assertSame($payer->id, ShopSubscription::sole()->user_id);
    }

    public function test_staff_cannot_pay_or_open_checkout(): void
    {
        $owner = $this->shopUser(); $cashier = $this->shopUser('cashier');
        $this->actingAs($cashier)->post('/subscription/choose', ['plan_id' => $this->plan()->id, 'billing_cycle' => 'yearly'])->assertForbidden();
        $this->actingAs($cashier)->get('/subscription/payment')->assertForbidden();
        $this->actingAs($cashier)->postJson('/subscription/payment/initiate')->assertForbidden();
        $this->actingAs($cashier)->get('/subscription/status')->assertForbidden();
        $this->actingAs($cashier)->payCallback($this->paidOrder($cashier))->assertForbidden();
        $this->assertSame(0, $this->razorpay->networkCalls);
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/subscription/status')->assertRedirect(route('login'));
    }

    public function test_choose_then_initiate_creates_server_priced_order(): void
    {
        $user = $this->shoplessUser(); $plan = $this->plan('dhiran_monthly');
        $this->actingAs($user)->post('/subscription/choose', ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])->assertRedirect(route('subscription.payment'));
        $this->get('/subscription/payment')->assertOk()->assertSee('checkout.razorpay.com/v1/checkout.js', false)->assertSee('1,852');
        $this->postJson('/subscription/payment/initiate', ['amount' => 1])->assertOk()->assertJsonPath('amount', 185200)->assertJsonPath('currency', 'INR');
        $order = array_values($this->razorpay->orders)[0];
        $this->assertSame(185200, $order->amount);
        $this->assertSame(['plan_id' => $plan->id, 'plan_name' => $plan->name, 'billing_cycle' => 'monthly', 'user_id' => $user->id], $order->notes);
    }

    public function test_choose_rejects_cycle_the_plan_does_not_sell(): void
    {
        $user = $this->shoplessUser();
        $this->actingAs($user)->post('/subscription/choose', ['plan_id' => $this->plan('dhiran_monthly')->id, 'billing_cycle' => 'yearly'])->assertSessionHasErrors('billing_cycle');
        $this->post('/subscription/choose', ['plan_id' => $this->plan('dhiran_yearly')->id, 'billing_cycle' => 'monthly'])->assertSessionHasErrors('billing_cycle');
    }

    public function test_payment_pages_other_than_checkout_do_not_load_razorpay_js(): void
    {
        $owner = $this->shopUser(); self::activeSubscription($owner->shop);
        $this->actingAs($owner)->get('/subscription/status')->assertOk()->assertDontSee('checkout.razorpay.com', false)->assertSee('Dhiran Yearly')->assertSee('Dhiran Monthly');
    }

    public function test_unresolved_payment_emails_alert_to_billing_alert_email(): void
    {
        Mail::fake(); config(['billing.alert_email' => 'ops@dhiran.test']);
        $user = $this->shoplessUser();
        $this->actingAs($user)->payCallback($this->paidOrder($user, null, 'captured', 100));
        Mail::assertQueued(UnresolvedPaymentAlert::class, fn ($m) => $m->hasTo('ops@dhiran.test'));
    }

    public function test_no_alert_when_alert_email_unset(): void
    {
        Mail::fake();
        $user = $this->shoplessUser();
        $this->actingAs($user)->payCallback($this->paidOrder($user, null, 'captured', 100));
        Mail::assertNothingQueued(); Mail::assertNothingSent();
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'payment.unresolved')->count());
    }

    public function test_repeated_failure_updates_one_record_and_alerts_once(): void
    {
        Mail::fake(); config(['billing.alert_email' => 'ops@dhiran.test']);
        $user = $this->shoplessUser(); $triple = $this->paidOrder($user, null, 'captured', 100);
        $this->actingAs($user)->payCallback($triple); $this->payCallback($triple);
        $event = SubscriptionEvent::where('event_type', 'payment.unresolved')->sole();
        $this->assertSame(2, $event->after['attempt_count']);
        Mail::assertQueued(UnresolvedPaymentAlert::class, 1);
    }

    public function test_second_payment_on_an_already_paid_order_is_not_applied(): void
    {
        $user = $this->shoplessUser(); $triple = $this->paidOrder($user);
        $this->actingAs($user)->payCallback($triple);
        $this->razorpay->seedPayment('pay_dup', $triple['razorpay_order_id']);
        $this->payCallback(['razorpay_order_id' => $triple['razorpay_order_id'], 'razorpay_payment_id' => 'pay_dup', 'razorpay_signature' => \Tests\Support\FakeRazorpayClient::paymentSignature($triple['razorpay_order_id'], 'pay_dup')]);
        $this->assertSame(1, ShopSubscription::count());
        $this->assertSame('pay_dup', SubscriptionEvent::where('event_type', 'payment.unresolved')->sole()->after['payment_id']);
    }
}
