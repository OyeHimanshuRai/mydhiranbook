<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loan-log times (occurred_at and created_at) written before 2026-11-26 were the app's wall time (Asia/Kolkata) sent without an offset, so Postgres stored
 * them as if they were in its own session zone. Re-read each one as that wall time in the app zone. Where the database already
 * runs in the app zone this changes nothing. The log is append-only: the trigger is switched off for this one correction only,
 * inside the migration's transaction (ALTER TABLE locks the table, so no other write can slip through; a failure rolls the trigger
 * state back too), and switched back on. Every row present is an old-style row: deploys run it in maintenance mode, where the
 * scheduler and queue workers write nothing. current_setting('TimeZone') is the same session zone the rows were written in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite("(occurred_at at time zone current_setting('TimeZone')) at time zone ?");
    }

    // Irreversible on purpose: shifting back would corrupt rows written after the fix, and a silent no-op would let a
    // rollback + migrate run up() twice (a double shift). Refusing the rollback keeps up() to exactly one run per database.
    public function down(): void
    {
        throw new \RuntimeException('2026_12_08_000000_fix_loan_event_times cannot be rolled back: the loan-log time correction runs once per database.');
    }

    private function rewrite(string $expression): void
    {
        DB::transaction(function () use ($expression) {
            DB::statement('alter table dhiran_loan_events disable trigger dhiran_loan_events_append_only');
            $fix = fn (string $col) => str_replace('occurred_at', $col, $expression);
            DB::update("update dhiran_loan_events set occurred_at = {$fix('occurred_at')}, created_at = {$fix('created_at')}", [config('app.timezone'), config('app.timezone')]);
            DB::statement('alter table dhiran_loan_events enable trigger dhiran_loan_events_append_only');
        });
    }
};
