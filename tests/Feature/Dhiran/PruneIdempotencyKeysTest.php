<?php

namespace Tests\Feature\Dhiran;

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F8: submit-once keys older than 30 days are pruned daily; nothing younger is ever touched. */
class PruneIdempotencyKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_only_keys_older_than_thirty_days(): void
    {
        $shop = self::createDhiranShop(['name' => 'Pawn Co', 'shop_type' => 'dhiran']);
        $ages = ['old' => 31, 'older' => 400, 'edge' => 29, 'fresh' => 0];
        foreach ($ages as $key => $days) {
            $row = new IdempotencyKey(['shop_id' => $shop->id, 'key' => "dhiran:$key", 'request_hash' => 'h', 'response_status' => 302]);
            $row->created_at = now()->subDays($days)->subMinutes($days ? 5 : 0);
            $row->save();
        }

        $this->artisan('dhiran:prune-idempotency-keys')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['dhiran:edge', 'dhiran:fresh'], IdempotencyKey::pluck('key')->all());
    }

    public function test_is_scheduled_daily(): void
    {
        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($e) => str_contains($e->command, 'dhiran:prune-idempotency-keys') && $e->expression === '0 0 * * *'));
    }
}
