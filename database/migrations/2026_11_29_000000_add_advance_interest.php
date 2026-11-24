<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Advance interest: the first N months' interest taken at the counter. The shop switches it on (off by default); the loan
// records how many months were taken and how much of the advance is still unused (spent day by day by accrueInterest).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dhiran_loans', function (Blueprint $t) {
            $t->unsignedSmallInteger('advance_interest_months')->default(0);
            $t->decimal('interest_prepaid', 15, 2)->default(0);
        });
        Schema::table('dhiran_settings', function (Blueprint $t) {
            $t->boolean('advance_interest_enabled')->default(false);
            $t->unsignedSmallInteger('advance_interest_default_months')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('dhiran_loans', fn (Blueprint $t) => $t->dropColumn(['advance_interest_months', 'interest_prepaid']));
        Schema::table('dhiran_settings', fn (Blueprint $t) => $t->dropColumn(['advance_interest_enabled', 'advance_interest_default_months']));
    }
};
