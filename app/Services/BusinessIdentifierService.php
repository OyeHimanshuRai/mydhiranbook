<?php
namespace App\Services;
use App\Models\ShopCounter;
use Illuminate\Support\Facades\DB;
use LogicException;
class BusinessIdentifierService
{
    public const KEY_DHIRAN = 'dhiran';
    public static function nextCounter(int $shopId, string $counterKey): int
    {
        if ($shopId <= 0) {
            throw new LogicException('Valid shop_id is required to generate business identifiers.');
        }

        return DB::transaction(function () use ($shopId, $counterKey): int {
            $counter = ShopCounter::query()
                ->where('shop_id', $shopId)
                ->where('counter_key', $counterKey)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                // The counter's first use. Two first uses at the same instant both
                // get here. ON CONFLICT DO NOTHING lets the second wait for the
                // first to commit and then insert nothing, without an error.
                // (Catching the unique violation instead, as this did, does not
                // work on PostgreSQL: the failed INSERT aborts the transaction and
                // every later statement in it, the caller's included, is refused.)
                // Only that conflict is absorbed: any other failure still raises.
                ShopCounter::query()->insertOrIgnore([
                    'shop_id'       => $shopId,
                    'counter_key'   => $counterKey,
                    'current_value' => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                $counter = ShopCounter::query()
                    ->where('shop_id', $shopId)
                    ->where('counter_key', $counterKey)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $next = (int) $counter->current_value + 1;
            $counter->current_value = $next;
            $counter->save();

            return $next;
        });
    }

}
