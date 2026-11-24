<?php

namespace App\Models\Dhiran;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;

/** One line of a loan's activity log. Append-only in PHP (ImmutableLedger) and in the database (trigger). */
class LoanEvent extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $table = 'dhiran_loan_events';

    public const UPDATED_AT = null;

    public const CATEGORIES = ['money' => 'Money', 'item' => 'Items', 'document' => 'Documents', 'loan' => 'Loan', 'print' => 'Prints', 'system' => 'System'];

    protected $fillable = ['shop_id', 'dhiran_loan_id', 'user_id', 'actor_name', 'event', 'category', 'summary', 'details', 'ip', 'occurred_at'];

    protected $casts = ['details' => 'array', 'occurred_at' => 'datetime'];

    public function loan()
    {
        return $this->belongsTo(DhiranLoan::class, 'dhiran_loan_id');
    }
}
