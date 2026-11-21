<?php
namespace App\Services\Billing;

use App\Jobs\SendPlatformInvoiceEmail;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One receipt per paid subscription term. Not GST-registered (default): a plain "Payment Receipt" with no tax lines.
 * With billing.gst_registered on, price_paid is treated as GST-inclusive and split into base and GST.
 */
class PlatformInvoiceService
{
    /**
     * Receipt bookkeeping must never undo a paid grant: runs in a savepoint, any failure is logged and recorded
     * (receipt.failed) and the caller's transaction carries on. Zero/unpaid terms get no receipt. Returns null if none issued.
     */
    public function issueSafely(ShopSubscription $subscription): ?PlatformInvoice
    {
        if ((float) $subscription->price_paid <= 0) { return null; }
        try {
            return DB::transaction(fn () => $this->issueForSubscription($subscription));
        } catch (\Throwable $e) {
            Log::error('Receipt issue failed; subscription kept, reconcile will retry', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);
            // one marker per subscription: the retry sweep must not append an event every run
            if (!SubscriptionEvent::where('shop_subscription_id', $subscription->id)->where('event_type', 'receipt.failed')->exists()) {
                SubscriptionEvent::create([
                    'shop_subscription_id' => $subscription->id, 'shop_id' => $subscription->shop_id, 'admin_id' => null,
                    'event_type' => 'receipt.failed', 'before' => null, 'after' => ['error' => mb_substr($e->getMessage(), 0, 500)],
                    'reason' => 'Receipt could not be issued; will be re-issued by reconcile.',
                ]);
            }
            return null;
        }
    }

    /** Issue receipts for paid terms that have none (a failed issue, or one lost before commit). @return int issued */
    public function reissueMissing(int $limit = 50): int
    {
        $issued = 0;
        ShopSubscription::where('price_paid', '>', 0)->where('status', '!=', 'cancelled')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('platform_invoices')->whereColumn('platform_invoices.shop_subscription_id', 'shop_subscriptions.id'))
            ->orderBy('id')->limit($limit)->get()->each(function ($sub) use (&$issued) { if ($this->issueSafely($sub)) { $issued++; } });
        return $issued;
    }

    /** Call inside the transaction that creates the term. Idempotent: a second call returns the existing receipt. */
    public function issueForSubscription(ShopSubscription $subscription): PlatformInvoice
    {
        $total = round((float) $subscription->price_paid, 2);
        if ($total <= 0) {
            throw new \InvalidArgumentException("Cannot issue a receipt for subscription #{$subscription->id}: price_paid is null or zero.");
        }

        return DB::transaction(function () use ($subscription, $total) {
            if ($existing = PlatformInvoice::where('shop_subscription_id', $subscription->id)->first()) { return $existing; }

            $rate = config('billing.gst_registered') ? (float) config('billing.gst_rate') : 0.0;
            $net = $rate > 0 ? round($total / (1 + $rate / 100), 2) : $total;
            $issuedAt = now();
            $sequence = $this->nextSequence($issuedAt->year);
            $cycle = $subscription->billing_cycle ?? 'monthly';

            $invoice = PlatformInvoice::create([
                'shop_id' => $subscription->shop_id, 'shop_subscription_id' => $subscription->id, 'plan_id' => $subscription->plan_id,
                'invoice_number' => sprintf('%s-%d-%06d', config('billing.receipt_prefix'), $issuedAt->year, $sequence),
                'invoice_sequence' => $sequence, 'billing_cycle' => $cycle,
                'billing_period_start' => $subscription->starts_at,
                'billing_period_end' => $subscription->ends_at ?? ($cycle === 'yearly' ? $subscription->starts_at->copy()->addYearNoOverflow() : $subscription->starts_at->copy()->addMonthNoOverflow()),
                'amount_before_tax' => $net, 'gst_rate' => $rate, 'gst_amount' => round($total - $net, 2), 'total_amount' => $total,
                'razorpay_payment_id' => $subscription->razorpay_payment_id, 'razorpay_order_id' => $subscription->razorpay_order_id,
                'payment_method' => 'razorpay', 'status' => 'issued', 'issued_at' => $issuedAt,
            ]);

            // The job is afterCommit: nothing is queued until the surrounding transaction commits.
            SendPlatformInvoiceEmail::dispatch($invoice->id);
            return $invoice;
        });
    }

    /** Gap-free per year: the counter row is locked until the issuing transaction ends, and rolls back with it. */
    private function nextSequence(int $year): int
    {
        $key = 'receipt:'.config('billing.receipt_prefix').':'.$year;
        DB::table('platform_counters')->insertOrIgnore(['key' => $key, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('platform_counters')->where('key', $key)->lockForUpdate()->first();
        $next = (int) $row->value + 1;
        DB::table('platform_counters')->where('key', $key)->update(['value' => $next, 'updated_at' => now()]);
        return $next;
    }
}
