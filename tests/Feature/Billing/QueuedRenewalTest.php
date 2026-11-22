<?php
namespace Tests\Feature\Billing;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\BillingFixtures;

/** Rules: a queued early renewal never hides or rewrites the started term; lapsed shops get an honest staff message; out-of-order refunds close their own alert. */
class QueuedRenewalTest extends AdminTestCase
{
    use BillingFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); }

    private function term(Shop $shop, string $start, string $end, string $status = 'active', ?string $payId = null): ShopSubscription
    {
        $p = $this->plan('dhiran_yearly');
        return ShopSubscription::create(['shop_id' => $shop->id, 'plan_id' => $p->id, 'status' => $status, 'billing_cycle' => 'yearly', 'starts_at' => $start, 'ends_at' => $end,
            'grace_ends_at' => date('Y-m-d', strtotime($end.' +14 days')), 'price_paid' => $p->price_yearly, 'razorpay_payment_id' => $payId, 'actor_type' => 'system']);
    }

    private function grant(Shop $shop, string $endsAt)
    {
        return $this->post('/admin/shops/'.$shop->id.'/subscription', ['plan' => 'dhiran_yearly', 'billing_cycle' => 'yearly', 'ends_at' => $endsAt, 'reason' => 'Goodwill']);
    }

    private function refundHook(string $paymentId, int $paise, string $refundId, string $eventId)
    {
        return $this->rawWebhook(['event' => 'refund.created', 'payload' => ['refund' => ['entity' => ['id' => $refundId, 'payment_id' => $paymentId, 'amount' => $paise, 'currency' => 'INR']]]], $eventId);
    }

    public function test_plan_page_shows_started_term_active_with_queued_renewal_line(): void
    {
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, today()->subMonths(11)->toDateString(), today()->addDays(30)->toDateString());
        $this->term($shop, today()->addDays(30)->toDateString(), today()->addDays(395)->toDateString());
        $html = $this->actingAs($owner)->get('/subscription/status')->assertOk()->getContent();
        $this->assertStringContainsString('st-badge--ok">Active<', $html);
        $this->assertStringNotContainsString('expired', $html);
        $this->assertStringContainsString('Renewal queued', $html);
        $this->assertStringContainsString(today()->addDays(30)->format('d M Y'), $html);
    }

    public function test_cancelled_queued_row_does_not_shadow_the_live_term_on_plan_page(): void
    {
        $owner = $this->shopUser(); $shop = $owner->shop;
        $live = $this->term($shop, today()->subMonths(2)->toDateString(), today()->addMonths(10)->toDateString());
        $this->term($shop, today()->addMonths(10)->toDateString(), today()->addMonths(22)->toDateString(), 'cancelled');
        $html = $this->actingAs($owner)->get('/subscription/status')->assertOk()->getContent();
        $this->assertStringContainsString('st-badge--ok">Active<', $html);
        $this->assertStringContainsString($live->ends_at->format('d M Y'), $html);
        $this->assertStringNotContainsString('Renewal queued', $html);
    }

    public function test_admin_grant_refused_while_a_renewal_is_queued_and_terminate_still_works(): void
    {
        $this->signIn($this->admin()); $shop = Shop::create(['name' => 'Queued']);
        $old = $this->term($shop, today()->subMonths(11)->toDateString(), today()->addDays(20)->toDateString());
        $new = $this->term($shop, today()->addDays(20)->toDateString(), today()->addDays(385)->toDateString());
        $snap = [$old->fresh()->only('ends_at', 'plan_id'), $new->fresh()->only('ends_at', 'plan_id')];
        $this->grant($shop, today()->addDays(500)->toDateString())->assertSessionHasErrors('ends_at');
        $this->assertSame(0, SubscriptionEvent::where('shop_id', $shop->id)->count());
        $this->assertEquals($snap, [$old->fresh()->only('ends_at', 'plan_id'), $new->fresh()->only('ends_at', 'plan_id')]);
        $this->post('/admin/shops/'.$shop->id.'/subscription/terminate', ['reason' => 'Fraud'])->assertRedirect();
        $this->assertSame(['cancelled', 'cancelled'], ShopSubscription::where('shop_id', $shop->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_lapsed_shop_staff_screens_say_plan_ended_not_negative_limit(): void
    {
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, today()->subYears(2)->toDateString(), today()->subYear()->toDateString());
        $this->assertTrue($shop->fresh()->isLapsed());
        $msg = 'Your Dhiran plan has ended. Renew it to add staff.';
        $this->actingAs($owner)->get('/staff/create')->assertRedirect()->assertSessionHas('error', $msg);
        $this->post('/staff', ['name' => 'X', 'mobile_number' => '9000000099', 'password' => 'Synthetic-pass-12345', 'password_confirmation' => 'Synthetic-pass-12345', 'role_id' => 1])->assertSessionHas('error', $msg);
    }

    public function test_out_of_order_refund_closes_its_earlier_invalid_evidence_once_applied(): void
    {
        $owner = $this->shopUser(); $t = $this->paidOrder($owner); $pay = $t['razorpay_payment_id'];
        \Illuminate\Support\Facades\Log::spy();
        $this->refundHook($pay, 1999900, 'rfnd_1', 'evt_r1')->assertStatus(500);
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('critical');
        $evidence = SubscriptionEvent::where('event_type', 'refund.invalid')->sole();
        $this->assertNull($evidence->after['resolved_at']);
        $this->capturedWebhook($t)->assertOk();
        $this->refundHook($pay, 1999900, 'rfnd_1', 'evt_r1')->assertOk();
        $evidence->refresh();
        $this->assertNotEmpty($evidence->after['resolved_at']);
        $this->assertSame('refund.invalid', $evidence->event_type); // evidence kept
    }

    public function test_partial_refund_also_closes_earlier_invalid_evidence_and_permanent_failure_stays_critical(): void
    {
        $owner = $this->shopUser(); $t = $this->paidOrder($owner); $pay = $t['razorpay_payment_id'];
        $this->refundHook($pay, 100000, 'rfnd_p', 'evt_p')->assertStatus(500);
        $this->capturedWebhook($t)->assertOk();
        $this->refundHook($pay, 100000, 'rfnd_p', 'evt_p')->assertOk();
        $this->assertNotEmpty(SubscriptionEvent::where('event_type', 'refund.invalid')->sole()->after['resolved_at']);
        // over-refund stays permanent, unresolved, and critical
        \Illuminate\Support\Facades\Log::spy();
        $this->refundHook($pay, 9999900, 'rfnd_big', 'evt_big')->assertOk();
        \Illuminate\Support\Facades\Log::shouldHaveReceived('critical')->once();
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'refund.invalid')->whereNull('after->resolved_at')->count());
    }
}
