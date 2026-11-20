<?php
namespace App\Services\Billing;

use App\Jobs\SendPlatformInvoiceEmail;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use Illuminate\Support\Facades\DB;

/**
 * One receipt per paid subscription term. Not GST-registered (default): a plain "Payment Receipt" with no tax lines.
 * With billing.gst_registered on, price_paid is treated as GST-inclusive and split like JewelFlow does.
 */
class PlatformInvoiceService
{
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
                'billing_period_end' => $subscription->ends_at ?? ($cycle === 'yearly' ? $subscription->starts_at->copy()->addYear() : $subscription->starts_at->copy()->addMonth()),
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
