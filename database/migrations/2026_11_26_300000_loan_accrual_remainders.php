<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sub-paisa remainder carried between accruals, so total interest/penalty doesn't depend on how often accrual runs.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dhiran_loans', function (Blueprint $t) {
            $t->decimal('interest_remainder', 12, 6)->default(0);
            $t->decimal('penalty_remainder', 12, 6)->default(0);
        });
    }
    public function down(): void
    {
        Schema::table('dhiran_loans', fn (Blueprint $t) => $t->dropColumn(['interest_remainder', 'penalty_remainder']));
    }
};
