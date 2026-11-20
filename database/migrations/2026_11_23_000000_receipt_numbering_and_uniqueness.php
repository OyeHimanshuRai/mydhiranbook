<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - One receipt per subscription term: a replayed payment can never issue a second one.
     * - A buyer pays before a business exists, so a receipt may have no shop yet.
     * - Gap-free receipt numbers need a row-locked counter per prefix and year.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE platform_invoices ALTER COLUMN shop_id DROP NOT NULL');
        Schema::table('platform_invoices', fn (Blueprint $t) => $t->unique('shop_subscription_id'));
        Schema::create('platform_counters', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->unsignedBigInteger('value')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_counters');
        Schema::table('platform_invoices', fn (Blueprint $t) => $t->dropUnique(['shop_subscription_id']));
        DB::statement('DELETE FROM platform_invoices WHERE shop_id IS NULL');
        DB::statement('ALTER TABLE platform_invoices ALTER COLUMN shop_id SET NOT NULL');
    }
};
