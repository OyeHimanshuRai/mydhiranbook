<?php
namespace Tests\Support;

use App\Services\Billing\RazorpayClient;
use Razorpay\Api\Errors\SignatureVerificationError;

/** In-memory Razorpay. Signatures are real HMACs over the test secrets, so verification is genuinely exercised. */
class FakeRazorpayClient implements RazorpayClient
{
    public const KEY_SECRET = 'test_key_secret';
    public const WEBHOOK_SECRET = 'test_webhook_secret';

    /** @var array<string,object> */
    public array $orders = [];
    /** @var array<string,object> */
    public array $payments = [];
    public int $networkCalls = 0;
    public bool $failFetch = false;

    public function createOrder(array $params): object
    {
        $this->networkCalls++;
        $order = (object) ($params + ['id' => 'order_'.(count($this->orders) + 1)]);
        $this->orders[$order->id] = $order;
        return $order;
    }

    public function seedOrder(string $id, int $amountPaise, array $notes, string $currency = 'INR'): void
    {
        $this->orders[$id] = (object) ['id' => $id, 'amount' => $amountPaise, 'currency' => $currency, 'notes' => $notes];
    }

    public function seedPayment(string $id, string $orderId, string $status = 'captured', ?int $amount = null, string $currency = 'INR'): void
    {
        $amount ??= isset($this->orders[$orderId]) ? (int) $this->orders[$orderId]->amount : 0;
        $this->payments[$id] = (object) ['id' => $id, 'order_id' => $orderId, 'status' => $status, 'amount' => $amount, 'currency' => $currency];
    }

    public function fetchOrder(string $orderId): object
    {
        $this->networkCalls++;
        if ($this->failFetch || !isset($this->orders[$orderId])) { throw new \RuntimeException('Razorpay unreachable'); }
        return $this->orders[$orderId];
    }

    public function fetchPayment(string $paymentId): object
    {
        $this->networkCalls++;
        if ($this->failFetch || !isset($this->payments[$paymentId])) { throw new \RuntimeException('Razorpay unreachable'); }
        return $this->payments[$paymentId];
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): void
    {
        $this->check($orderId.'|'.$paymentId, $signature, self::KEY_SECRET);
    }

    public function verifyWebhookSignature(string $body, string $signature, string $secret): void
    {
        $this->check($body, $signature, $secret);
    }

    private function check(string $payload, string $signature, string $secret): void
    {
        if (!hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) { throw new SignatureVerificationError('bad signature'); }
    }

    public function capturedPaymentsSince(int $fromTimestamp): array
    {
        $this->networkCalls++;
        $out = [];
        foreach ($this->payments as $p) { if ($p->status === 'captured') { $out[] = ['id' => $p->id, 'order_id' => $p->order_id]; } }
        return $out;
    }

    public static function paymentSignature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, self::KEY_SECRET);
    }

    public static function webhookSignature(string $body): string
    {
        return hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
    }
}
