<?php
namespace Tests\Feature\Billing;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures;
use Tests\Support\FakeRazorpayClient;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); }

    public function test_webhook_without_callback_finalizes_payment(): void
    {
        $owner = $this->shopUser(); $triple = $this->paidOrder($owner);
        $this->capturedWebhook($triple)->assertOk();
        $sub = ShopSubscription::sole();
        $this->assertSame($owner->shop_id, $sub->shop_id); $this->assertSame($owner->id, $sub->user_id);
        $this->assertTrue($owner->shop->hasEdition('dhiran'));
    }

    public function test_webhook_is_public_and_csrf_exempt(): void
    {
        $this->capturedWebhook($this->paidOrder($this->shoplessUser()))->assertOk();
    }

    public function test_webhook_bad_signature_401(): void
    {
        $triple = $this->paidOrder($this->shoplessUser());
        $this->rawWebhook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => $triple['razorpay_payment_id'], 'order_id' => $triple['razorpay_order_id']]]]], 'evt_x', 'forged')->assertUnauthorized();
        $this->assertSame(0, ShopSubscription::count()); $this->assertSame(0, SubscriptionEvent::count());
    }

    public function test_webhook_without_configured_secret_fails_closed(): void
    {
        config(['services.razorpay.webhook_secret' => null]);
        $this->capturedWebhook($this->paidOrder($this->shoplessUser()))->assertStatus(500);
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_webhook_without_event_id_is_rejected(): void
    {
        $triple = $this->paidOrder($this->shoplessUser());
        $this->capturedWebhook($triple, '')->assertStatus(400);
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_transient_provider_failure_returns_500_and_succeeds_on_retry(): void
    {
        $triple = $this->paidOrder($this->shoplessUser());
        $this->razorpay->failFetch = true;
        $this->capturedWebhook($triple)->assertStatus(500);
        $this->assertSame(0, ShopSubscription::count());
        $this->assertTrue((bool) SubscriptionEvent::where('event_type', 'payment.unresolved')->sole()->after['transient']);
        $this->razorpay->failFetch = false;
        $this->capturedWebhook($triple)->assertOk();
        $this->assertSame(1, ShopSubscription::count());
        $this->assertNotNull(SubscriptionEvent::where('event_type', 'payment.unresolved')->sole()->after['resolved_at']);
    }

    public function test_permanent_validation_failure_returns_200_so_razorpay_stops_retrying(): void
    {
        $triple = $this->paidOrder($this->shoplessUser(), null, 'captured', 100);
        $this->capturedWebhook($triple)->assertOk()->assertJsonPath('status', 'not_applied');
        $this->assertSame(0, ShopSubscription::count());
    }

    private function refund(string $paymentId, int $paise, string $refundId = 'rfnd_1', string $eventId = 'evt_r1')
    {
        return $this->rawWebhook(['event' => 'refund.created', 'payload' => ['refund' => ['entity' => ['id' => $refundId, 'payment_id' => $paymentId, 'amount' => $paise, 'currency' => 'INR']]]], $eventId);
    }

    public function test_refund_webhook_cancels_subscription_and_logs_event(): void
    {
        $owner = $this->shopUser(); $triple = $this->paidOrder($owner);
        $this->capturedWebhook($triple)->assertOk();
        $this->refund($triple['razorpay_payment_id'], 1999900)->assertOk();
        $sub = ShopSubscription::sole();
        $this->assertSame('cancelled', $sub->status); $this->assertNotNull($sub->cancelled_at);
        $event = SubscriptionEvent::where('event_type', 'subscription.refunded')->sole();
        $this->assertSame($sub->id, $event->shop_subscription_id); $this->assertSame('rfnd_1', $event->after['refund_id']);
        $this->assertFalse($owner->shop->fresh()->hasEdition('dhiran'));
    }

    public function test_refund_redelivery_is_idempotent(): void
    {
        $triple = $this->paidOrder($this->shoplessUser());
        $this->capturedWebhook($triple);
        $this->refund($triple['razorpay_payment_id'], 1999900); $this->refund($triple['razorpay_payment_id'], 1999900);
        $this->refund($triple['razorpay_payment_id'], 1999900, 'rfnd_1', 'evt_other');
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.refunded')->count());
    }

    public function test_partial_refund_logs_but_keeps_subscription_active(): void
    {
        $triple = $this->paidOrder($this->shoplessUser());
        $this->capturedWebhook($triple);
        $this->refund($triple['razorpay_payment_id'], 100000)->assertOk();
        $this->assertSame('active', ShopSubscription::sole()->status);
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.partial_refund')->count());
    }

    public function test_refund_exceeding_captured_amount_is_refused_and_recorded(): void
    {
        $triple = $this->paidOrder($this->shoplessUser());
        $this->capturedWebhook($triple);
        $this->refund($triple['razorpay_payment_id'], 9999900)->assertOk();
        $this->assertSame('active', ShopSubscription::sole()->status);
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'refund.invalid')->count());
    }

    public function test_payment_failed_is_logged_only_and_deduplicated(): void
    {
        $payload = ['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['order_id' => 'order_9', 'error_description' => 'card declined']]]];
        $this->rawWebhook($payload, 'evt_f')->assertOk(); $this->rawWebhook($payload, 'evt_f')->assertOk();
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'payment.failed')->count());
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_unknown_event_is_acknowledged(): void
    {
        $this->rawWebhook(['event' => 'order.paid'], 'evt_u')->assertOk();
    }
}
