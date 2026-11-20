<?php
namespace App\Console\Commands;

use App\Services\Billing\RazorpayClient;
use App\Services\Billing\SubscriptionPaymentService;
use Illuminate\Console\Command;

/**
 * Backstop for captured-but-unapplied Razorpay payments (browser closed and webhook lost).
 *   subscription:reconcile-payments                              scheduled sweep
 *   subscription:reconcile-payments --payment=pay_X --order=order_Y   one known payment
 */
class ReconcileCapturedPayments extends Command
{
    protected $signature = 'subscription:reconcile-payments
        {--payment= : Reconcile a single Razorpay payment id (requires --order)}
        {--order= : Razorpay order id, used with --payment}
        {--days=2 : Days back to scan captured payments}
        {--limit=25 : Max payments reconciled per run (bounds provider calls)}
        {--sleep-ms=200 : Pause between provider-touching reconciles}';

    protected $description = 'Finalize captured Razorpay payments never applied to a subscription.';

    public function handle(SubscriptionPaymentService $service, RazorpayClient $razorpay): int
    {
        if ($paymentId = $this->option('payment')) {
            if (!($orderId = $this->option('order'))) { $this->error('--order is required with --payment.'); return self::FAILURE; }
            $sub = $service->reconcileCapturedPayment($orderId, $paymentId);
            $this->line($sub ? "Applied {$paymentId} -> subscription {$sub->id}" : "Could not apply {$paymentId}; recorded for admin review.");
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $applied = 0; $attempted = 0;
        try { $candidates = $razorpay->capturedPaymentsSince(now()->subDays(max(1, (int) $this->option('days')))->timestamp); }
        catch (\Throwable $e) { $this->error('Could not list Razorpay payments: '.$e->getMessage()); return self::FAILURE; }

        foreach ($candidates as $c) {
            if (empty($c['id']) || empty($c['order_id']) || $service->findExistingSubscription($c['id'])) { continue; }
            if ($attempted >= $limit) { $this->warn("Reconcile limit ({$limit}) reached; the rest waits for the next run."); break; }
            if ($attempted++ > 0 && $sleepMs > 0) { usleep($sleepMs * 1000); }
            if ($service->reconcileCapturedPayment($c['order_id'], $c['id'])) { $applied++; }
        }
        $this->info("Reconcile complete: {$applied} payment(s) applied.");
        return self::SUCCESS;
    }
}
