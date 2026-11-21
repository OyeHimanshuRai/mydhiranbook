<?php
namespace App\Models\Platform;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopSubscription extends Model
{
    public const STATUSES = ['active', 'grace', 'expired', 'cancelled'];

    protected $fillable = ['shop_id', 'user_id', 'plan_id', 'status', 'billing_cycle', 'starts_at', 'ends_at', 'grace_ends_at', 'cancelled_at', 'price_paid', 'razorpay_order_id', 'razorpay_payment_id', 'updated_by_admin_id', 'actor_type', 'notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'date', 'ends_at' => 'date', 'grace_ends_at' => 'date', 'cancelled_at' => 'datetime', 'price_paid' => 'decimal:2'];
    }

    public function shop(): BelongsTo { return $this->belongsTo(Shop::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(PlatformAdmin::class, 'updated_by_admin_id'); }
    public function events(): HasMany { return $this->hasMany(SubscriptionEvent::class); }

    /** Paid but no business created yet. */
    public function isPendingBusiness(): bool { return $this->shop_id === null; }

    /** Whole days until ends_at (negative = overdue); null without an end date. */
    public function daysRemaining(): ?int
    {
        return $this->ends_at ? (int) now()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay(), false) : null;
    }

    /** Date-derived access: active|grace until max(ends_at, grace_ends_at), inclusive; expired/cancelled never. */
    public function grantsAccessToday(): bool
    {
        if (!in_array($this->status, ['active', 'grace'], true)) { return false; }
        if ($this->starts_at !== null && $this->starts_at->toDateString() > today()->toDateString()) { return false; } // queued term: not yet
        $until = collect([$this->ends_at, $this->grace_ends_at])->filter()->max();
        return $until !== null && $until->toDateString() >= today()->toDateString();
    }
}
