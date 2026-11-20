<?php
namespace App\Services\Billing;

/** The only door to Razorpay. Tests bind a fake; nothing else may touch the SDK. */
interface RazorpayClient
{
    /** @param array<string,mixed> $params */
    public function createOrder(array $params): object;

    public function fetchOrder(string $orderId): object;

    public function fetchPayment(string $paymentId): object;

    /** @throws \Razorpay\Api\Errors\SignatureVerificationError */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): void;

    /** @throws \Razorpay\Api\Errors\SignatureVerificationError */
    public function verifyWebhookSignature(string $body, string $signature, string $secret): void;

    /** @return list<array{id:string,order_id:?string}> captured payments since the unix timestamp */
    public function capturedPaymentsSince(int $fromTimestamp): array;
}
