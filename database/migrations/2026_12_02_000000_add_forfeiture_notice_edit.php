<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The owner's own wording of a loan's forfeiture notice ({body, warning, by, at}); null = print the system-generated text.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dhiran_loans', fn (Blueprint $t) => $t->jsonb('forfeiture_notice_edit')->nullable());
    }

    public function down(): void
    {
        Schema::table('dhiran_loans', fn (Blueprint $t) => $t->dropColumn('forfeiture_notice_edit'));
    }
};
