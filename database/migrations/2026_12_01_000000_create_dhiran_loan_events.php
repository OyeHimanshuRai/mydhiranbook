<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The per-loan activity log: every transaction, release, upload, edit, print and system step on a loan, with who and when.
 * Append-only: a trigger refuses any UPDATE or DELETE (the log of a loan can never be rewritten). user_id is a plain number, not a
 * foreign key, so deleting a user never touches (or fails on) the log; actor_name keeps who they were at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhiran_loan_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('dhiran_loan_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('actor_name', 150);
            $t->string('event', 60);        // e.g. payment.interest_payment, item.released, document.uploaded
            $t->string('category', 12);     // money | item | document | loan | print | system
            $t->string('summary', 500);
            $t->jsonb('details')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestampTz('occurred_at');
            $t->timestampTz('created_at')->nullable();
            $t->index(['dhiran_loan_id', 'occurred_at']);
            $t->index(['shop_id', 'occurred_at']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION dhiran_loan_events_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'dhiran_loan_events is append-only: % refused', TG_OP USING ERRCODE = 'restrict_violation';
END $$;
SQL);
        DB::unprepared('CREATE TRIGGER dhiran_loan_events_append_only BEFORE UPDATE OR DELETE ON dhiran_loan_events FOR EACH ROW EXECUTE FUNCTION dhiran_loan_events_append_only()');

        // who may read a loan's log: the owner, and anyone the owner grants it to (not given to managers by default)
        $permission = \App\Models\Permission::firstOrCreate(['name' => 'dhiran.audit'], ['display_name' => 'View loan activity log', 'group' => 'dhiran']);
        \App\Models\Role::withoutTenant()->where('name', 'owner')->get()->each(fn ($role) => $role->permissions()->syncWithoutDetaching([$permission->id]));

        // loans that already exist get their history rebuilt from what was recorded
        Artisan::call('dhiran:backfill-loan-log');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS dhiran_loan_events_append_only ON dhiran_loan_events');
        DB::unprepared('DROP FUNCTION IF EXISTS dhiran_loan_events_append_only()');
        Schema::dropIfExists('dhiran_loan_events');
        \App\Models\Permission::where('name', 'dhiran.audit')->get()->each(function ($p) { DB::table('role_permission')->where('permission_id', $p->id)->delete(); $p->delete(); });
    }
};
