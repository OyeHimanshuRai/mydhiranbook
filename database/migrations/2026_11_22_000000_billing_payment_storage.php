<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * - Unresolved-payment / failed-payment / invalid-refund evidence has no subscription to hang on.
     * - A buyer pays before a business exists, so a user can exist without a shop. Tenant routes stay
     *   fail-closed: UseShop refuses any user whose shop is missing.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE subscription_events ALTER COLUMN shop_subscription_id DROP NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN shop_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM subscription_events WHERE shop_subscription_id IS NULL');
        DB::statement('ALTER TABLE subscription_events ALTER COLUMN shop_subscription_id SET NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN shop_id SET NOT NULL');
    }
};
