<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shop extends Model
{
    protected $attributes = ['status'=>'active'];
    protected $casts = ['reviewed_at'=>'datetime','resubmitted_at'=>'datetime'];
    /** A new shop waits for an admin to approve it (pending), or was turned down (rejected); only active shops open the software. */
    public function awaitsApproval(): bool { return in_array($this->status, ['pending', 'rejected'], true); }
    public function users() { return $this->hasMany(User::class); }
    public function subscriptions() { return $this->hasMany(\App\Models\Platform\ShopSubscription::class); }
    /** The live term that has started (an early-renewed future term only counts once it starts); else the next one queued. */
    public function currentSubscription(): ?\App\Models\Platform\ShopSubscription
    {
        $live = fn () => $this->subscriptions()->with('plan')->where('status','!=','cancelled');
        return $live()->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at','<=',today()))->orderByRaw('ends_at desc nulls last')->orderByDesc('id')->first()
            ?? $live()->orderBy('starts_at')->orderBy('id')->first();
    }
    /** The last day the shop can use the software: the latest cut-off (end, or the end of any grace) over its live terms; $startedOnly ignores terms that have not begun. */
    public function accessUntil(bool $startedOnly = false): ?\Illuminate\Support\Carbon
    {
        $rows = $this->subscriptions()->where('status','!=','cancelled')->get();
        if ($startedOnly) { $rows = $rows->filter(fn ($r) => $r->starts_at === null || $r->starts_at->toDateString() <= today()->toDateString()); }
        return $rows->flatMap(fn ($r) => [$r->ends_at, $r->grace_ends_at])->filter()->max();
    }
    public function hasEdition(string $edition): bool
    {
        return $edition === 'dhiran' && (bool) $this->currentSubscription()?->grantsAccessToday();
    }
    public function isLapsed(): bool { return !$this->hasEdition('dhiran'); }
    public function staffLimit(): int { return (int) ($this->currentSubscription()?->plan?->feature('staff_limit', -1) ?? -1); }
    public function itemLimit(): int { return (int) ($this->currentSubscription()?->plan?->feature('max_items', -1) ?? -1); }
    public function currentStaffCount(): int { return $this->users()->where('is_active',true)->whereNotNull('role_id')->whereHas('assignedRole',fn($q)=>$q->where('name','!=','owner'))->count(); }
    /** Pledged items still held on this shop's unfinished loans (what max_items caps). */
    public function pledgedItemCount(): int
    {
        return \App\Models\Dhiran\DhiranLoanItem::withoutGlobalScopes()->where('shop_id', $this->id)->where('status', 'pledged')
            ->whereIn('dhiran_loan_id', \App\Models\Dhiran\DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->id)->whereNotIn('status', \App\Models\Dhiran\DhiranLoan::FINISHED)->select('id'))->count();
    }
    // a lapsed shop (refunded/terminated/expired) is collect-only: no new staff either, whatever the old plan allowed
    public function canAddStaff(): bool { return !$this->isLapsed() && ($this->staffLimit() === -1 || $this->currentStaffCount() < $this->staffLimit()); }
    protected $fillable = ['name', 'phone', 'address', 'status', 'financial_lock_date', 'reviewed_at', 'review_note', 'submissions', 'resubmitted_at', 'previous_review_note'];
}
