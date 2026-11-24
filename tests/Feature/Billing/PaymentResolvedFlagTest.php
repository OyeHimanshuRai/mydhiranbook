<?php
namespace Tests\Feature\Billing;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Services\Billing\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

/** F6: a payment that is applied never leaves an open payment.unresolved row behind, even after a crash. */
class PaymentResolvedFlagTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); }

    private function openUnresolved(array $t): SubscriptionEvent
    {
        return SubscriptionEvent::create(['shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'payment.unresolved', 'before' => null,
            'after' => ['order_id' => $t['razorpay_order_id'], 'payment_id' => $t['razorpay_payment_id'], 'attempt_count' => 1, 'transient' => true, 'resolved_at' => null], 'reason' => 'transient: provider down']);
    }

    public function test_a_later_lookup_of_an_already_applied_payment_resolves_the_phantom_row(): void
    {
        // the crash window: term committed, resolved flag lost. The next webhook/callback finalize must heal it.
        $user = $this->shoplessUser(); $t = $this->paidOrder($user);
        $sub = app(SubscriptionPaymentService::class)->finalizeCapturedPayment($t['razorpay_order_id'], $t['razorpay_payment_id']);
        $event = $this->openUnresolved($t); // phantom, as if markPaymentResolved never ran

        app(SubscriptionPaymentService::class)->finalizeCapturedPayment($t['razorpay_order_id'], $t['razorpay_payment_id']);

        $after = $event->fresh()->after;
        $this->assertNotEmpty($after['resolved_at']);
        $this->assertSame($sub->id, $after['resolved_subscription_id']);
        $this->assertSame(1, ShopSubscription::count());
    }

    public function test_the_term_and_the_resolved_flag_commit_together(): void
    {
        // if resolving the open row fails, no term may be left behind with the flag lost
        $user = $this->shoplessUser(); $t = $this->paidOrder($user); $this->openUnresolved($t);
        SubscriptionEvent::updating(fn ($e) => $e->event_type === 'payment.unresolved' ? throw new \RuntimeException('boom') : null);

        try { app(SubscriptionPaymentService::class)->finalizeCapturedPayment($t['razorpay_order_id'], $t['razorpay_payment_id']); $this->fail('expected failure'); }
        catch (\RuntimeException $e) { $this->assertSame('boom', $e->getMessage()); }

        $this->assertSame(0, ShopSubscription::count());
    }
}
