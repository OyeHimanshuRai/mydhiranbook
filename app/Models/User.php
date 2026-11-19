<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'shop_id', 'role', 'is_active'];
    protected $attributes = ['is_active'=>true, 'role'=>'owner'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['password'=>'hashed', 'is_active'=>'boolean'];
    public function shop() { return $this->belongsTo(Shop::class); }
    public function may(string $permission): bool
    {
        return $this->is_active && match ($this->role) {
            'owner' => true,
            'operator' => in_array($permission, ['borrowers.write', 'loans.write', 'payments.write'], true),
            default => false,
        };
    }
}
