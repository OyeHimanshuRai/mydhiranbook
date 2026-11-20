<?php
namespace Tests\Support;

use App\Models\Platform\Plan;
use App\Models\Shop;
use App\Models\User;
use App\Services\Billing\RazorpayClient;
use App\Services\TenantRoleService;
use Database\Seeders\PlanSeeder;

trait BillingFixtures
{
    protected FakeRazorpayClient $razorpay;

    protected function setUpBilling(): void
    {
        (new PlanSeeder)->run();
        $this->razorpay = new FakeRazorpayClient;
        $this->app->instance(RazorpayClient::class, $this->razorpay);
        config(['services.razorpay.webhook_secret' => FakeRazorpayClient::WEBHOOK_SECRET, 'billing.alert_email' => null]);
    }

    protected function plan(string $code = 'dhiran_yearly'): Plan { return Plan::where('code', $code)->firstOrFail(); }

    protected function shopUser(string $role = 'owner'): User
    {
        static $n = 0; $n++;
        $shop = Shop::create(['name' => "Billing shop $n"]);
        $roles = app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        return User::create(['name' => "$role $n", 'email' => "$role$n@billing.test", 'password' => 'a-long-test-password', 'shop_id' => $shop->id, 'role' => $role, 'role_id' => $roles[$role]->id]);
    }

    protected function shoplessUser(): User
    {
        static $n = 0; $n++;
        return User::create(['name' => "Buyer $n", 'email' => "buyer$n@billing.test", 'password' => 'a-long-test-password', 'role' => 'owner']);
    }

    /** Seed a Razorpay order + payment for $user; returns the checkout callback triple. */
    protected function paidOrder(User $user, ?Plan $plan = null, string $status = 'captured', ?int $amountPaise = null): array
    {
        $plan ??= $this->plan();
        $cycle = $plan->price_yearly ? 'yearly' : 'monthly';
        static $n = 0; $n++;
        $orderId = "order_t$n"; $paymentId = "pay_t$n";
        $this->razorpay->seedOrder($orderId, $amountPaise ?? $plan->priceFor($cycle) * 100, ['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'user_id' => $user->id]);
        $this->razorpay->seedPayment($paymentId, $orderId, $status);
        return ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => FakeRazorpayClient::paymentSignature($orderId, $paymentId)];
    }

    protected function capturedWebhook(array $triple, string $eventId = 'evt_1'): \Illuminate\Testing\TestResponse
    {
        return $this->rawWebhook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => $triple['razorpay_payment_id'], 'order_id' => $triple['razorpay_order_id']]]]], $eventId);
    }

    protected function rawWebhook(array $payload, string $eventId, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        return $this->call('POST', '/subscription/payment/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? FakeRazorpayClient::webhookSignature($body), 'HTTP_X_RAZORPAY_EVENT_ID' => $eventId], $body);
    }
}
