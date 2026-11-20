<?php
namespace App\Models\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price_monthly' => 'decimal:2', 'price_yearly' => 'decimal:2', 'grace_days' => 'integer', 'is_active' => 'boolean', 'features' => 'array'];
    }

    public function subscriptions(): HasMany { return $this->hasMany(ShopSubscription::class); }

    /** Whole rupees charged for a cycle ('yearly', anything else = monthly). */
    public function priceFor(string $cycle): int
    {
        return (int) round((float) ($cycle === 'yearly' ? $this->price_yearly : $this->price_monthly));
    }

    /** A plan sells exactly one cycle: yearly when it has a yearly price, otherwise monthly. */
    public function sellsCycle(string $cycle): bool
    {
        return match ($cycle) {
            'yearly' => (float) $this->price_yearly > 0,
            'monthly' => $this->price_yearly === null && (float) $this->price_monthly > 0,
            default => false,
        };
    }

    public function feature(string $key, mixed $default = null): mixed { return ($this->features ?? [])[$key] ?? $default; }
}
