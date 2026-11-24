<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-shop notification settings: the daily popup on/off, which notices are off, the day windows. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dhiran_settings', function (Blueprint $t) {
            $t->boolean('notices_popup')->default(true);
            $t->json('notices_off')->nullable();
            $t->unsignedSmallInteger('notices_forfeit_days')->default(30);
            $t->json('notices_interest_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dhiran_settings', fn (Blueprint $t) => $t->dropColumn(['notices_popup', 'notices_off', 'notices_forfeit_days', 'notices_interest_days']));
    }
};
