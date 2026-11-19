<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Log;
use LogicException;

trait ImmutableLedger
{
    protected static function bootImmutableLedger(): void
    {
        static::updating(function ($model) {
            $allowed = $model->allowedUpdateColumns ?? [];
            $blocked = array_diff(array_keys($model->getDirty()), $allowed);
            if (! empty($blocked)) {
                static::logMutationAttempt($model, 'update_blocked');
                throw new LogicException(static::class . ' is append-only and cannot be updated.');
            }
        });

        static::deleting(function ($model) {
            static::logMutationAttempt($model, 'delete_blocked');
            throw new LogicException(static::class . ' is append-only and cannot be deleted.');
        });
    }

    protected static function logMutationAttempt($model, string $action): void
    {
        try {
            if (!auth()->check()) {
                return;
            }

            Log::warning('Blocked Dhiran financial mutation', ['shop_id' => $model->shop_id, 'model' => static::class, 'id' => $model->id, 'action' => $action]);
        } catch (\Throwable) {
            // Never break core write paths because audit log write failed.
        }
    }
}
