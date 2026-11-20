<?php

namespace App\Console\Commands;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckSubscriptionExpiry extends Command
{
    protected $signature = 'subscription:check-expiry';
    protected $description = 'Move subscriptions active -> grace -> expired by date (bookkeeping; access is date-derived anyway)';

    public function handle(): int
    {
        // ponytail: shop_subscriptions is platform-level (no tenant scope), so no TenantContext here.
        $moved = 0;
        ShopSubscription::with('plan')->whereIn('status', ['active', 'grace'])->orderBy('id')->each(function (ShopSubscription $sub) use (&$moved) {
            DB::transaction(function () use ($sub, &$moved) {
                $sub = ShopSubscription::whereKey($sub->id)->lockForUpdate()->first();
                if ($sub->status === 'active' && $sub->ends_at && $sub->ends_at->lt(today())) {
                    $this->move($sub, 'grace', ['grace_ends_at' => $sub->grace_ends_at ?? $sub->ends_at->copy()->addDays((int) ($sub->plan?->grace_days ?? 0))]);
                    $moved++;
                }
                if ($sub->status === 'grace' && ($sub->grace_ends_at ?? $sub->ends_at)?->lt(today())) {
                    $this->move($sub, 'expired');
                    $moved++;
                }
            });
        });
        $this->info("Done. Status changes: {$moved}.");
        return self::SUCCESS;
    }

    private function move(ShopSubscription $sub, string $to, array $extra = []): void
    {
        $before = $sub->only(['status', 'grace_ends_at']);
        $sub->update(['status' => $to] + $extra);
        SubscriptionEvent::create([
            'shop_subscription_id' => $sub->id, 'shop_id' => $sub->shop_id, 'admin_id' => null,
            'event_type' => "subscription.{$to}", 'before' => $before, 'after' => $sub->only(['status', 'grace_ends_at']),
            'reason' => 'Daily expiry check',
        ]);
    }
}
