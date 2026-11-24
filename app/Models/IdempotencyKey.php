<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Submit-once key row for the Dhiran money forms.
 *
 * Written and read by App\Http\Middleware\DhiranSubmitOnce. Pruned daily by
 * `dhiran:prune-idempotency-keys` (keys older than 30 days). Deliberately has NO BelongsToShop global
 * scope: the middleware queries by explicit (shop_id, user_id, key) tuple.
 */
class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    protected $fillable = [
        'shop_id',
        'user_id',
        'key',
        'request_hash',
        'response_status',
        'response_body',
        'response_headers',
    ];

    protected $casts = [
        'shop_id' => 'integer',
        'user_id' => 'integer',
        'response_status' => 'integer',
        'response_body' => 'array',
        'response_headers' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
