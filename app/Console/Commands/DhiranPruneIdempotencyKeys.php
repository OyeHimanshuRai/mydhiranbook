<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

class DhiranPruneIdempotencyKeys extends Command
{
    /** A key only has to outlive a double click or a browser retry; 30 days is far beyond that. */
    private const KEEP_DAYS = 30;

    protected $signature = 'dhiran:prune-idempotency-keys';
    protected $description = 'Delete submit-once idempotency keys older than 30 days';

    public function handle(): int
    {
        $n = IdempotencyKey::where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        $this->info("Pruned {$n} idempotency key(s).");

        return self::SUCCESS;
    }
}
