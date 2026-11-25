<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A rejected shop may fix its details and send them again: how many times, when, and what the admin said last time. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $t) {
            $t->unsignedSmallInteger('submissions')->default(1);
            $t->timestamp('resubmitted_at')->nullable();
            $t->text('previous_review_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $t) => $t->dropColumn(['submissions', 'resubmitted_at', 'previous_review_note']));
    }
};
