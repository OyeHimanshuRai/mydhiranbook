<?php
namespace App\Services\Billing;

use Razorpay\Api\Api;

class SdkRazorpayClient implements RazorpayClient
{
    public function __construct(private readonly ?string $key, private readonly ?string $secret) {}

    private function api(): Api { return new Api($this->key, $this->secret); }

    /** SDK entities become plain objects; nested `notes` stay arrays. */
    private function plain(mixed $entity): object { return (object) $entity->toArray(); }

    public function createOrder(array $params): object { return $this->plain($this->api()->order->create($params)); }

    public function fetchOrder(string $orderId): object { return $this->plain($this->api()->order->fetch($orderId)); }

    public function fetchPayment(string $paymentId): object { return $this->plain($this->api()->payment->fetch($paymentId)); }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): void
    {
        $this->api()->utility->verifySignature($orderId.'|'.$paymentId, $signature, (string) $this->secret);
    }

    public function verifyWebhookSignature(string $body, string $signature, string $secret): void
    {
        $this->api()->utility->verifyWebhookSignature($body, $signature, $secret);
    }

    public function capturedPaymentsSince(int $fromTimestamp): array
    {
        $out = [];
        foreach ($this->api()->payment->all(['from' => $fromTimestamp, 'count' => 100])->toArray()['items'] ?? [] as $p) {
            if (($p['status'] ?? null) === 'captured') { $out[] = ['id' => $p['id'], 'order_id' => $p['order_id'] ?? null]; }
        }
        return $out;
    }
}
