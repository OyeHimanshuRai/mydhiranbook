<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Referrals: every shop has a share code; a new shop remembers who told it (a shop, or the free text it typed). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $t) {
            $t->string('referral_code', 12)->nullable()->unique();
            $t->foreignId('referred_by_shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $t->string('referred_by_text', 80)->nullable();
        });
        // same rule as Shop::referralCodeFor(): 'MDB' + base36 of the id, 5 wide, upper case
        DB::table('shops')->whereNull('referral_code')->orderBy('id')->each(
            fn ($s) => DB::table('shops')->where('id', $s->id)->update(['referral_code' => 'MDB'.strtoupper(str_pad(base_convert((string) $s->id, 10, 36), 5, '0', STR_PAD_LEFT))])
        );
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $t) {
            $t->dropConstrainedForeignId('referred_by_shop_id');
            $t->dropColumn(['referral_code', 'referred_by_text']);
        });
    }
};
