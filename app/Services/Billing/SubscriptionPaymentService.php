<?php
namespace App\Services\Billing;

use App\Mail\UnresolvedPaymentAlert;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Money core. Callback, webhook and reconcile all converge on finalizeCapturedPayment(), so one
 * captured payment yields one subscription row and one payment event, in any order, however often retried.
 * Provider fetches happen before the DB transaction; nothing talks to Razorpay under a row lock.
 */
class SubscriptionPaymentService
{
    public const OUTCOME_APPLIED = 'applied';     // 200: applied or already applied
    public const OUTCOME_PERMANENT = 'permanent'; // 200 + evidence: a retry can never fix it
    public const OUTCOME_TRANSIENT = 'transient'; // 500: provider/DB hiccup, safe to retry
    public const EXPECTED_CURRENCY = 'INR';

    public function __construct(private readonly RazorpayClient $razorpay, private readonly PlatformInvoiceService $receipts) {}

    /** @throws \Razorpay\Api\Errors\SignatureVerificationError */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): void
    {
        $this->razorpay->verifyPaymentSignature($orderId, $paymentId, $signature);
    }

    /** @return array{order: object, plan: Plan, billing_cycle: string} @throws \Exception */
    public function fetchAndValidateOrder(string $orderId): array
    {
        $order = $this->razorpay->fetchOrder($orderId);
        $notes = (array) ($order->notes ?? []);
        $plan = isset($notes['plan_id']) ? Plan::find($notes['plan_id']) : null;
        $cycle = $notes['billing_cycle'] ?? null;
        if (!$plan || !$cycle) { throw new \Exception('Plan not found in order notes.'); }
        if (!$plan->sellsCycle($cycle)) { throw new \Exception("Invalid billing cycle: {$plan->name} is not sold {$cycle}."); }
        return ['order' => $order, 'plan' => $plan, 'billing_cycle' => $cycle];
    }

    /** @return int expected paise */
    public function verifyAmount(object $rzpOrder, Plan $plan, string $billingCycle): int
    {
        if (!$plan->sellsCycle($billingCycle)) { throw new \Exception("Invalid billing cycle: {$plan->name} is not sold {$billingCycle}."); }
        $expected = $plan->priceFor($billingCycle) * 100;
        if ((int) $rzpOrder->amount !== $expected) {
            Log::error('Razorpay amount mismatch', ['order_id' => $rzpOrder->id, 'expected_paise' => $expected, 'actual_paise' => $rzpOrder->amount, 'plan_id' => $plan->id]);
            throw new \Exception('Payment amount mismatch.');
        }
        return $expected;
    }

    public function verifyCurrency(object $rzpOrder): void
    {
        if (($rzpOrder->currency ?? null) !== self::EXPECTED_CURRENCY) { throw new \Exception('Payment currency mismatch.'); }
    }

    /** The captured money itself must match the order it is being applied to: same order, same paise, INR. */
    public function verifyPaymentCaptured(string $paymentId, ?string $orderId = null, ?int $expectedPaise = null): void
    {
        $payment = $this->razorpay->fetchPayment($paymentId);
        if (($payment->status ?? null) !== 'captured') { throw new \Exception('Payment not captured.'); }
        if ($orderId !== null && ($payment->order_id ?? null) !== $orderId) { throw new \LogicException("Payment {$paymentId} was made on a different order than {$orderId}."); }
        if ($expectedPaise !== null && (int) ($payment->amount ?? -1) !== $expectedPaise) { throw new \LogicException("Captured amount of payment {$paymentId} does not match the order price."); }
        if (($payment->currency ?? null) !== self::EXPECTED_CURRENCY) { throw new \LogicException("Captured currency of payment {$paymentId} is not ".self::EXPECTED_CURRENCY.'.'); }
    }

    public function findExistingSubscription(string $paymentId): ?ShopSubscription
    {
        return ShopSubscription::where('razorpay_payment_id', $paymentId)->first();
    }

    public function createRazorpayOrder(Plan $plan, string $billingCycle): object
    {
        $user = Auth::user();
        return $this->razorpay->createOrder([
            'amount' => $plan->priceFor($billingCycle) * 100,
            'currency' => self::EXPECTED_CURRENCY,
            'receipt' => 'dhr_'.$user->id.'_'.time(),
            'notes' => ['plan_id' => $plan->id, 'plan_name' => $plan->name, 'billing_cycle' => $billingCycle, 'user_id' => $user->id],
        ]);
    }

    /** First term for a shopless payer, or a renewal when the shop already has a subscription. */
    public function createSubscription(Plan $plan, string $billingCycle, float $expectedPrice, string $paymentId, string $orderId, ?User $actor = null): ShopSubscription
    {
        $actor ??= Auth::user();
        $current = $actor?->shop_id ? $this->latestTermFor((int) $actor->shop_id) : null;
        return $this->persistTerm($plan, $billingCycle, $expectedPrice, $paymentId, $orderId, $actor, $current);
    }

    /** Renewal: a NEW row for the next term; the current row is never mutated. */
    public function renewSubscription(ShopSubscription $current, Plan $plan, string $billingCycle, float $price, string $paymentId, string $orderId, ?User $actor = null): ShopSubscription
    {
        return $this->persistTerm($plan, $billingCycle, $price, $paymentId, $orderId, $actor ?? $current->user, $current);
    }

    /** The payer's latest non-cancelled term: the shop's, or for a shopless buyer their own pending-business terms. */
    private function latestTermFor(?int $shopId, ?int $userId = null): ?ShopSubscription
    {
        return ShopSubscription::where('status', '!=', 'cancelled')
            ->when($shopId, fn ($q) => $q->where('shop_id', $shopId), fn ($q) => $q->whereNull('shop_id')->where('user_id', $userId))
            ->orderByRaw('ends_at desc nulls last')->orderByDesc('id')->first();
    }

    private function persistTerm(Plan $plan, string $cycle, float $price, string $paymentId, string $orderId, ?User $actor, ?ShopSubscription $hint): ShopSubscription
    {
        try {
            [$sub, $created] = DB::transaction(function () use ($plan, $cycle, $price, $paymentId, $orderId, $actor, $hint) {
                $shopId = $actor?->shop_id ?? $hint?->shop_id;
                // serialise this payer's terms: the shop row, or for a shopless buyer their own user row
                if ($shopId) { Shop::whereKey($shopId)->lockForUpdate()->first(); }
                elseif ($actor) { User::whereKey($actor->id)->lockForUpdate()->first(); }
                if ($existing = $this->findExistingSubscription($paymentId)) { return [$existing, false]; }
                if (ShopSubscription::where('razorpay_order_id', $orderId)->exists()) {
                    throw new \LogicException("Order {$orderId} is already paid by another payment; this one needs a refund.");
                }

                $previous = ($shopId || $actor) ? $this->latestTermFor($shopId ? (int) $shopId : null, $actor?->id) : null;
                $startsAt = $this->termStart($previous);
                $endsAt = $cycle === 'yearly' ? $startsAt->copy()->addYearNoOverflow()->subDay() : $startsAt->copy()->addMonthNoOverflow()->subDay();

                $sub = ShopSubscription::create([
                    'shop_id' => $shopId, 'user_id' => $actor?->id, 'plan_id' => $plan->id, 'status' => 'active', 'billing_cycle' => $cycle,
                    'starts_at' => $startsAt, 'ends_at' => $endsAt, 'grace_ends_at' => $endsAt->copy()->addDays($plan->grace_days),
                    'price_paid' => $price, 'razorpay_payment_id' => $paymentId, 'razorpay_order_id' => $orderId,
                    'updated_by_admin_id' => null, 'actor_type' => 'self_service',
                ]);
                SubscriptionEvent::create([
                    'shop_subscription_id' => $sub->id, 'shop_id' => $sub->shop_id, 'admin_id' => null,
                    'event_type' => $previous ? 'subscription.renewed' : 'subscription.paid',
                    'before' => $previous?->toArray(), 'after' => $sub->toArray(),
                    'reason' => ($previous ? 'Renewal' : 'Payment').' via Razorpay: '.$paymentId.($previous ? " (previous subscription #{$previous->id})" : ''),
                ]);
                $this->receipts->issueSafely($sub);
                return [$sub, true];
            });
        } catch (UniqueConstraintViolationException $e) {
            Log::info('Concurrent payment application caught by unique constraint', ['payment_id' => $paymentId]);
            $sub = $this->findExistingSubscription($paymentId);
            // only a violation on the subscription's own razorpay keys means "paid by another payment"; anything else is retryable
            if (!$sub && !str_contains($e->getMessage(), 'shop_subscriptions_razorpay')) {
                throw new \RuntimeException('Unexpected unique violation while applying payment: '.$e->getMessage(), 0, $e);
            }
            if (!$sub) { throw new \LogicException("Order {$orderId} is already paid by another payment; this one needs a refund."); }
            return $sub;
        }

        if ($created) { $this->markPaymentResolved($paymentId, $sub); }
        return $sub;
    }

    /** Renewing inside the paid term or grace continues from the day after ends_at; after that, a fresh term from today. */
    private function termStart(?ShopSubscription $previous): Carbon
    {
        $today = Carbon::today();
        $graceEnd = $previous?->grace_ends_at ?? $previous?->ends_at;
        if (!$previous || !$previous->ends_at || !$graceEnd || $today->gt($graceEnd->copy()->startOfDay())) { return $today; }
        return $previous->ends_at->copy()->startOfDay()->addDay();
    }

    /**
     * Session-less finalization of a captured payment. Everything is re-resolved server-side from the
     * Razorpay order (payer from notes.user_id, plan/cycle from notes, amount checked against the plan).
     *
     * @throws \LogicException any refusal that retrying can never fix (wrong payer, double payment on an order)
     * @throws \Exception amount/currency mismatch, not captured, plan missing, provider failure
     */
    public function finalizeCapturedPayment(string $orderId, string $paymentId): ShopSubscription
    {
        if ($existing = $this->findExistingSubscription($paymentId)) { return $existing; }

        ['order' => $order, 'plan' => $plan, 'billing_cycle' => $cycle] = $this->fetchAndValidateOrder($orderId);
        $userId = ((array) ($order->notes ?? []))['user_id'] ?? null;
        $actor = $userId ? User::find($userId) : null;
        if (!$actor) { throw new \RuntimeException("Razorpay order {$orderId} has no resolvable user (notes.user_id)."); }
        if ($actor->shop_id && !$actor->isShopOwner()) { throw new \LogicException('Payer is not the owner of the shop; payment not applied.'); }
        if (!$plan->is_active) { throw new \Exception("Plan {$plan->id} is not active; payment cannot be applied."); }

        $this->verifyCurrency($order);
        $paise = $this->verifyAmount($order, $plan, $cycle);
        $this->verifyPaymentCaptured($paymentId, $orderId, $paise);

        return $this->createSubscription($plan, $cycle, (float) $plan->priceFor($cycle), $paymentId, $orderId, $actor);
    }

    /** Never throws. Failures leave admin-visible evidence and are classified permanent or transient. @return array{outcome:string,subscription:?ShopSubscription} */
    public function applyCapturedPayment(string $orderId, string $paymentId): array
    {
        try {
            return ['outcome' => self::OUTCOME_APPLIED, 'subscription' => $this->finalizeCapturedPayment($orderId, $paymentId)];
        } catch (\LogicException $e) {
            $this->recordUnresolvedPayment($orderId, $paymentId, 'refused: '.$e->getMessage(), false);
            return ['outcome' => self::OUTCOME_PERMANENT, 'subscription' => null];
        } catch (\Throwable $e) {
            $transient = $this->isTransientPaymentError($e);
            $this->recordUnresolvedPayment($orderId, $paymentId, ($transient ? 'transient: ' : 'permanent: ').$e->getMessage(), $transient);
            return ['outcome' => $transient ? self::OUTCOME_TRANSIENT : self::OUTCOME_PERMANENT, 'subscription' => null];
        }
    }

    public function reconcileCapturedPayment(string $orderId, string $paymentId): ?ShopSubscription
    {
        return $this->applyCapturedPayment($orderId, $paymentId)['subscription'];
    }

    /** Permanent = a server-side validation failure retrying cannot fix; anything else (provider, DB) is transient. */
    public function isTransientPaymentError(\Throwable $e): bool
    {
        if ($e instanceof \LogicException) { return false; }
        foreach (['amount mismatch', 'currency mismatch', 'not captured', 'Plan not found in order notes', 'Invalid billing cycle', 'is not active', 'has no resolvable user'] as $needle) {
            if (stripos($e->getMessage(), $needle) !== false) { return false; }
        }
        return true;
    }

    /** One record per payment: the first failure creates it and alerts ops; repeats only advance attempt_count. */
    public function recordUnresolvedPayment(string $orderId, string $paymentId, string $reason, bool $transient = false): void
    {
        $now = now()->toIso8601String();
        $text = 'Captured Razorpay payment not applied, needs admin review: '.$reason;
        $event = SubscriptionEvent::where('event_type', 'payment.unresolved')->where('after->payment_id', $paymentId)->first();

        if ($event) {
            $after = $event->after ?? [];
            if (!empty($after['resolved_at'])) { return; }
            $after['attempt_count'] = (int) ($after['attempt_count'] ?? 1) + 1;
            $after['last_failed_at'] = $now;
            $after['transient'] = $transient;
            $event->update(['after' => $after, 'reason' => $text]);
            return;
        }

        SubscriptionEvent::create([
            'shop_subscription_id' => null, 'shop_id' => null, 'admin_id' => null, 'event_type' => 'payment.unresolved', 'before' => null,
            'after' => ['order_id' => $orderId, 'payment_id' => $paymentId, 'attempt_count' => 1, 'first_failed_at' => $now, 'last_failed_at' => $now, 'transient' => $transient, 'resolved_at' => null],
            'reason' => $text,
        ]);
        Log::critical('Captured payment unresolved, admin review required', ['order_id' => $orderId, 'payment_id' => $paymentId, 'reason' => $reason, 'transient' => $transient]);

        if ($to = config('billing.alert_email')) {
            try { Mail::to($to)->queue(new UnresolvedPaymentAlert($paymentId, $orderId, $text, $transient)); }
            catch (\Throwable $e) { Log::error('Unresolved-payment alert could not be queued', ['payment_id' => $paymentId, 'error' => $e->getMessage()]); }
        }
    }

    /** Flip an open payment.unresolved record to resolved once its payment applies. */
    private function markPaymentResolved(string $paymentId, ShopSubscription $sub): void
    {
        $event = SubscriptionEvent::where('event_type', 'payment.unresolved')->where('after->payment_id', $paymentId)->first();
        $after = $event?->after ?? [];
        if (!$event || !empty($after['resolved_at'])) { return; }
        $after['resolved_at'] = now()->toIso8601String();
        $after['resolved_subscription_id'] = $sub->id;
        $event->update(['after' => $after, 'reason' => $event->reason.' [RESOLVED, subscription #'.$sub->id.']']);
    }
}
