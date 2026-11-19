<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shop extends Model
{
    protected $attributes = ['status'=>'active'];
    public function users() { return $this->hasMany(User::class); }
    public function hasEdition(string $edition): bool { return $edition === 'dhiran' && (bool)\App\Models\Dhiran\DhiranSettings::getForShop($this->id)->is_enabled; }
    public function staffLimit(): int { return -1; }
    public function currentStaffCount(): int { return $this->users()->where('is_active',true)->whereNotNull('role_id')->whereHas('assignedRole',fn($q)=>$q->where('name','!=','owner'))->count(); }
    public function canAddStaff(): bool { return $this->staffLimit() === -1 || $this->currentStaffCount() < $this->staffLimit(); }
    protected $fillable = ['name', 'phone', 'address', 'status', 'financial_lock_date'];
}
