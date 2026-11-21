<?php
namespace App\Services\Billing;

use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionWebhookService
{
    private const REFUND_EVENTS = ['subscription.refunded', 'subscription.partial_refund'];

    public function __construct(private readonly RazorpayClient $razorpay, private readonly SubscriptionPaymentService $payments) {}

    /** @throws \RuntimeException secret not configured @throws \Razorpay\Api\Errors\SignatureVerificationError */
    public function verifySignature(string $body, ?string $signature): void
    {
        $secret = config('services.razorpay.webhook_secret');
        if (!$secret) {
            Log::error('Razorpay webhook secret is not configured. Rejecting webhook request.');
            throw new \RuntimeException('Webhook secret not configured.');
        }
        $this->razorpay->verifyWebhookSignature($body, $signature ?? '', $secret);
    }

    /** Money applies even if the browser callback never came. A known payment is already applied (idempotent no-op). */
    public function handlePaymentCaptured(array $payload): string
    {
        $entity = $payload['payload']['payment']['entity'] ?? [];
        $paymentId = $entity['id'] ?? null;
        if (!$paymentId) { return SubscriptionPaymentService::OUTCOME_PERMANENT; }
        if ($this->payments->findExistingSubscription($paymentId)) { return SubscriptionPaymentService::OUTCOME_APPLIED; }

        $orderId = $entity['order_id'] ?? null;
        if (!$orderId) {
            $this->payments->recordUnresolvedPayment('', $paymentId, 'payment.captured webhook carried no order_id', false);
            return SubscriptionPaymentService::OUTCOME_PERMANENT;
        }
        return $this->payments->applyCapturedPayment($orderId, $paymentId)['outcome'];
    }

    /** Evidence only; never touches a subscription. Deduplicated on the Razorpay event id. */
    public function handlePaymentFailed(array $payload, string $eventId): void
    {
        $entity = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $entity['order_id'] ?? null;
        $error = $entity['error_description'] ?? 'Unknown';
        Log::warning('Webhook: payment.failed', ['order_id' => $orderId, 'error' => $error, 'event_id' => $eventId]);
        if (!$orderId || SubscriptionEvent::where('event_type', 'payment.failed')->where('after->event_id', $eventId)->exists()) { return; }
        SubscriptionEvent::create([
            'shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'payment.failed', 'before' => null,
            'after' => ['event_id' => $eventId, 'order_id' => $orderId, 'error' => $error], 'reason' => 'Razorpay webhook: payment failed, '.$error,
        ]);
    }

    /**
     * A full refund cancels the subscription; a partial one is logged and the term stays. Redelivery is a no-op
     * (event id, then refund id). Refunds that do not validate are recorded as refund.invalid and never applied.
     */
    public function handleRefundCreated(array $payload, string $eventId): string
    {
        $refund = $payload['payload']['refund']['entity'] ?? [];
        $paymentId = (string) ($refund['payment_id'] ?? '');
        $refundId = (string) ($refund['id'] ?? '');
        $paise = (int) ($refund['amount'] ?? 0);
        $currency = (string) ($refund['currency'] ?? '');

        $problem = match (true) {
            $refundId === '' => 'missing refund id',
            $paymentId === '' => 'missing payment id',
            $paise <= 0 => "non-positive refund amount ({$paise} paise)",
            $currency !== SubscriptionPaymentService::EXPECTED_CURRENCY => "currency '{$currency}' is not ".SubscriptionPaymentService::EXPECTED_CURRENCY,
            default => null,
        };
        if ($problem !== null) { return $this->refuseRefund($paymentId, $refundId, $problem, $paise, $currency, $eventId); }

        try {
            return DB::transaction(function () use ($paymentId, $refundId, $paise, $currency, $eventId) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$eventId]);
                // a refused attempt (refund.invalid) is evidence, not an application: a redelivery must still be able to apply
                if (SubscriptionEvent::where('after->event_id', $eventId)->where('event_type', '!=', 'refund.invalid')->exists()) { return SubscriptionPaymentService::OUTCOME_APPLIED; }

                $sub = ShopSubscription::where('razorpay_payment_id', $paymentId)->lockForUpdate()->first();
                // webhooks arrive out of order: keep evidence and ask Razorpay to redeliver, so the refund applies once the capture lands
                if (!$sub) { $this->refuseRefund($paymentId, $refundId, 'no local subscription found for payment id (will retry)', $paise, $currency, $eventId); return SubscriptionPaymentService::OUTCOME_TRANSIENT; }

                $refunds = SubscriptionEvent::where('shop_subscription_id', $sub->id)->whereIn('event_type', self::REFUND_EVENTS);
                if ((clone $refunds)->where('after->refund_id', $refundId)->exists()) { return SubscriptionPaymentService::OUTCOME_APPLIED; }

                $captured = (int) round((float) $sub->price_paid * 100);
                $cumulative = (int) (clone $refunds)->sum(DB::raw("COALESCE(CAST(after->>'refund_amount_paise' AS BIGINT), 0)")) + $paise;
                if ($cumulative > $captured) {
                    return $this->refuseRefund($paymentId, $refundId, "cumulative refunds ({$cumulative} paise) would exceed captured ({$captured} paise)", $paise, $currency, $eventId);
                }

                $full = $cumulative === $captured;
                $before = $sub->toArray();
                if ($full) {
                    $sub->update(['status' => 'cancelled', 'cancelled_at' => Carbon::now()]);
                    // a fully refunded term's receipt no longer stands
                    PlatformInvoice::where('shop_subscription_id', $sub->id)->where('status', '!=', 'cancelled')->get()
                        ->each(fn ($i) => $i->update(['status' => 'cancelled', 'cancelled_at' => Carbon::now(), 'cancellation_reason' => 'Full refund '.$refundId]));
                }

                SubscriptionEvent::create([
                    'shop_subscription_id' => $sub->id, 'shop_id' => $sub->shop_id, 'admin_id' => null,
                    'event_type' => $full ? 'subscription.refunded' : 'subscription.partial_refund', 'before' => $before,
                    'after' => array_merge($sub->fresh()->toArray(), [
                        'event_id' => $eventId, 'refund_id' => $refundId, 'payment_id' => $paymentId, 'refund_amount_paise' => $paise,
                        'currency' => $currency, 'captured_amount_paise' => $captured, 'cumulative_refunded_paise' => $cumulative,
                        'refund_classification' => $full ? 'full' : 'partial',
                    ]),
                    'reason' => 'Razorpay '.($full ? 'full' : 'partial').' refund: '.$refundId.' (cumulative '.number_format($cumulative / 100, 2).' of '.number_format($captured / 100, 2).')'.($full ? '' : ', subscription remains active'),
                ]);
                return SubscriptionPaymentService::OUTCOME_APPLIED;
            });
        } catch (\Throwable $e) {
            Log::error('Webhook: refund.created transient failure, will retry', ['event_id' => $eventId, 'payment_id' => $paymentId, 'error' => $e->getMessage()]);
            return SubscriptionPaymentService::OUTCOME_TRANSIENT;
        }
    }

    /** Immutable evidence, deduplicated per event id (else refund id). */
    private function refuseRefund(string $paymentId, string $refundId, string $reason, int $paise, string $currency, string $eventId): string
    {
        $existing = SubscriptionEvent::where('event_type', 'refund.invalid')->where('after->event_id', $eventId)->first();
        if ($existing) {
            $after = $existing->after;
            $after['attempt_count'] = (int) ($after['attempt_count'] ?? 1) + 1;
            $after['last_failed_at'] = now()->toIso8601String();
            $existing->update(['after' => $after]);
            return SubscriptionPaymentService::OUTCOME_PERMANENT;
        }
        SubscriptionEvent::create([
            'shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'refund.invalid', 'before' => null,
            'after' => ['event_id' => $eventId, 'payment_id' => $paymentId, 'refund_id' => $refundId, 'refund_amount_paise' => $paise, 'currency' => $currency,
                'attempt_count' => 1, 'first_failed_at' => now()->toIso8601String(), 'last_failed_at' => now()->toIso8601String(), 'resolved_at' => null, 'validation_reason' => $reason],
            'reason' => 'Razorpay refund refused, validation failed: '.$reason,
        ]);
        Log::critical('Refund refused, validation failed', ['event_id' => $eventId, 'payment_id' => $paymentId, 'refund_id' => $refundId, 'reason' => $reason]);
        return SubscriptionPaymentService::OUTCOME_PERMANENT;
    }
}
