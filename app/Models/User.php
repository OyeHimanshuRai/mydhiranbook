<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable
{
    use Notifiable;
    protected $fillable = ['name', 'email', 'password', 'shop_id', 'role', 'is_active','mobile_number','role_id','realm','email_verified_at','first_name','last_name','terms_accepted_at'];
    protected $attributes = ['is_active'=>true, 'role'=>'owner'];
    protected $hidden = ['password', 'remember_token', 'email_verify_otp'];
    protected $casts = ['password'=>'hashed', 'is_active'=>'boolean','email_verified_at'=>'datetime','terminated_at'=>'datetime','terms_accepted_at'=>'datetime','email_otp_expires_at'=>'datetime'];
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->name ??= trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->mobile_number ?? $user->email);
        });
    }
    /** Captured-payment subscriptions waiting for a business (paid before the shop exists). */
    public function paidShoplessSubscriptions() { return \App\Models\Platform\ShopSubscription::where('user_id',$this->id)->whereNull('shop_id')->whereIn('status',['active','grace'])->whereRaw('GREATEST(ends_at, grace_ends_at) >= ?',[today()->toDateString()]); } // same rule as ShopSubscription::grantsAccessToday
    public function shop() { return $this->belongsTo(Shop::class); }
    public function assignedRole() { return $this->belongsTo(Role::class, 'role_id'); }
    public function hasRole(string $name): bool { return $this->role_id ? ($this->assignedRole?->name === $name && (int)$this->assignedRole?->shop_id === (int)$this->shop_id) : $this->role === $name; }
    public function isOwner(): bool { return $this->hasRole('owner'); }
    public function isShopOwner(): bool { return $this->role_id ? Role::withoutTenant()->whereKey($this->role_id)->where('shop_id',$this->shop_id)->where('name','owner')->exists() : $this->role === 'owner'; }
    public function isDhiran(): bool { return true; }
    public function isErp(): bool { return false; }
    public function hasPermission(string $permission): bool
    {
        if (!$this->is_active) { return false; }
        if ($this->role_id) { return (int)$this->assignedRole?->shop_id === (int)$this->shop_id && $this->assignedRole->hasPermission($permission); }
        return $this->role === 'owner' || ($this->role === 'operator' && in_array($permission,['dhiran.view','dhiran.create','dhiran.pay'],true)) || ($this->role === 'viewer' && $permission === 'dhiran.view');
    }
    public function scopeActive($query) { return $query->where('is_active',true); }
    public function may(string $permission): bool
    {
        return match ($permission) {
            'borrowers.write', 'loans.write' => $this->hasPermission('dhiran.create'),
            'payments.write' => $this->hasPermission('dhiran.pay'),
            'staff.manage' => $this->hasPermission('staff.manage'),
            default => false,
        };
    }
}
