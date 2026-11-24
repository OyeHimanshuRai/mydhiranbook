<?php

namespace App\Services\Dhiran;

use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\LoanEvent;
use App\Support\IndianNumber;
use Illuminate\Support\Carbon;

/**
 * The one place a loan's activity log is written. Called from model hooks (payments, loans, items) and from the few places that
 * are not model writes (uploads, interest accrual, prints). It runs inside the same transaction as the change it records, so a
 * change can never be saved without its log line, and a failed log write rolls the change back.
 */
final class LoanLog
{
    /** Columns that move on every accrual or payment: told through the payment and accrual entries, never as an "edit". */
    private const NOISE = [
        'outstanding_principal', 'outstanding_interest', 'outstanding_penalty', 'interest_accrued_through', 'interest_remainder',
        'penalty_remainder', 'interest_capitalised', 'interest_prepaid', 'interest_periods_paid', 'total_interest_collected',
        'total_penalty_collected', 'total_principal_collected', 'closed_at', 'renewed_count', 'updated_at', 'forfeiture_notice_edit',
    ];

    /** Personal identifiers: the log says they changed, never what they were. */
    private const PRIVATE = ['kyc_pan', 'kyc_aadhaar', 'kyc_photo_path'];

    private const LABELS = [
        'status' => 'Status', 'notes' => 'Notes', 'terms_text' => 'Terms', 'tenure_months' => 'Tenure (months)', 'maturity_date' => 'Maturity date',
        'interest_rate_monthly' => 'Interest rate (%/month)', 'interest_type' => 'Interest type', 'penalty_rate_monthly' => 'Penalty rate (%/month)',
        'grace_period_days' => 'Grace period (days)', 'min_lock_months' => 'Lock period (months)', 'min_interest_months' => 'Minimum interest (months)',
        'closure_notes' => 'Closure notes', 'forfeiture_notice_sent_at' => 'Forfeiture notice sent', 'forfeiture_deadline' => 'Forfeiture deadline',
        'forfeited_at' => 'Forfeited on', 'kyc_pan' => 'PAN', 'kyc_aadhaar' => 'ID number', 'kyc_photo_path' => 'KYC photo',
        'processing_fee' => 'Processing fee', 'ltv_ratio_applied' => 'Loan-to-value applied', 'description' => 'Description', 'gross_weight' => 'Gross weight',
        'stone_weight' => 'Stone weight', 'net_metal_weight' => 'Net weight', 'purity' => 'Purity', 'metal_type' => 'Metal', 'huid' => 'HUID',
        'market_value' => 'Market value', 'loan_value' => 'Loan value', 'fine_weight' => 'Fine weight',
    ];

    private static function rupee(float|int|string|null $n): string
    {
        return '₹' . IndianNumber::format((float) $n, 2);
    }

    /** $actor overrides who did it ([id, name]); used only when old history is rebuilt. */
    public static function record(int $shopId, int $loanId, string $event, string $category, string $summary, array $details = [], ?Carbon $at = null, ?array $actor = null): LoanEvent
    {
        $user = auth()->user();
        return LoanEvent::create([
            'shop_id' => $shopId, 'dhiran_loan_id' => $loanId,
            'user_id' => $actor ? ($actor['id'] ?? null) : $user?->id, 'actor_name' => $actor['name'] ?? ($user ? ($user->name ?: ($user->mobile_number ?? 'User')) : 'System'),
            'event' => $event, 'category' => $category, 'summary' => mb_substr($summary, 0, 500),
            'details' => $details ?: null, 'ip' => app()->bound('request') && (! app()->runningInConsole() || app()->runningUnitTests()) ? request()->ip() : null, // none for the scheduler / artisan: there is no client
            'occurred_at' => $at ?? now(),
        ]);
    }

    public static function forLoan(DhiranLoan $loan, string $event, string $category, string $summary, array $details = [], ?array $actor = null): LoanEvent
    {
        return self::record((int) $loan->shop_id, (int) $loan->id, $event, $category, $summary, $details, null, $actor);
    }

    /** A payment row was written: every transaction (money in or out) is a line. */
    public static function payment(DhiranPayment $p, ?Carbon $at = null, ?array $actor = null, array $extra = []): void
    {
        $in = $p->direction === 'in';
        $amount = self::rupee($p->amount);
        $note = (string) $p->notes;
        $label = [
            'disbursement' => "Loan paid out {$amount}", 'processing_fee' => "Processing fee {$amount} collected",
            'advance_interest' => "Interest paid in advance {$amount} received", 'interest_payment' => "Interest payment {$amount} received",
            'renewal_interest' => "Renewal interest {$amount} received", 'pre_closure' => "Pre-closure payment {$amount} received",
            'cancellation' => "Payout {$amount} returned (loan cancelled)", 'fee_refund' => "Processing fee {$amount} refunded",
            'advance_refund' => "Advance interest {$amount} returned (loan cancelled)",
        ][$p->type] ?? null;
        if ($p->type === 'principal_repayment') {
            $label = str_starts_with($note, 'Item release payment:')
                ? "Payment {$amount} received to release " . trim(substr($note, strlen('Item release payment:')))
                : "Repayment {$amount} received";
        }
        $label ??= ucfirst(str_replace('_', ' ', (string) $p->type)) . " {$amount} " . ($in ? 'received' : 'paid out');

        self::record((int) $p->shop_id, (int) $p->dhiran_loan_id, 'payment.' . $p->type, 'money', $label, array_filter([
            'payment_id' => $p->id, 'type' => $p->type, 'direction' => $p->direction, 'amount' => (float) $p->amount,
            'method' => $p->payment_method, 'principal' => (float) $p->principal_component, 'interest' => (float) $p->interest_component,
            'penalty' => (float) $p->penalty_component, 'fee' => (float) $p->processing_fee_component,
            'principal_left' => (float) $p->outstanding_principal_after, 'interest_left' => (float) $p->outstanding_interest_after,
            'penalty_left' => (float) $p->outstanding_penalty_after, 'payment_date' => (string) $p->payment_date?->toDateString(), 'note' => $note ?: null,
        ], fn ($v) => $v !== null) + $extra, $at, $actor);
    }

    /** A loan row was created. */
    public static function loanCreated(DhiranLoan $loan): void
    {
        $renewed = $loan->renewed_from_id ? ' (renewal of an earlier loan)' : '';
        self::forLoan($loan, 'loan.created', 'loan', 'Loan ' . $loan->loan_number . ' created: ' . self::rupee($loan->principal_amount) . " at {$loan->interest_rate_monthly}% a month ({$loan->interest_type}), {$loan->tenure_months} months" . $renewed, array_filter([
            'loan_number' => $loan->loan_number, 'principal' => (float) $loan->principal_amount, 'interest_rate_monthly' => (float) $loan->interest_rate_monthly,
            'interest_type' => $loan->interest_type, 'tenure_months' => (int) $loan->tenure_months, 'loan_date' => (string) $loan->loan_date?->toDateString(),
            'maturity_date' => (string) $loan->maturity_date?->toDateString(), 'status' => $loan->status, 'renewed_from_id' => $loan->renewed_from_id,
            'advance_interest_months' => (int) $loan->advance_interest_months ?: null, 'brought_forward' => $loan->brought_forward ? true : null,
        ], fn ($v) => $v !== null));
    }

    /** A loan row was saved: a status change, or any business field changed (accrual and payment bookkeeping is left out). */
    public static function loanChanged(DhiranLoan $loan): void
    {
        $changes = array_diff_key($loan->getChanges(), array_flip(self::NOISE));
        if (! $changes) {
            return;
        }
        if (array_key_exists('status', $changes)) {
            $from = (string) $loan->getOriginal('status');
            $to = (string) $changes['status'];
            $words = ['active' => $from === 'pending_evidence' ? 'Loan activated' : 'Loan reopened', 'closed' => 'Loan closed', 'renewed' => 'Loan renewed (a new loan took over)',
                'forfeited' => 'Loan forfeited', 'cancelled' => 'Loan cancelled', 'pending_evidence' => 'Loan awaiting evidence'];
            self::forLoan($loan, 'loan.status', 'loan', ($words[$to] ?? "Status changed to {$to}") . " (was " . str_replace('_', ' ', $from) . ')', ['from' => $from, 'to' => $to]);
            unset($changes['status']);
        }
        if (! $changes) {
            return;
        }
        $lines = [];
        $details = [];
        foreach ($changes as $col => $new) {
            $label = self::LABELS[$col] ?? ucfirst(str_replace('_', ' ', $col));
            if (in_array($col, self::PRIVATE, true)) {
                $lines[] = "{$label} changed";
                $details[$label] = ['changed' => true];
                continue;
            }
            $old = $loan->getOriginal($col);
            $lines[] = "{$label}: " . self::show($old) . ' → ' . self::show($new);
            $details[$label] = ['from' => self::show($old), 'to' => self::show($new)];
        }
        self::forLoan($loan, 'loan.changed', 'loan', 'Loan details changed — ' . implode('; ', $lines), ['changes' => $details]);
    }

    /** A pledged item was added. */
    public static function itemAdded(DhiranLoanItem $i): void
    {
        self::record((int) $i->shop_id, (int) $i->dhiran_loan_id, 'item.added', 'item', 'Item pledged: ' . $i->description . ' (' . self::describeItem($i) . ')', self::itemDetails($i));
    }

    /** A pledged item changed: released or forfeited, or its details were edited. */
    public static function itemChanged(DhiranLoanItem $i): void
    {
        $changes = array_diff_key($i->getChanges(), array_flip(['updated_at', 'released_by', 'release_condition_note', 'released_at']));
        if (array_key_exists('status', $changes)) {
            $to = $changes['status'];
            $by = $i->releasedBy?->name;
            $text = $to === 'released'
                ? 'Item released to the borrower: ' . $i->description . ($i->release_condition_note ? " — condition: {$i->release_condition_note}" : '')
                : "Item {$to}: {$i->description}";
            self::record((int) $i->shop_id, (int) $i->dhiran_loan_id, 'item.' . $to, 'item', $text, self::itemDetails($i) + array_filter([
                'from' => $i->getOriginal('status'), 'to' => $to, 'condition_note' => $i->release_condition_note, 'released_by' => $by,
            ], fn ($v) => $v !== null && $v !== ''));
            unset($changes['status']);
        }
        if (! $changes) {
            return;
        }
        $lines = [];
        foreach ($changes as $col => $new) {
            $lines[self::LABELS[$col] ?? ucfirst(str_replace('_', ' ', $col))] = ['from' => self::show($i->getOriginal($col)), 'to' => self::show($new)];
        }
        self::record((int) $i->shop_id, (int) $i->dhiran_loan_id, 'item.changed', 'item', "Item details changed ({$i->description}) — " . implode('; ', array_map(fn ($k, $v) => "{$k}: {$v['from']} → {$v['to']}", array_keys($lines), $lines)), ['item_id' => $i->id, 'changes' => $lines]);
    }

    /** Several pledged items changed hands at once (loan closed, cancelled, forfeited or renewed): one line naming them. Call before the bulk update. */
    public static function bulkItems(DhiranLoan $loan, string $event, string $what, ?DhiranLoan $target = null): void
    {
        $items = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->where('status', 'pledged')->orderBy('id')->get();
        if ($items->isEmpty()) {
            return;
        }
        $names = $items->pluck('description')->implode(', ');
        $count = $items->count();
        $details = ['items' => $items->map(fn ($i) => ['id' => $i->id, 'description' => $i->description, 'gross_weight' => (float) $i->gross_weight, 'net_weight' => (float) $i->net_metal_weight])->all()];
        self::forLoan($loan, 'item.' . $event, 'item', "{$count} " . ($count === 1 ? 'item' : 'items') . " {$what}: {$names}", $details);
        if ($target) {
            self::forLoan($target, 'item.received', 'item', "{$count} " . ($count === 1 ? 'item' : 'items') . " received from loan {$loan->loan_number}: {$names}", $details);
        }
    }

    public static function itemDetails(DhiranLoanItem $i): array
    {
        return ['item_id' => $i->id, 'description' => $i->description, 'metal' => $i->metal_type, 'purity' => (float) $i->purity,
            'gross_weight' => (float) $i->gross_weight, 'net_weight' => (float) $i->net_metal_weight, 'fine_weight' => (float) $i->fine_weight, 'market_value' => (float) $i->market_value];
    }

    public static function describeItem(DhiranLoanItem $i): string
    {
        $metal = ucfirst($i->metal_type ?? 'gold');
        $purity = ($i->metal_type ?? 'gold') === 'other' ? '' : ' ' . rtrim(rtrim((string) $i->purity, '0'), '.') . (($i->metal_type ?? 'gold') === 'silver' ? '' : 'K');

        return "{$metal}{$purity}, gross " . number_format((float) $i->gross_weight, 3) . ' g, net ' . number_format((float) $i->net_metal_weight, 3) . ' g';
    }

    private static function show(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('d M Y');
        }
        $s = (string) $v;

        return mb_strlen($s) > 80 ? mb_substr($s, 0, 77) . '…' : $s;
    }
}
