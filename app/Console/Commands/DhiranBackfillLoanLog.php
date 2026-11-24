<?php

namespace App\Console\Commands;

use App\Models\Dhiran\DhiranAttachment;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\LoanEvent;
use App\Services\Dhiran\LoanLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Rebuild the activity log of loans that existed before the log did, from what was recorded (payments, items, uploads, closures). */
class DhiranBackfillLoanLog extends Command
{
    protected $signature = 'dhiran:backfill-loan-log {--force : also rebuild loans that already have log lines}';
    protected $description = "Write the activity log of earlier loans from their recorded payments, items and uploads";

    public function handle(): int
    {
        $count = 0;
        DhiranLoan::withoutGlobalScopes()->orderBy('id')->chunkById(100, function ($loans) use (&$count) {
            foreach ($loans as $loan) {
                if (! $this->option('force') && LoanEvent::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->exists()) {
                    continue;
                }
                $this->backfill($loan);
                $count++;
            }
        });
        $this->info("Rebuilt the activity log of {$count} loan(s).");

        return self::SUCCESS;
    }

    private function backfill(DhiranLoan $loan): void
    {
        $earlier = ['id' => null, 'name' => 'Earlier record'];
        $mark = fn (array $d = []) => $d + ['rebuilt_from_earlier_records' => true];
        $shop = (int) $loan->shop_id;
        $id = (int) $loan->id;
        $at = fn ($t) => $t ? Carbon::parse($t) : now();

        LoanLog::record($shop, $id, 'loan.created', 'loan', "Loan {$loan->loan_number} created: ₹" . \App\Support\IndianNumber::format((float) $loan->principal_amount, 2) . " at {$loan->interest_rate_monthly}% a month ({$loan->interest_type}), {$loan->tenure_months} months",
            $mark(['loan_number' => $loan->loan_number, 'principal' => (float) $loan->principal_amount, 'loan_date' => (string) $loan->loan_date?->toDateString()]), $at($loan->created_at), $earlier);

        $items = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $id)->orderBy('id')->get();
        foreach ($items as $i) {
            LoanLog::record($shop, $id, 'item.added', 'item', 'Item pledged: ' . $i->description . ' (' . LoanLog::describeItem($i) . ')', $mark(LoanLog::itemDetails($i)), $at($i->created_at), $earlier);
            if ($i->status === 'released' && $i->released_at) {
                LoanLog::record($shop, $id, 'item.released', 'item', 'Item released to the borrower: ' . $i->description . ($i->release_condition_note ? " — condition: {$i->release_condition_note}" : ''), $mark(LoanLog::itemDetails($i)), $at($i->released_at), $earlier);
            }
        }
        DhiranPayment::withoutGlobalScopes()->where('dhiran_loan_id', $id)->orderBy('id')->each(fn ($p) => LoanLog::payment($p, $at($p->created_at), $earlier, $mark()));

        $itemIds = $items->pluck('id')->all();
        DhiranAttachment::withoutGlobalScopes()->where(fn ($q) => $q->where(fn ($w) => $w->where('owner_type', DhiranAttachment::OWNER_LOAN)->where('owner_id', $id))
            ->orWhere(fn ($w) => $w->where('owner_type', DhiranAttachment::OWNER_ITEM)->whereIn('owner_id', $itemIds ?: [0])))
            ->orderBy('id')->each(fn ($a) => LoanLog::record($shop, $id, 'document.uploaded', 'document', 'Uploaded ' . ucfirst(str_replace('_', ' ', $a->document_type)) . ': ' . mb_substr((string) $a->original_name, 0, 80),
                $mark(['attachment_id' => $a->id, 'document_type' => $a->document_type, 'file' => $a->original_name]), $at($a->created_at), ['id' => $a->uploaded_by, 'name' => $a->uploaded_by ? 'Earlier record' : 'Earlier record']));

        $ended = ['closed' => ['Loan closed', $loan->closed_at], 'renewed' => ['Loan renewed (a new loan took over)', $loan->closed_at], 'cancelled' => ['Loan cancelled', $loan->closed_at], 'forfeited' => ['Loan forfeited', $loan->forfeited_at ?? $loan->closed_at]][$loan->status] ?? null;
        if ($ended && $ended[1]) {
            LoanLog::record($shop, $id, 'loan.status', 'loan', $ended[0], $mark(['to' => $loan->status]), $at($ended[1]), $earlier);
        }
    }
}
