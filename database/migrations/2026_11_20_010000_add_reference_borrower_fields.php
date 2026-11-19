<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('customers',function(Blueprint $t) { $t->string('pan',10)->nullable(); $t->string('id_number',30)->nullable(); $t->string('state_code',5)->nullable(); }); }
    public function down(): void { Schema::table('customers',fn(Blueprint $t)=>$t->dropColumn(['pan','id_number','state_code'])); }
};
