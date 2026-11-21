<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lookups by event/payment id run on every webhook, callback and receipt; `after` is jsonb.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_events', fn (Blueprint $t) => $t->index('shop_subscription_id', 'subscription_events_shop_subscription_id_index'));
        DB::statement("CREATE INDEX IF NOT EXISTS subscription_events_after_payment_id_index ON subscription_events ((after->>'payment_id'))");
        DB::statement("CREATE INDEX IF NOT EXISTS subscription_events_after_event_id_index ON subscription_events ((after->>'event_id'))");
        Schema::table('shop_subscriptions', fn (Blueprint $t) => $t->index('user_id', 'shop_subscriptions_user_id_index'));
    }

    public function down(): void
    {
        Schema::table('shop_subscriptions', fn (Blueprint $t) => $t->dropIndex('shop_subscriptions_user_id_index'));
        DB::statement('DROP INDEX IF EXISTS subscription_events_after_event_id_index');
        DB::statement('DROP INDEX IF EXISTS subscription_events_after_payment_id_index');
        Schema::table('subscription_events', fn (Blueprint $t) => $t->dropIndex('subscription_events_shop_subscription_id_index'));
    }
};
