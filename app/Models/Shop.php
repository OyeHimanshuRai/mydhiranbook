<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shop extends Model
{
    protected $attributes = ['status'=>'active'];
    public function users() { return $this->hasMany(User::class); }
    public function hasEdition(string $edition): bool
    {
        return $edition === 'dhiran' && \Illuminate\Support\Facades\DB::table('subscriptions')->where('shop_id',$this->id)
            ->whereIn('status',['active','trial'])->where(function($q){$q->whereNull('expires_at')->orWhereDate('expires_at','>=',today());})->exists();
    }
    public function staffLimit(): int { return -1; }
    public function currentStaffCount(): int { return $this->users()->where('is_active',true)->whereNotNull('role_id')->whereHas('assignedRole',fn($q)=>$q->where('name','!=','owner'))->count(); }
    public function canAddStaff(): bool { return $this->staffLimit() === -1 || $this->currentStaffCount() < $this->staffLimit(); }
    protected $fillable = ['name', 'phone', 'address', 'status', 'financial_lock_date'];
}
