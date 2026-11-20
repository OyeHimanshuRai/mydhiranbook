<?php
namespace Tests\Feature\Billing;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); }

    public function test_reconcile_applies_captured_payment_nobody_else_applied(): void
    {
        $owner = $this->shopUser(); $this->paidOrder($owner);
        $this->artisan('subscription:reconcile-payments', ['--sleep-ms' => 0])->assertSuccessful();
        $this->assertSame($owner->shop_id, ShopSubscription::sole()->shop_id);
    }

    public function test_reconcile_skips_payments_already_applied_without_provider_calls_for_them(): void
    {
        $user = $this->shoplessUser(); $triple = $this->paidOrder($user);
        $this->capturedWebhook($triple);
        $before = $this->razorpay->networkCalls;
        $this->artisan('subscription:reconcile-payments', ['--sleep-ms' => 0])->assertSuccessful();
        $this->assertSame($before + 1, $this->razorpay->networkCalls); // only the list call
        $this->assertSame(1, ShopSubscription::count());
    }

    public function test_manual_single_payment_mode(): void
    {
        $user = $this->shoplessUser(); $triple = $this->paidOrder($user);
        $this->artisan('subscription:reconcile-payments', ['--payment' => $triple['razorpay_payment_id'], '--order' => $triple['razorpay_order_id']])->assertSuccessful();
        $this->assertSame(1, ShopSubscription::count());
    }

    public function test_callback_then_webhook_then_reconcile_creates_one_subscription_and_one_payment_event(): void
    {
        $this->assertOneEverywhere(['callback', 'webhook', 'reconcile']);
    }

    public function test_webhook_then_callback_then_reconcile_creates_one_subscription_and_one_payment_event(): void
    {
        $this->assertOneEverywhere(['webhook', 'callback', 'reconcile']);
    }

    public function test_reconcile_then_webhook_then_callback_creates_one_subscription_and_one_payment_event(): void
    {
        $this->assertOneEverywhere(['reconcile', 'webhook', 'callback', 'webhook']);
    }

    private function assertOneEverywhere(array $order): void
    {
        foreach ([$this->shoplessUser(), $this->shopUser()] as $user) {
            $triple = $this->paidOrder($user);
            foreach ($order as $step) {
                match ($step) {
                    'callback' => $this->actingAs($user)->post('/subscription/payment/callback', $triple),
                    'webhook' => $this->capturedWebhook($triple),
                    'reconcile' => $this->artisan('subscription:reconcile-payments', ['--sleep-ms' => 0]),
                };
            }
            $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', $triple['razorpay_payment_id'])->count());
            $this->assertSame(1, ShopSubscription::where('razorpay_order_id', $triple['razorpay_order_id'])->count());
            $this->assertSame(1, SubscriptionEvent::whereIn('event_type', ['subscription.paid', 'subscription.renewed'])->where('after->razorpay_payment_id', $triple['razorpay_payment_id'])->count());
        }
    }

    public function test_reconcile_is_scheduled_every_ten_minutes_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'subscription:reconcile-payments'));
        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
