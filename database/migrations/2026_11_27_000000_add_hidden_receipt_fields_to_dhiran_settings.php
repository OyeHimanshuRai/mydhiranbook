<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Receipt fields the shop owner switched off ("pledge.shop_phone", ...). Empty = today's full receipt.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('dhiran_settings', fn (Blueprint $t) => $t->jsonb('hidden_receipt_fields')->nullable());
    }
    public function down(): void
    {
        Schema::table('dhiran_settings', fn (Blueprint $t) => $t->dropColumn('hidden_receipt_fields'));
    }
};
