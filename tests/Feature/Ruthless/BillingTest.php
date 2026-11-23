<?php
namespace Tests\Feature\Ruthless;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\User;
use App\Services\Billing\PlatformInvoiceService;
use App\Services\DhiranService;
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\BillingFixtures;
use Tests\Support\FakeRazorpayClient;

/** Adversarial billing suite. Every test states the business rule it enforces. */
class BillingTest extends AdminTestCase
{
    use BillingFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function freeze(string $ist): void { Carbon::setTestNow(Carbon::parse($ist, 'Asia/Kolkata')); }
    private function cb(array $triple) { return $this->post('/subscription/payment/callback', $triple); }
    private function reconcile(): void { $this->artisan('subscription:reconcile-payments', ['--sleep-ms' => 0])->assertSuccessful(); }
    private function counter(int $year): ?int { $v = DB::table('platform_counters')->where('key', "receipt:DHR:$year")->value('value'); return $v === null ? null : (int) $v; }
    private function types(?int $subId = null): array { return SubscriptionEvent::when($subId, fn ($q) => $q->where('shop_subscription_id', $subId))->orderBy('id')->pluck('event_type')->all(); }

    private function refund(string $paymentId, int $paise, string $refundId, string $eventId, string $currency = 'INR')
    {
        return $this->rawWebhook(['event' => 'refund.created', 'payload' => ['refund' => ['entity' => ['id' => $refundId, 'payment_id' => $paymentId, 'amount' => $paise, 'currency' => $currency]]]], $eventId);
    }

    private function failedHook(string $orderId, string $eventId)
    {
        return $this->rawWebhook(['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_f', 'order_id' => $orderId, 'error_description' => 'card declined']]]], $eventId);
    }

    private function term(\App\Models\Shop $shop, string $plan, string $start, string $end, string $grace, string $status = 'active', ?string $payId = null): ShopSubscription
    {
        $p = $this->plan($plan);
        return ShopSubscription::create(['shop_id' => $shop->id, 'plan_id' => $p->id, 'status' => $status, 'billing_cycle' => $p->price_yearly ? 'yearly' : 'monthly',
            'starts_at' => $start, 'ends_at' => $end, 'grace_ends_at' => $grace, 'price_paid' => $p->price_yearly ?? $p->price_monthly, 'razorpay_payment_id' => $payId, 'actor_type' => 'system']);
    }

    // ───────────────────────── webhook ordering ─────────────────────────

    public function test_refund_arriving_before_capture_is_recorded_as_invalid_and_grants_nothing(): void
    {
        // Rule: a refund for an unknown payment never creates or cancels anything; it leaves refund.invalid evidence
        // and answers 500 so Razorpay redelivers (webhooks arrive out of order; the capture may land later).
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_1', 'evt_r1')->assertStatus(500)->assertJson(['status' => 'retry']);
        $this->assertSame(0, ShopSubscription::count());
        $this->assertSame(['refund.invalid'], $this->types());
    }

    public function test_refund_for_an_unresolved_payment_is_answered_permanently_not_retried_forever(): void
    {
        // Rule: a payment parked as payment.unresolved (e.g. amount mismatch) never gets a subscription. If an admin then
        // refunds it in Razorpay, the refund webhook must be answered 200 (evidence kept), not 500 forever — Razorpay
        // retries a failing endpoint and eventually disables the webhook, which would drop real payment events.
        $this->assertSame(0, ShopSubscription::count());
        \App\Models\Platform\SubscriptionEvent::create(['shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'payment.unresolved', 'before' => null,
            'after' => ['order_id' => 'order_x', 'payment_id' => 'pay_unres', 'attempt_count' => 1, 'resolved_at' => null], 'reason' => 'amount mismatch']);
        $this->refund('pay_unres', 1999900, 'rfnd_u', 'evt_ru')->assertOk()->assertJson(['status' => 'not_applied']);
        $this->assertContains('refund.invalid', $this->types()); // evidence kept
        $this->assertSame(0, ShopSubscription::count());
    }
    public function test_refund_for_a_transiently_parked_payment_is_still_retried(): void
    {
        // Rule: a transient failure (provider hiccup) will be reconciled into a subscription later, so the refund must be
        // redelivered until it can apply — answering final would leave a refunded term active.
        \App\Models\Platform\SubscriptionEvent::create(['shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'payment.unresolved', 'before' => null,
            'after' => ['order_id' => 'order_t', 'payment_id' => 'pay_trans', 'attempt_count' => 1, 'transient' => true, 'resolved_at' => null], 'reason' => 'transient: timeout']);
        $this->refund('pay_trans', 1999900, 'rfnd_t', 'evt_rt')->assertStatus(500)->assertJson(['status' => 'retry']);
    }

    public function test_a_payment_refunded_while_parked_is_never_granted_by_a_later_capture(): void
    {
        // Rule: once a refund was seen for a parked payment, a later capture/reconcile must not grant a term for refunded money.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        \App\Models\Platform\SubscriptionEvent::create(['shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'payment.unresolved', 'before' => null,
            'after' => ['order_id' => $t['razorpay_order_id'], 'payment_id' => $t['razorpay_payment_id'], 'attempt_count' => 1, 'transient' => false, 'resolved_at' => null], 'reason' => 'refused']);
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_p', 'evt_rp')->assertOk()->assertJson(['status' => 'not_applied']);
        $this->capturedWebhook($t, 'cap_after_refund');
        $this->assertSame(0, ShopSubscription::count());
    }
    public function test_redelivered_refund_keeps_the_parked_marker_when_the_park_turns_final(): void
    {
        // Rule: the same refund event is first retried (transient park, evidence row without marker), then the park becomes
        // final; the redelivery must update that evidence row WITH the marker, or a later capture would grant refunded money.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        $park = \App\Models\Platform\SubscriptionEvent::create(['shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'payment.unresolved', 'before' => null,
            'after' => ['order_id' => $t['razorpay_order_id'], 'payment_id' => $t['razorpay_payment_id'], 'attempt_count' => 1, 'transient' => true, 'resolved_at' => null], 'reason' => 'transient']);
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_m', 'evt_rm')->assertStatus(500);
        $park->update(['after' => array_merge($park->after, ['transient' => false])]);
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_m', 'evt_rm')->assertOk();
        $this->capturedWebhook($t, 'cap_m');
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_refund_for_an_admin_dismissed_payment_is_answered_permanently(): void
    {
        // Rule: an admin who marks a parked payment resolved has handled it by hand (refund/ignore); a later refund webhook
        // finds no subscription and must not be retried forever (500 loop).
        \App\Models\Platform\SubscriptionEvent::create(['shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'payment.unresolved', 'before' => null,
            'after' => ['order_id' => 'order_a', 'payment_id' => 'pay_admin', 'attempt_count' => 1, 'transient' => false, 'resolved_at' => now()->toIso8601String(), 'resolved_by_admin_id' => 1, 'resolution_note' => 'refunded in dashboard'], 'reason' => 'refused [RESOLVED by admin]']);
        $this->refund('pay_admin', 1999900, 'rfnd_a', 'evt_ra')->assertOk()->assertJson(['status' => 'not_applied']);
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_a_payment_an_admin_dismissed_is_never_granted_by_a_later_capture(): void
    {
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        \App\Models\Platform\SubscriptionEvent::create(['shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'payment.unresolved', 'before' => null,
            'after' => ['order_id' => $t['razorpay_order_id'], 'payment_id' => $t['razorpay_payment_id'], 'attempt_count' => 1, 'transient' => false, 'resolved_at' => now()->toIso8601String(), 'resolved_by_admin_id' => 1, 'resolution_note' => 'ignore'], 'reason' => 'refused [RESOLVED by admin]']);
        $this->capturedWebhook($t, 'cap_dismissed');
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_refund_redelivered_after_capture_cancels_the_term(): void
    {
        // Rule: money refunded at Razorpay must end the term. A refund seen before the capture was webhook-ed
        // (out-of-order delivery) and redelivered under the SAME event id must be applied once the payment is known.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_1', 'evt_r1');
        $this->capturedWebhook($t, 'evt_c1')->assertOk();
        $this->assertSame('active', ShopSubscription::sole()->status);
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_1', 'evt_r1')->assertOk();
        $this->assertSame('cancelled', ShopSubscription::sole()->status);
        $this->assertFalse($owner->shop->fresh()->hasEdition('dhiran'));
    }

    public function test_refund_with_new_event_id_after_capture_cancels_the_term(): void
    {
        // Rule: out-of-order refund with a fresh event id (provider re-emits) applies normally.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_1', 'evt_r1');
        $this->capturedWebhook($t, 'evt_c1');
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_1', 'evt_r2')->assertOk();
        $this->assertSame('cancelled', ShopSubscription::sole()->status);
        $this->assertFalse($owner->shop->fresh()->hasEdition('dhiran'));
    }

    public function test_payment_failed_after_capture_never_touches_the_paid_term(): void
    {
        // Rule: payment.failed is evidence only (a second, abandoned attempt on the same order must not revoke access).
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        $this->capturedWebhook($t)->assertOk();
        $before = ShopSubscription::sole()->only('status', 'ends_at', 'grace_ends_at', 'price_paid');
        $this->failedHook($t['razorpay_order_id'], 'evt_f1')->assertOk();
        $this->assertEquals($before, ShopSubscription::sole()->only('status', 'ends_at', 'grace_ends_at', 'price_paid'));
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'payment.failed')->count());
    }

    public function test_payment_failed_before_capture_does_not_block_the_later_capture(): void
    {
        // Rule: a failed attempt followed by a successful one on the same order still yields exactly one term.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        $this->failedHook($t['razorpay_order_id'], 'evt_f1')->assertOk();
        $this->capturedWebhook($t, 'evt_c1')->assertOk();
        $this->assertSame(1, ShopSubscription::count());
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
    }

    public function test_same_captured_event_id_redelivered_many_times_is_one_term_one_receipt(): void
    {
        // Rule: webhooks are at-least-once; N deliveries of one capture = 1 term, 1 paid event, 1 receipt, counter 1.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        foreach (range(1, 5) as $_) { $this->capturedWebhook($t, 'evt_same')->assertOk(); }
        $this->assertSame(1, ShopSubscription::count());
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.paid')->count());
        $this->assertSame(1, PlatformInvoice::count());
        $this->assertSame(1, $this->counter(now()->year));
    }

    public function test_different_event_ids_for_the_same_payment_are_still_one_term(): void
    {
        // Rule: idempotency is keyed on the payment id, not the delivery's event id.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        foreach (['evt_a', 'evt_b', 'evt_c'] as $e) { $this->capturedWebhook($t, $e)->assertOk(); }
        $this->assertSame(1, ShopSubscription::count());
        $this->assertSame(1, PlatformInvoice::count());
    }

    public static function orders(): array
    {
        $all = [['cb', 'wh', 'rc'], ['cb', 'rc', 'wh'], ['wh', 'cb', 'rc'], ['wh', 'rc', 'cb'], ['rc', 'cb', 'wh'], ['rc', 'wh', 'cb']];
        return array_combine(array_map(fn ($p) => implode('>', $p), $all), array_map(fn ($p) => [$p], $all));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function test_callback_webhook_reconcile_in_any_order_apply_the_payment_exactly_once(array $order): void
    {
        // Rule: one captured payment = one term, one paid event, one receipt, zero unresolved noise, in every arrival order.
        $user = $this->shoplessUser(); $t = $this->paidOrder($user);
        $this->actingAs($user);
        foreach ($order as $path) {
            match ($path) { 'cb' => $this->cb($t), 'wh' => $this->capturedWebhook($t, 'evt_'.$path)->assertOk(), 'rc' => $this->reconcile() };
        }
        $this->assertSame(1, ShopSubscription::count());
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.paid')->count());
        $this->assertSame(0, SubscriptionEvent::where('event_type', 'payment.unresolved')->count());
        $this->assertSame(1, PlatformInvoice::count());
        $this->assertSame(1, $this->counter(now()->year));
    }

    public function test_webhook_with_unparseable_body_is_acknowledged_without_side_effects(): void
    {
        // Rule: a signed but garbage body must not 500 forever (Razorpay would retry) nor mutate anything.
        $body = 'not-json';
        $this->call('POST', '/subscription/payment/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => FakeRazorpayClient::webhookSignature($body), 'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_g'], $body)->assertOk();
        $this->assertSame(0, SubscriptionEvent::count());
    }

    public function test_captured_webhook_without_order_id_is_recorded_unresolved_and_acknowledged(): void
    {
        // Rule: nothing can be applied without an order; evidence kept, 200 so Razorpay stops retrying.
        $this->rawWebhook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_orphan']]]], 'evt_o')->assertOk();
        $this->assertSame(0, ShopSubscription::count());
        $this->assertSame('pay_orphan', SubscriptionEvent::where('event_type', 'payment.unresolved')->sole()->after['payment_id']);
    }

    public function test_captured_webhook_without_payment_id_is_acknowledged_and_writes_nothing(): void
    {
        $this->rawWebhook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['order_id' => 'order_x']]]], 'evt_n')->assertOk();
        $this->assertSame(0, SubscriptionEvent::count());
    }

    // ───────────────────────── amount / currency / plan drift ─────────────────────────

    public static function priceMoves(): array { return ['price raised' => [24999], 'price lowered' => [14999]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('priceMoves')]
    public function test_plan_price_changed_between_order_and_capture_is_refused_then_applies_once_price_is_restored(int $newPrice): void
    {
        // Rule: the captured order must equal the plan's CURRENT server price; otherwise nothing is granted and
        // an unresolved record exists for admin. Restoring the price lets a manual reconcile apply it exactly once.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        Plan::where('code', 'dhiran_yearly')->update(['price_yearly' => $newPrice]);
        $this->capturedWebhook($t)->assertOk()->assertJson(['status' => 'not_applied']);
        $this->assertSame(0, ShopSubscription::count());
        $ev = SubscriptionEvent::where('event_type', 'payment.unresolved')->sole();
        $this->assertStringContainsString('amount mismatch', $ev->reason);
        $this->assertFalse((bool) $ev->after['transient']);

        Plan::where('code', 'dhiran_yearly')->update(['price_yearly' => 19999]);
        $this->artisan('subscription:reconcile-payments', ['--payment' => $t['razorpay_payment_id'], '--order' => $t['razorpay_order_id']])->assertSuccessful();
        $this->assertSame(1, ShopSubscription::count());
        $this->assertNotNull(SubscriptionEvent::where('event_type', 'payment.unresolved')->sole()->after['resolved_at']);
    }

    public function test_deactivated_plan_payment_is_not_applied_until_plan_is_reactivated(): void
    {
        // Rule: money for a plan switched off after checkout is held for admin, never silently granted; once reactivated it applies once.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        Plan::where('code', 'dhiran_yearly')->update(['is_active' => false]);
        $this->capturedWebhook($t)->assertOk()->assertJson(['status' => 'not_applied']);
        $this->assertSame(0, ShopSubscription::count());
        $this->assertStringContainsString('is not active', SubscriptionEvent::where('event_type', 'payment.unresolved')->sole()->reason);
        Plan::where('code', 'dhiran_yearly')->update(['is_active' => true]);
        $this->artisan('subscription:reconcile-payments', ['--payment' => $t['razorpay_payment_id'], '--order' => $t['razorpay_order_id']])->assertSuccessful();
        $this->assertSame(1, ShopSubscription::count());
    }

    public function test_cheap_order_with_expensive_plan_in_notes_is_refused(): void
    {
        // Rule: plan comes from notes but price is verified; a monthly-priced order cannot buy the yearly plan.
        $owner = $this->shopUser();
        $this->razorpay->seedOrder('order_cheat', 185200, ['plan_id' => $this->plan()->id, 'billing_cycle' => 'yearly', 'user_id' => $owner->id]);
        $this->razorpay->seedPayment('pay_cheat', 'order_cheat');
        $this->capturedWebhook(['razorpay_order_id' => 'order_cheat', 'razorpay_payment_id' => 'pay_cheat'])->assertOk();
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_cycle_the_plan_does_not_sell_is_refused_both_ways(): void
    {
        // Rule: yearly plan sells only yearly; monthly plan only monthly.
        $owner = $this->shopUser();
        $this->razorpay->seedOrder('order_a', 185200, ['plan_id' => $this->plan()->id, 'billing_cycle' => 'monthly', 'user_id' => $owner->id]);
        $this->razorpay->seedPayment('pay_a', 'order_a');
        $this->razorpay->seedOrder('order_b', 1999900, ['plan_id' => $this->plan('dhiran_monthly')->id, 'billing_cycle' => 'yearly', 'user_id' => $owner->id]);
        $this->razorpay->seedPayment('pay_b', 'order_b');
        $this->capturedWebhook(['razorpay_order_id' => 'order_a', 'razorpay_payment_id' => 'pay_a'], 'e1')->assertOk();
        $this->capturedWebhook(['razorpay_order_id' => 'order_b', 'razorpay_payment_id' => 'pay_b'], 'e2')->assertOk();
        $this->assertSame(0, ShopSubscription::count());
        $this->assertSame(2, SubscriptionEvent::where('event_type', 'payment.unresolved')->count());
    }

    public static function badStatuses(): array { return ['authorized only' => ['authorized'], 'already refunded' => ['refunded'], 'failed' => ['failed'], 'created' => ['created']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('badStatuses')]
    public function test_payment_not_in_captured_state_grants_nothing_on_every_path(string $status): void
    {
        // Rule: only status=captured at the provider grants a term (a refund that beat the webhook flips it to refunded).
        $user = $this->shoplessUser(); $t = $this->paidOrder($user, null, $status);
        $this->actingAs($user)->cb($t);
        $this->capturedWebhook($t);
        $this->artisan('subscription:reconcile-payments', ['--payment' => $t['razorpay_payment_id'], '--order' => $t['razorpay_order_id']]);
        $this->assertSame(0, ShopSubscription::count());
        $this->assertSame(0, PlatformInvoice::count());
    }

    public function test_webhook_cannot_pair_a_payment_with_someone_elses_correctly_priced_order(): void
    {
        // Rule: the payment's own order_id at Razorpay must equal the order being credited.
        $a = $this->shopUser(); $b = $this->shopUser();
        $ta = $this->paidOrder($a, null, 'created'); $tb = $this->paidOrder($b);
        $this->capturedWebhook(['razorpay_order_id' => $ta['razorpay_order_id'], 'razorpay_payment_id' => $tb['razorpay_payment_id']])->assertOk();
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_payment_whose_order_payer_is_staff_not_owner_is_refused(): void
    {
        // Rule: only a shop owner (or a shopless buyer) can buy; a staff user id in notes grants nothing.
        $owner = $this->shopUser(); $staff = $this->staff($owner->shop, 1);
        $t = $this->paidOrder($staff);
        $this->capturedWebhook($t)->assertOk()->assertJson(['status' => 'not_applied']);
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_order_with_unknown_payer_or_plan_is_refused(): void
    {
        $this->razorpay->seedOrder('order_u', 1999900, ['plan_id' => $this->plan()->id, 'billing_cycle' => 'yearly', 'user_id' => 99999999]);
        $this->razorpay->seedPayment('pay_u', 'order_u');
        $this->razorpay->seedOrder('order_p', 1999900, ['plan_id' => 99999999, 'billing_cycle' => 'yearly', 'user_id' => $this->shoplessUser()->id]);
        $this->razorpay->seedPayment('pay_p', 'order_p');
        $this->capturedWebhook(['razorpay_order_id' => 'order_u', 'razorpay_payment_id' => 'pay_u'], 'e1')->assertOk();
        $this->capturedWebhook(['razorpay_order_id' => 'order_p', 'razorpay_payment_id' => 'pay_p'], 'e2')->assertOk();
        $this->assertSame(0, ShopSubscription::count());
    }

    // ───────────────────────── terms chaining ─────────────────────────

    public function test_shopless_buyer_paying_three_times_gets_back_to_back_terms_and_gap_free_receipts(): void
    {
        // Rule: each extra term starts the day after the previous term ends; receipts 1,2,3 with matching periods.
        $this->freeze('2027-01-10 10:00:00');
        $user = $this->shoplessUser(); $this->actingAs($user);
        foreach ([1, 2, 3] as $i) { $this->cb($this->paidOrder($user)); }
        $terms = ShopSubscription::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame(['2027-01-10', '2028-01-10', '2029-01-10'], $terms->map(fn ($s) => $s->starts_at->toDateString())->all());
        $this->assertSame(['2028-01-09', '2029-01-09', '2030-01-09'], $terms->map(fn ($s) => $s->ends_at->toDateString())->all());
        $this->assertSame(['DHR-2027-000001', 'DHR-2027-000002', 'DHR-2027-000003'], PlatformInvoice::orderBy('id')->pluck('invoice_number')->all());
        $this->assertSame(['2027-01-10', '2028-01-10', '2029-01-10'], PlatformInvoice::orderBy('id')->get()->map(fn ($i) => $i->billing_period_start->toDateString())->all());
        $this->assertSame(['subscription.paid', 'subscription.renewed', 'subscription.renewed'], SubscriptionEvent::where('event_type', 'like', 'subscription.%')->orderBy('id')->pluck('event_type')->all());
    }

    public function test_early_renewal_stacks_new_term_at_end_of_last_term_not_today(): void
    {
        // Rule: renewing early must not waste paid days: the new term starts the day AFTER the last live term ends.
        $this->freeze('2027-01-10 10:00:00');
        $owner = $this->shopUser(); $first = $this->term($owner->shop, 'dhiran_yearly', '2026-04-21', '2027-04-20', '2027-05-04');
        $this->capturedWebhook($this->paidOrder($owner), 'e1')->assertOk();
        $this->capturedWebhook($this->paidOrder($owner), 'e2')->assertOk();
        [$r1, $r2] = ShopSubscription::where('shop_id', $owner->shop_id)->where('id', '!=', $first->id)->orderBy('id')->get()->all();
        $this->assertSame(['2027-04-21', '2028-04-20', '2028-05-04'], [$r1->starts_at->toDateString(), $r1->ends_at->toDateString(), $r1->grace_ends_at->toDateString()]);
        $this->assertSame(['2028-04-21', '2029-04-20'], [$r2->starts_at->toDateString(), $r2->ends_at->toDateString()]);
        $this->assertSame('2027-04-20', $first->fresh()->ends_at->toDateString()); // current row never mutated
        $this->assertSame($first->id, $owner->shop->currentSubscription()->id); // today's term; queued terms count once they start
        $this->assertSame(['subscription.renewed', 'subscription.renewed'], $this->types());
        $this->assertSame(['2027-04-21', '2028-04-21'], PlatformInvoice::orderBy('id')->get()->map(fn ($i) => $i->billing_period_start->toDateString())->all());
    }

    public static function graceRenewals(): array
    {
        // monthly term 2027-04-01..2027-04-30, grace_days 7 -> grace_ends_at 2027-05-07
        return [
            'first day after end (grace)'  => ['2027-05-01 00:00:00', '2027-05-01', '2027-05-31'],
            'last grace day 23:59:59'      => ['2027-05-07 23:59:59', '2027-05-01', '2027-05-31'],
            'first day after grace 00:00'  => ['2027-05-08 00:00:00', '2027-05-08', '2027-06-07'],
            'ends_at day itself'           => ['2027-04-30 12:00:00', '2027-05-01', '2027-05-31'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('graceRenewals')]
    public function test_renewal_continues_through_grace_and_starts_fresh_only_after_it(string $now, string $start, string $end): void
    {
        // Rule: renewing up to and including the last grace day continues from ends_at+1; from the next day a fresh term starts today.
        $this->freeze($now);
        $owner = $this->shopUser(); $old = $this->term($owner->shop, 'dhiran_monthly', '2027-04-01', '2027-04-30', '2027-05-07');
        $this->capturedWebhook($this->paidOrder($owner, $this->plan('dhiran_monthly')))->assertOk();
        $new = ShopSubscription::where('id', '!=', $old->id)->sole();
        $this->assertSame([$start, $end], [$new->starts_at->toDateString(), $new->ends_at->toDateString()]);
        $this->assertSame(Carbon::parse($end)->addDays(7)->toDateString(), $new->grace_ends_at->toDateString());
    }

    public function test_renewal_after_a_refunded_latest_term_ignores_the_cancelled_row(): void
    {
        // Rule: a refunded (cancelled) term must not push the next term's start into the future.
        $this->freeze('2027-01-10 10:00:00');
        $owner = $this->shopUser(); $t1 = $this->paidOrder($owner);
        $this->capturedWebhook($t1, 'c1');
        $this->refund($t1['razorpay_payment_id'], 1999900, 'rfnd_a', 'r1')->assertOk();
        $this->assertSame('cancelled', ShopSubscription::sole()->status);
        $this->capturedWebhook($this->paidOrder($owner), 'c2')->assertOk();
        $new = ShopSubscription::where('status', 'active')->sole();
        $this->assertSame('2027-01-10', $new->starts_at->toDateString());
        $this->assertSame(['subscription.paid', 'subscription.refunded', 'subscription.paid'], $this->types());
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
    }

    public function test_terminated_shop_paying_again_gets_a_fresh_term_from_today_and_access_returns(): void
    {
        // Rule: after admin termination, a new payment is a first term (paid, not renewed) from today.
        $this->signIn($this->admin());
        $owner = $this->shopUser(); $this->term($owner->shop, 'dhiran_yearly', today()->subDays(30)->toDateString(), today()->addDays(335)->toDateString(), today()->addDays(349)->toDateString());
        $this->post("/admin/shops/{$owner->shop_id}/subscription/terminate", ['reason' => 'Fraud'])->assertRedirect();
        $this->assertFalse($owner->shop->fresh()->hasEdition('dhiran'));
        $this->capturedWebhook($this->paidOrder($owner))->assertOk();
        $new = ShopSubscription::where('status', 'active')->sole();
        $this->assertSame([today()->toDateString(), today()->addYear()->subDay()->toDateString()], [$new->starts_at->toDateString(), $new->ends_at->toDateString()]);
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
    }

    // ───────────────────────── refunds ─────────────────────────

    public function test_full_refund_cancels_term_revokes_access_and_is_idempotent(): void
    {
        // Rule: a full refund cancels the term (access off) and logs one subscription.refunded; redelivery is a no-op.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        $this->capturedWebhook($t);
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_1', 'e_r1')->assertOk();
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_1', 'e_r1')->assertOk();
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_1', 'e_r1_new')->assertOk(); // same refund id, new event id
        $sub = ShopSubscription::sole();
        $this->assertSame('cancelled', $sub->status);
        $this->assertNotNull($sub->cancelled_at);
        $this->assertFalse($owner->shop->fresh()->hasEdition('dhiran'));
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.refunded')->count());
    }

    public function test_fully_refunded_term_receipt_is_no_longer_issued(): void
    {
        // Rule: a receipt for money that was fully refunded must not stay "issued" (PlatformInvoice supports
        // cancelled + cancellation_reason for exactly this). Expected: status cancelled with a reason.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        $this->capturedWebhook($t);
        $this->refund($t['razorpay_payment_id'], 1999900, 'rfnd_1', 'e_r1');
        $inv = PlatformInvoice::sole();
        $this->assertSame('cancelled', $inv->status);
        $this->assertNotEmpty($inv->cancellation_reason);
    }

    public function test_two_partial_refunds_summing_to_full_cancel_only_on_the_second(): void
    {
        // Rule: partial refunds keep the term; the refund that makes cumulative == captured cancels it; one more is refused.
        $owner = $this->shopUser(); $t = $this->paidOrder($owner); $p = $t['razorpay_payment_id'];
        $this->capturedWebhook($t);
        $this->refund($p, 1000000, 'rfnd_1', 'e1')->assertOk();
        $this->assertSame('active', ShopSubscription::sole()->status);
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
        $this->refund($p, 1000000, 'rfnd_1', 'e1b')->assertOk(); // same refund id again: not double counted
        $this->assertSame('active', ShopSubscription::sole()->status);
        $this->refund($p, 999900, 'rfnd_2', 'e2')->assertOk();
        $this->assertSame('cancelled', ShopSubscription::sole()->status);
        $this->assertSame(['subscription.paid', 'subscription.partial_refund', 'subscription.refunded'], $this->types());
        $this->refund($p, 100, 'rfnd_3', 'e3')->assertOk()->assertJson(['status' => 'not_applied']);
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'refund.invalid')->count());
    }

    public static function badRefunds(): array
    {
        return ['usd' => [1999900, 'USD', 'rfnd_x'], 'zero' => [0, 'INR', 'rfnd_x'], 'negative' => [-5, 'INR', 'rfnd_x'], 'no refund id' => [1999900, 'INR', ''], 'over capture' => [2000000, 'INR', 'rfnd_x']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badRefunds')]
    public function test_invalid_refunds_are_refused_with_evidence_and_leave_the_term_alone(int $paise, string $cur, string $rid): void
    {
        $owner = $this->shopUser(); $t = $this->paidOrder($owner);
        $this->capturedWebhook($t);
        $this->refund($t['razorpay_payment_id'], $paise, $rid, 'e_bad', $cur)->assertOk()->assertJson(['status' => 'not_applied']);
        $this->assertSame('active', ShopSubscription::sole()->status);
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'refund.invalid')->count());
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
    }

    public function test_refunding_the_older_of_two_stacked_terms_leaves_the_newer_term_for_its_own_dates(): void
    {
        // Rule: each payment is its own term for its own dates; refunding the current term revokes today's access,
        // the queued term still entitles the shop from its start date (no access before a paid term starts).
        $this->freeze('2027-01-10 10:00:00');
        $owner = $this->shopUser(); $t1 = $this->paidOrder($owner); $t2 = $this->paidOrder($owner);
        $this->capturedWebhook($t1, 'c1'); $this->capturedWebhook($t2, 'c2');
        $this->refund($t1['razorpay_payment_id'], 1999900, 'rfnd_1', 'r1')->assertOk();
        $this->assertSame(['cancelled', 'active'], ShopSubscription::orderBy('id')->pluck('status')->all());
        $this->assertFalse($owner->shop->fresh()->hasEdition('dhiran'));
        $queued = ShopSubscription::where('status', 'active')->first();
        $this->travelTo($queued->starts_at->copy()->setTime(10, 0));
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
    }

    // ───────────────────────── receipts ─────────────────────────

    public function test_receipt_sequence_has_no_gap_when_an_issue_fails_and_is_retried(): void
    {
        // Rule: a failed receipt issue rolls its counter bump back; the retry takes the next number: 1,2 with no gap, no duplicate.
        $a = $this->shopUser(); $this->capturedWebhook($this->paidOrder($a), 'e1');
        $y = now()->year;
        $this->assertSame(1, $this->counter($y));
        DB::table('platform_counters')->where('key', "receipt:DHR:$y")->update(['value' => 0]); // force a collision with DHR-$y-000001
        $b = $this->shopUser(); $this->capturedWebhook($this->paidOrder($b), 'e2')->assertOk();
        $this->assertSame(2, ShopSubscription::count());                       // the paid term survived the receipt failure
        $this->assertSame(1, PlatformInvoice::count());
        $this->assertSame(0, $this->counter($y));                              // bump rolled back with the failed issue
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'receipt.failed')->count());
        foreach (range(1, 3) as $_) { $this->reconcile(); }                    // retry sweep keeps failing: still one marker, counter untouched
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'receipt.failed')->count());
        $this->assertSame(0, $this->counter($y));
        DB::table('platform_counters')->where('key', "receipt:DHR:$y")->update(['value' => 1]); // ops repairs the counter
        $this->reconcile();
        $this->assertSame(['DHR-'.$y.'-000001', 'DHR-'.$y.'-000002'], PlatformInvoice::orderBy('invoice_sequence')->pluck('invoice_number')->all());
        $this->assertSame(2, $this->counter($y));
    }

    public function test_issuing_the_same_receipt_repeatedly_never_moves_the_counter(): void
    {
        // Rule: issue is idempotent per subscription: 10 calls = 1 row, counter 1.
        $owner = $this->shopUser(); $this->capturedWebhook($this->paidOrder($owner));
        $sub = ShopSubscription::sole(); $svc = app(PlatformInvoiceService::class);
        foreach (range(1, 10) as $_) { $svc->issueForSubscription($sub); $svc->issueSafely($sub); }
        $this->assertSame(0, $svc->reissueMissing());
        $this->assertSame(1, PlatformInvoice::count());
        $this->assertSame(1, $this->counter(now()->year));
    }

    public function test_receipt_numbering_restarts_per_calendar_year_in_india_time(): void
    {
        // Rule: the year in the number is the IST year: 23:59:59 on 31 Dec is the old year, 00:00:00 on 1 Jan the new one, each restarting at 1.
        $this->freeze('2027-12-31 23:59:59'); $a = $this->shopUser(); $this->capturedWebhook($this->paidOrder($a), 'e1');
        $this->freeze('2028-01-01 00:00:00'); $b = $this->shopUser(); $this->capturedWebhook($this->paidOrder($b), 'e2');
        $c = $this->shopUser(); $this->capturedWebhook($this->paidOrder($c), 'e3');
        $this->assertSame(['DHR-2027-000001', 'DHR-2028-000001', 'DHR-2028-000002'], PlatformInvoice::orderBy('id')->pluck('invoice_number')->all());
    }

    public function test_reissue_sweep_does_not_issue_a_receipt_for_a_refunded_term(): void
    {
        // Rule: a refunded (cancelled) term whose receipt never got issued must not get a fresh "issued" receipt later.
        $owner = $this->shopUser();
        $this->term($owner->shop, 'dhiran_yearly', '2027-01-10', '2028-01-09', '2028-01-23', 'cancelled', 'pay_refunded');
        $this->assertSame(0, app(PlatformInvoiceService::class)->reissueMissing());
        $this->assertSame(0, PlatformInvoice::count());
    }

    public function test_admin_grant_and_zero_price_terms_never_get_receipts(): void
    {
        // Rule: receipts exist only for money actually paid.
        $owner = $this->shopUser(); $this->signIn($this->admin());
        $this->post("/admin/shops/{$owner->shop_id}/subscription", ['plan' => 'dhiran_yearly', 'billing_cycle' => 'yearly', 'ends_at' => today()->addYear()->toDateString(), 'reason' => 'Comp'])->assertRedirect();
        $this->assertSame(0, app(PlatformInvoiceService::class)->reissueMissing());
        $this->assertSame(0, PlatformInvoice::count());
    }

    // ───────────────────────── expiry command ─────────────────────────

    public function test_expiry_command_flips_exactly_at_the_ist_midnights(): void
    {
        // Rule: ends_at 10 Mar, grace 7d: still active through 23:59:59 on 10 Mar; grace from 00:00 11 Mar through 23:59:59 17 Mar; expired from 00:00 18 Mar.
        $shop = $this->shopUser()->shop; $s = $this->term($shop, 'dhiran_monthly', '2027-02-11', '2027-03-10', '2027-03-17');
        foreach ([['2027-03-10 23:59:59', 'active', 0], ['2027-03-11 00:00:00', 'grace', 1], ['2027-03-17 23:59:59', 'grace', 1], ['2027-03-18 00:00:00', 'expired', 2]] as [$now, $status, $events]) {
            $this->freeze($now);
            $this->artisan('subscription:check-expiry')->assertSuccessful();
            $this->artisan('subscription:check-expiry')->assertSuccessful(); // run twice: idempotent
            $this->assertSame($status, $s->fresh()->status, $now);
            $this->assertSame($events, SubscriptionEvent::count(), $now);
        }
        $this->assertSame(['subscription.grace', 'subscription.expired'], $this->types($s->id));
        $this->assertSame('2027-03-17', $s->fresh()->grace_ends_at->toDateString()); // stored grace never rewritten
    }

    public function test_expiry_command_on_a_quiet_day_changes_nothing_and_reports_zero(): void
    {
        $shop = $this->shopUser()->shop; $s = self::activeSubscription($shop);
        $before = $s->fresh()->toArray();
        $this->artisan('subscription:check-expiry')->expectsOutput('Done. Status changes: 0.')->assertSuccessful();
        $this->assertSame($before, $s->fresh()->toArray());
        $this->assertSame(0, SubscriptionEvent::count());
    }

    public function test_expiry_command_after_a_long_outage_walks_active_to_expired_in_one_run(): void
    {
        // Rule: if the scheduler was down past the grace window, one run records both transitions in order.
        $this->freeze('2027-06-01 03:00:00');
        $shop = $this->shopUser()->shop; $s = $this->term($shop, 'dhiran_yearly', '2026-03-11', '2027-03-10', '2027-03-24');
        $this->artisan('subscription:check-expiry')->expectsOutput('Done. Status changes: 2.')->assertSuccessful();
        $this->assertSame('expired', $s->fresh()->status);
        $this->assertSame(['subscription.grace', 'subscription.expired'], $this->types($s->id));
        $this->assertSame('2027-03-24', $s->fresh()->grace_ends_at->toDateString());
    }

    public function test_expiry_of_the_old_term_does_not_revoke_a_stacked_newer_term(): void
    {
        // Rule: the older term lapsing must leave the shop entitled through the early-renewed term.
        $this->freeze('2027-03-20 10:00:00');
        $owner = $this->shopUser();
        $old = $this->term($owner->shop, 'dhiran_yearly', '2026-03-11', '2027-03-10', '2027-03-24', 'grace');
        $new = $this->term($owner->shop, 'dhiran_yearly', '2027-03-11', '2028-03-10', '2028-03-24');
        $this->freeze('2027-03-25 00:00:00');
        $this->artisan('subscription:check-expiry')->assertSuccessful();
        $this->assertSame(['expired', 'active'], [$old->fresh()->status, $new->fresh()->status]);
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
        $this->assertSame($new->id, $owner->shop->fresh()->currentSubscription()->id);
    }

    public function test_expiry_command_never_resurrects_or_touches_cancelled_and_expired_rows(): void
    {
        $shop = $this->shopUser()->shop;
        $c = $this->term($shop, 'dhiran_yearly', '2026-01-01', '2026-12-31', '2027-01-14', 'cancelled');
        $e = $this->term($shop, 'dhiran_yearly', '2025-01-01', '2025-12-31', '2026-01-14', 'expired');
        $this->freeze('2027-06-01 00:00:00');
        $this->artisan('subscription:check-expiry')->assertSuccessful();
        $this->assertSame(['cancelled', 'expired'], [$c->fresh()->status, $e->fresh()->status]);
        $this->assertSame(0, SubscriptionEvent::count());
    }

    // ───────────────────────── plan limits ─────────────────────────

    private function staff(\App\Models\Shop $shop, int $n, bool $active = true): User
    {
        $roles = app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        return User::create(['name' => "Staff $n", 'email' => "st{$shop->id}x$n@billing.test", 'password' => 'a-long-test-password', 'shop_id' => $shop->id, 'role' => 'staff', 'role_id' => $roles['staff']->id, 'is_active' => $active]);
    }

    public function test_staff_cap_is_exactly_the_plan_number_owner_and_inactive_staff_do_not_count(): void
    {
        // Rule: monthly allows 3 staff: with 2 you can add, at exactly 3 you cannot; owner and deactivated staff are free.
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, 'dhiran_monthly', today()->subDays(5)->toDateString(), today()->addDays(25)->toDateString(), today()->addDays(32)->toDateString());
        $this->staff($shop, 1); $this->staff($shop, 2); $this->staff($shop, 99, false);
        $this->assertSame([3, 2, true], TenantContext::runFor($shop->id, fn () => [$shop->staffLimit(), $shop->currentStaffCount(), $shop->canAddStaff()]));
        $third = $this->staff($shop, 3);
        $this->assertSame([3, false], TenantContext::runFor($shop->id, fn () => [$shop->currentStaffCount(), $shop->canAddStaff()]));
        $third->update(['is_active' => false]);
        $this->assertTrue(TenantContext::runFor($shop->id, fn () => $shop->canAddStaff()));
    }

    public function test_downgrade_below_current_staff_blocks_new_staff_but_removes_nobody(): void
    {
        // Rule: lowering the plan never deletes staff (no automatic data deletion), it only blocks additions.
        $owner = $this->shopUser(); $shop = $owner->shop;
        $sub = $this->term($shop, 'dhiran_yearly', today()->subDay()->toDateString(), today()->addYear()->toDateString(), today()->addYear()->addDays(14)->toDateString());
        foreach (range(1, 5) as $i) { $this->staff($shop, $i); }
        $this->assertSame([7, true], TenantContext::runFor($shop->id, fn () => [$shop->staffLimit(), $shop->canAddStaff()]));
        $sub->update(['plan_id' => $this->plan('dhiran_monthly')->id]);
        $this->assertSame([3, 5, false], TenantContext::runFor($shop->id, fn () => [$shop->fresh()->staffLimit(), $shop->currentStaffCount(), $shop->fresh()->canAddStaff()]));
    }

    public function test_early_renewal_onto_a_smaller_plan_keeps_todays_limits_until_the_new_term_starts(): void
    {
        // Rule: limits come from the term that COVERS TODAY. A yearly shop (7 staff, 3000 items) that queues a monthly term
        // starting in 100 days must still have yearly limits today.
        $this->freeze('2027-01-10 10:00:00');
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, 'dhiran_yearly', '2026-04-21', '2027-04-20', '2027-05-04');
        $this->term($shop, 'dhiran_monthly', '2027-04-21', '2027-05-20', '2027-05-27');
        $this->assertSame(7, $shop->staffLimit());
        $this->assertSame(3000, $shop->itemLimit());
    }

    public function test_early_renewal_onto_a_bigger_plan_does_not_grant_its_limits_early(): void
    {
        // Rule: queuing a future yearly term must not hand the shop yearly limits (7 staff) while the monthly term (3) still runs.
        $this->freeze('2027-01-10 10:00:00');
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, 'dhiran_monthly', '2027-01-01', '2027-01-31', '2027-02-07');
        $this->term($shop, 'dhiran_yearly', '2027-02-01', '2028-01-31', '2028-02-14');
        $this->assertSame(3, $shop->staffLimit());
    }

    public function test_terminated_shop_cannot_add_staff_beyond_its_old_plan(): void
    {
        // Rule: once the only term is cancelled (refund/terminate) the shop is not entitled; "no plan" must not mean unlimited staff.
        $owner = $this->shopUser(); $shop = $owner->shop;
        $sub = $this->term($shop, 'dhiran_yearly', today()->subDay()->toDateString(), today()->addYear()->toDateString(), today()->addYear()->addDays(14)->toDateString());
        foreach (range(1, 7) as $i) { $this->staff($shop, $i); }
        $this->assertFalse(TenantContext::runFor($shop->id, fn () => $shop->canAddStaff()));
        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->assertFalse($shop->fresh()->hasEdition('dhiran'));
        $this->assertFalse(TenantContext::runFor($shop->id, fn () => $shop->fresh()->canAddStaff()));
    }

    private function loanShop(string $plan = 'dhiran_monthly', int $maxItems = 3): array
    {
        $owner = $this->shopUser(); $shop = $owner->shop;
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $this->term($shop, $plan, today()->subDay()->toDateString(), today()->addMonth()->toDateString(), today()->addMonth()->addDays(7)->toDateString());
        Plan::where('code', $plan)->update(['features' => array_merge($this->plan($plan)->features, ['max_items' => $maxItems])]);
        $c = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'Lim', 'last_name' => 'It', 'mobile' => '9815550099']));
        return [$owner, $c];
    }

    private function loan(User $owner, Customer $c, int $items)
    {
        $its = array_fill(0, $items, ['description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]);
        return TenantContext::runFor($owner->shop_id, fn () => app(DhiranService::class)->createLoan($owner->shop->fresh(), $c, $its, ['principal_amount' => 10000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id]));
    }

    public function test_item_cap_allows_exactly_n_items_and_refuses_n_plus_one_atomically(): void
    {
        // Rule: max_items 3: one loan of exactly 3 items is allowed; the very next item is refused and writes nothing.
        [$owner, $c] = $this->loanShop();
        $this->loan($owner, $c, 3);
        $this->assertSame(3, $owner->shop->fresh()->pledgedItemCount());
        try { $this->loan($owner, $c, 1); $this->fail('N+1 items must be refused'); }
        catch (\LogicException $e) { $this->assertStringContainsString('Item limit reached', $e->getMessage()); }
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count());
        $this->assertSame(3, $owner->shop->fresh()->pledgedItemCount());
    }

    public function test_item_cap_n_plus_one_in_a_single_first_loan_is_refused(): void
    {
        [$owner, $c] = $this->loanShop();
        try { $this->loan($owner, $c, 4); $this->fail('4 items over a cap of 3 must be refused'); }
        catch (\LogicException $e) { $this->assertStringContainsString('Item limit reached', $e->getMessage()); }
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count());
    }

    public function test_releasing_one_item_frees_exactly_one_slot(): void
    {
        // Rule: only items still pledged on unfinished loans count; a released item frees a slot.
        [$owner, $c] = $this->loanShop();
        $l = $this->loan($owner, $c, 3);
        \App\Models\Dhiran\DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->limit(1)->update(['status' => 'released']);
        $this->assertSame(2, $owner->shop->fresh()->pledgedItemCount());
        $this->loan($owner, $c, 1); // 2 + 1 = 3 == cap: allowed
        $this->expectException(\LogicException::class);
        $this->loan($owner, $c, 1); // 4 > 3
    }

    // ───────────────────────── admin grant / terminate ─────────────────────────

    private function grant(int $shopId, string $endsAt, string $plan = 'dhiran_yearly', string $cycle = 'yearly')
    {
        return $this->post("/admin/shops/$shopId/subscription", ['plan' => $plan, 'billing_cycle' => $cycle, 'ends_at' => $endsAt, 'reason' => 'ruthless']);
    }

    private function d(int $days): string { return today()->addDays($days)->toDateString(); }

    public function test_admin_grant_one_day_shorter_than_a_paid_term_is_refused_but_equal_is_allowed(): void
    {
        // Rule: grant is extend-only: ends_at < live ends_at is refused with no writes; equal is a harmless no-shrink.
        $this->signIn($this->admin());
        $owner = $this->shopUser(); $s = $this->term($owner->shop, 'dhiran_yearly', $this->d(-300), $this->d(60), $this->d(74));
        $this->grant($owner->shop_id, $this->d(59))->assertSessionHasErrors('ends_at');
        $this->assertSame(0, SubscriptionEvent::count());
        $this->assertDatabaseMissing('platform_audit_logs', ['action' => 'subscription.updated']);
        $this->assertSame($this->d(60), $s->fresh()->ends_at->toDateString());
        $this->grant($owner->shop_id, $this->d(60))->assertSessionHasNoErrors();
        $this->assertSame($this->d(60), $s->fresh()->ends_at->toDateString());
    }

    public function test_admin_grant_in_the_past_is_refused_even_for_a_shop_with_no_terms(): void
    {
        $this->signIn($this->admin());
        $shop = \App\Models\Shop::create(['name' => 'Empty']);
        $this->grant($shop->id, $this->d(-1))->assertSessionHasErrors('ends_at');
        $this->assertSame(0, ShopSubscription::count());
        $this->grant($shop->id, $this->d(0))->assertSessionHasNoErrors();  // today is the floor
        $this->assertTrue($shop->fresh()->hasEdition('dhiran'));
    }

    public function test_admin_grant_on_a_grace_shop_extends_the_lapsing_row_in_place(): void
    {
        // Rule: grant to a shop in grace reactivates that same row (status active) and recomputes grace from the plan.
        $this->signIn($this->admin());
        $owner = $this->shopUser(); $s = $this->term($owner->shop, 'dhiran_monthly', $this->d(-32), $this->d(-2), $this->d(5), 'grace');
        $this->grant($owner->shop_id, $this->d(28), 'dhiran_monthly', 'monthly')->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertSame(['active', $this->d(28), $this->d(35)], [$s->status, $s->ends_at->toDateString(), $s->grace_ends_at->toDateString()]);
        $this->assertSame(1, ShopSubscription::count());
    }

    public function test_terminate_then_grant_creates_a_new_row_and_keeps_the_cancelled_history(): void
    {
        // Rule: terminate cancels every live row (all of them, even stacked); a later grant is a fresh row from today; history intact.
        $this->signIn($this->admin());
        $owner = $this->shopUser(); $shopId = $owner->shop_id;
        $a = $this->term($owner->shop, 'dhiran_yearly', $this->d(-300), $this->d(60), $this->d(74));
        $b = $this->term($owner->shop, 'dhiran_yearly', $this->d(61), $this->d(425), $this->d(439));
        $this->post("/admin/shops/$shopId/subscription/terminate", ['reason' => 'Chargeback'])->assertRedirect();
        $this->assertSame(['cancelled', 'cancelled'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertFalse($owner->shop->fresh()->hasEdition('dhiran'));
        $this->post("/admin/shops/$shopId/subscription/terminate", ['reason' => 'again'])->assertSessionHasErrors('reason'); // nothing live left
        $this->grant($shopId, $this->d(31), 'dhiran_monthly', 'monthly')->assertSessionHasNoErrors();
        $this->assertSame(3, ShopSubscription::where('shop_id', $shopId)->count());
        $new = ShopSubscription::where('shop_id', $shopId)->where('status', 'active')->sole();
        $this->assertSame([$this->d(0), $this->d(31), $this->d(38)], [$new->starts_at->toDateString(), $new->ends_at->toDateString(), $new->grace_ends_at->toDateString()]);
        $this->assertNull($new->cancelled_at);
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
        $this->assertSame($new->id, $owner->shop->fresh()->currentSubscription()->id);
        $this->assertSame(['admin_terminated', 'admin_terminated', 'admin_granted'], $this->types());
    }

    public function test_grant_for_a_cycle_the_plan_does_not_sell_writes_nothing(): void
    {
        $this->signIn($this->admin()); $shop = \App\Models\Shop::create(['name' => 'X']);
        $this->grant($shop->id, today()->addMonth()->toDateString(), 'dhiran_monthly', 'yearly')->assertSessionHasErrors('billing_cycle');
        $this->grant($shop->id, today()->addMonth()->toDateString(), 'dhiran_yearly', 'monthly')->assertSessionHasErrors('billing_cycle');
        $this->assertSame(0, ShopSubscription::count());
    }

    // ───────────────────────── controller: choose / initiate / callback ─────────────────────────

    public function test_choose_refuses_a_deactivated_plan_and_initiate_refuses_one_deactivated_after_choosing(): void
    {
        $user = $this->shoplessUser(); $this->actingAs($user);
        $this->post('/subscription/choose', ['plan_id' => $this->plan()->id, 'billing_cycle' => 'yearly']);
        Plan::where('code', 'dhiran_yearly')->update(['is_active' => false]);
        $this->postJson('/subscription/payment/initiate')->assertStatus(422);
        $this->assertSame(0, $this->razorpay->networkCalls); // no order created for a dead plan
        $this->post('/subscription/choose', ['plan_id' => $this->plan()->id, 'billing_cycle' => 'yearly'])->assertSessionHasErrors('plan_id');
    }

    public function test_initiate_prices_the_order_from_the_server_plan_even_if_the_price_moved_after_choosing(): void
    {
        // Rule: the order amount is read from the plan at initiate time; a posted amount is ignored.
        $user = $this->shoplessUser(); $this->actingAs($user);
        $this->post('/subscription/choose', ['plan_id' => $this->plan()->id, 'billing_cycle' => 'yearly']);
        Plan::where('code', 'dhiran_yearly')->update(['price_yearly' => 24999]);
        $r = $this->postJson('/subscription/payment/initiate', ['amount' => 1, 'price' => 1])->assertOk();
        $this->assertSame(2499900, $r->json('amount'));
        $this->assertSame(2499900, (int) $this->razorpay->orders[$r->json('order_id')]->amount);
    }

    public function test_callback_with_missing_pieces_changes_nothing(): void
    {
        $user = $this->shoplessUser(); $t = $this->paidOrder($user); $this->actingAs($user);
        foreach (['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature'] as $k) {
            $this->cb(array_diff_key($t, [$k => 1]))->assertRedirect(route('subscription.payment'));
        }
        $this->assertSame(0, ShopSubscription::count());
        $this->assertSame(0, SubscriptionEvent::count());
    }

    public function test_callback_cannot_use_another_users_valid_signature_triple(): void
    {
        // Rule: a genuine signed triple is bound to the order's payer; a different logged-in user cannot claim it.
        $payer = $this->shoplessUser(); $thief = $this->shoplessUser(); $t = $this->paidOrder($payer);
        $this->actingAs($thief)->cb($t)->assertRedirect(route('subscription.payment'));
        $this->assertSame(0, ShopSubscription::count());
        $this->actingAs($payer)->cb($t)->assertRedirect();
        $this->assertSame($payer->id, ShopSubscription::sole()->user_id);
    }
}
