<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'shop_id', 'role', 'is_active','mobile_number','role_id','realm','email_verified_at','first_name','last_name'];
    protected $attributes = ['is_active'=>true, 'role'=>'owner'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['password'=>'hashed', 'is_active'=>'boolean'];
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
        return $this->is_active && match ($this->role) {
            'owner' => true,
            'operator' => in_array($permission, ['borrowers.write', 'loans.write', 'payments.write'], true),
            default => false,
        };
    }
}
