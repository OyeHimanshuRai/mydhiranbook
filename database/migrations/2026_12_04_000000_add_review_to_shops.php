<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A new shop waits for an admin (shops.status = pending); the decision's time and note are kept (rejected shows the note). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $t) {
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn(['reviewed_at', 'review_note']));
    }
};
