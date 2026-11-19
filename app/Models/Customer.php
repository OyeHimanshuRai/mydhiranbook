<?php
namespace App\Models;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ArchivableParty;
use Illuminate\Database\Eloquent\Model;
class Customer extends Model
{
    use BelongsToShop, ArchivableParty;
    protected $fillable = ['shop_id', 'first_name', 'last_name', 'mobile', 'address', 'is_active'];
    protected $casts = ['is_active'=>'boolean'];
    public function getNameAttribute(): string { return trim($this->first_name.' '.$this->last_name); }
}
