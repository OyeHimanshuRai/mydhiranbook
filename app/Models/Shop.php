<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shop extends Model
{
    protected $attributes = ['status'=>'active'];
    protected $fillable = ['name', 'phone', 'address', 'status', 'financial_lock_date'];
}
