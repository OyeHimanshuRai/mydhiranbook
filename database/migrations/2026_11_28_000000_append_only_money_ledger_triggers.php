<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Money ledger rows are immutable below the model guard too: a BEFORE UPDATE OR DELETE trigger refuses any change
 * (the ImmutableLedger model trait already refuses it in PHP; this catches raw SQL, tinker and future code).
 * Only the users FK's ON DELETE SET NULL on created_by is let through. Corrections are new reversing rows.
 * Not covered: dhiran_loans / dhiran_loan_items (status and accrual columns are legitimately updated).
 */
return new class extends Migration
{
    private const TABLES = ['dhiran_payments', 'dhiran_cash_entries', 'dhiran_ledger_entries'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION dhiran_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.created_by IS NULL AND (to_jsonb(NEW) - 'created_by') = (to_jsonb(OLD) - 'created_by') THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION '% is append-only: % refused', TG_TABLE_NAME, TG_OP USING ERRCODE = 'restrict_violation';
END $$;
SQL);
        foreach (self::TABLES as $t) {
            DB::unprepared("CREATE TRIGGER {$t}_append_only BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION dhiran_append_only()");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_append_only ON {$t}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS dhiran_append_only()');
    }
};
