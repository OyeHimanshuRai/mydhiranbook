<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Paid-in-advance loans: how many monthly interest periods (from the loan day) are paid for. The next installment is due at
// loan date + this many months.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dhiran_loans', fn (Blueprint $t) => $t->unsignedSmallInteger('interest_periods_paid')->default(0));
        // Loans that already took an advance covered that many months.
        \Illuminate\Support\Facades\DB::table('dhiran_loans')->where('advance_interest_months', '>', 0)->update(['interest_periods_paid' => \Illuminate\Support\Facades\DB::raw('advance_interest_months')]);
    }

    public function down(): void
    {
        Schema::table('dhiran_loans', fn (Blueprint $t) => $t->dropColumn('interest_periods_paid'));
    }
};
