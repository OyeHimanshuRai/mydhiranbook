<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How many digits a new loan number is padded to (6 = DH-000001, 1 = DH-1). Numbers already issued never change.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dhiran_settings', fn (Blueprint $t) => $t->unsignedSmallInteger('loan_number_digits')->default(6));
    }

    public function down(): void
    {
        Schema::table('dhiran_settings', fn (Blueprint $t) => $t->dropColumn('loan_number_digits'));
    }
};
