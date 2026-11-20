<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shop extends Model
{
    protected $attributes = ['status'=>'active'];
    public function users() { return $this->hasMany(User::class); }
    public function subscriptions() { return $this->hasMany(\App\Models\Platform\ShopSubscription::class); }
    /** Latest non-cancelled subscription by end date. */
    public function currentSubscription(): ?\App\Models\Platform\ShopSubscription
    {
        return $this->subscriptions()->with('plan')->where('status','!=','cancelled')->orderByRaw('ends_at desc nulls last')->orderByDesc('id')->first();
    }
    public function hasEdition(string $edition): bool
    {
        return $edition === 'dhiran' && (bool) $this->currentSubscription()?->grantsAccessToday();
    }
    public function isLapsed(): bool { return !$this->hasEdition('dhiran'); }
    public function staffLimit(): int { return (int) ($this->currentSubscription()?->plan?->feature('staff_limit', -1) ?? -1); }
    public function itemLimit(): int { return (int) ($this->currentSubscription()?->plan?->feature('max_items', -1) ?? -1); }
    public function currentStaffCount(): int { return $this->users()->where('is_active',true)->whereNotNull('role_id')->whereHas('assignedRole',fn($q)=>$q->where('name','!=','owner'))->count(); }
    public function canAddStaff(): bool { return $this->staffLimit() === -1 || $this->currentStaffCount() < $this->staffLimit(); }
    protected $fillable = ['name', 'phone', 'address', 'status', 'financial_lock_date'];
}
