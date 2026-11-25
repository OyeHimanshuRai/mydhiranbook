<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Dhiran\DhiranCashEntry;
use App\Models\Dhiran\DhiranLedgerEntry;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class DhiranService
{
    /* ══════════════════════════════════════════════════════════
     *  1. CREATE LOAN
     * ══════════════════════════════════════════════════════════ */

    /**
     * Create a new gold-pledge loan with items, disbursement, and optional processing fee.
     *
     * @param  array  $items   Each item: description, category?, metal_type?, quantity?, gross_weight, stone_weight?, purity, rate_per_gram_at_pledge, photo_path?, huid?
     * @param  array  $params  principal_amount, loan_date?, gold_rate_on_date, silver_rate_on_date?, interest_rate_monthly?, interest_type?, penalty_rate_monthly?, tenure_months?, min_lock_months?, grace_period_days?, min_interest_months?, processing_fee?, processing_fee_type?, kyc_aadhaar?, kyc_pan?, kyc_photo_path?, terms_text?, notes?, payment_method?, created_by?
     */
    /**
     * Who is recording a money movement: the signed-in user. The loan's creator only when there is none (console and
     * scheduled runs). Owner 2026-11-12: payments used to carry the loan's creator, so the cash book named the wrong person.
     */
    private function actor(DhiranLoan $loan): ?int
    {
        return auth()->id() ?? $loan->created_by;
    }

    /** Constitution Article I: no Dhiran money row may land in the shop's locked accounting period. */
    /**
     * The one gate for acting on a loan (owner 2026-11-12, state-machine audit): lock the row, then decide on the loan
     * as it is now. The status check callers make before the lock reads the copy their page loaded; another person may
     * have closed, renewed or forfeited it since.
     */
    private function lockActive(DhiranLoan $loan, string $state = 'active'): DhiranLoan
    {
        $locked = DhiranLoan::lockForUpdate()->findOrFail($loan->id);
        if ($locked->status !== $state) {
            throw new LogicException(self::notActive($locked->status));
        }
        $this->assertOpen((int) $locked->shop_id, today());

        return $locked;
    }

    /** The early check every action makes on the loan it was given (lockActive repeats it on the locked row). */
    private function assertActive(DhiranLoan $loan): void
    {
        if ($loan->status !== 'active') {
            throw new LogicException(self::notActive($loan->status));
        }
    }

    /** One wording for "this loan cannot take that action in its state", wherever the check happens. */
    private static function notActive(string $status): string
    {
        return $status === 'pending_evidence'
            ? 'This loan is awaiting evidence. Upload the item photo and ID proof, then activate it.'
            : "This loan is already {$status}. Reload the page to see it as it is now.";
    }

    /** Money moves in whole paise: refuse sub-paisa and fractional-paisa amounts instead of booking a rounded value. */
    private function assertPaise(float $amount): void
    {
        if ($amount <= 0) {
            throw new LogicException('Payment amount must be greater than zero.');
        }
        if ($amount < 0.01 || abs($amount * 100 - round($amount * 100)) > 1e-6) {
            throw new LogicException('Amount must be at least 0.01 and in whole paise (two decimals at most).');
        }
    }

    /** Paying a loan off by any path is a settlement: the pre-close rules (lock period, minimum interest) apply. */
    private function assertMaySettle(DhiranLoan $loan): void
    {
        if (! $loan->canPreClose()) {
            throw new LogicException('This payment would settle the loan during its lock period. Settlement is allowed once the lock period ends.');
        }
        if ((float) $loan->total_interest_collected + (float) $loan->outstanding_interest < $loan->minimumInterestAmount() - 0.005) {
            throw new LogicException('This payment would settle the loan before its minimum interest is earned. Use Pre-close, which charges the minimum interest.');
        }
    }

    /** Closing collects at least min_interest_months of interest: book any shortfall as interest owed (pre-close and last-item release). */
    private function applyMinimumInterest(DhiranLoan $loan): void
    {
        $minimumInterest = $loan->minimumInterestAmount();
        $totalInterestCollected = (float) $loan->total_interest_collected + (float) $loan->outstanding_interest;

        if ($totalInterestCollected < $minimumInterest) {
            $shortfall = round($minimumInterest - $totalInterestCollected, 2);
            $loan->outstanding_interest = round((float) $loan->outstanding_interest + $shortfall, 2);
            $loan->save();

            // Ledger entry for minimum interest adjustment
            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'entry_type'              => 'interest_accrual',
                'direction'               => 'debit',
                'amount'                  => $shortfall,
                'balance_after'           => (float) $loan->outstanding_principal,
                'interest_balance_after'  => (float) $loan->outstanding_interest,
                'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                'note'                    => "Minimum interest enforcement: shortfall {$shortfall}",
                'meta'                    => json_encode([
                    'min_interest_months' => $loan->min_interest_months,
                    'minimum_amount'      => $minimumInterest,
                    'shortfall'           => $shortfall,
                ]),
            ]);
        }
    }

    private function assertOpen(int $shopId, mixed $date): void
    {
        \App\Services\InvoiceAccountingService::assertShopLockForDate($shopId, Carbon::parse($date)->toDateString());
    }

    public function createLoan(Shop $shop, Customer $customer, array $items, array $params): DhiranLoan
    {
        return DB::transaction(function () use ($shop, $customer, $items, $params): DhiranLoan {

            // ── Validate shop ownership ──────────────────────
            if ((int) $customer->shop_id !== (int) $shop->id) {
                throw new LogicException('Customer does not belong to this shop.');
            }

            // MASTERS PART 3: a loan is a new commitment — authoritative archive
            // check inside this transaction (validation alone is a TOCTOU hole).
            Customer::lockActiveOrFail((int) $shop->id, (int) $customer->id, 'customer_id');

            if (empty($items)) {
                throw new LogicException('At least one collateral item is required.');
            }

            // Plan item cap (also covers old-loan import, which comes through here). Shop row lock serialises concurrent creates.
            Shop::whereKey($shop->id)->lockForUpdate()->value('id');
            $limit = $shop->itemLimit();
            if ($limit !== -1 && $shop->pledgedItemCount() + count($items) > $limit) {
                throw new LogicException("Item limit reached: your access allows {$limit} pledged items and this loan would exceed it. Close or release items, or contact us to raise this limit.");
            }

            $settings = DhiranSettings::getForShop($shop->id);

            // ── Resolve parameters with settings defaults ────
            $principalAmount      = (float) $params['principal_amount'];
            $interestRateMonthly  = (float) ($params['interest_rate_monthly'] ?? $settings->default_interest_rate_monthly);
            $interestType         = $params['interest_type'] ?? $settings->default_interest_type ?? 'flat';
            $penaltyRateMonthly   = (float) ($params['penalty_rate_monthly'] ?? $settings->default_penalty_rate_monthly);
            $tenureMonths         = (int) ($params['tenure_months'] ?? $settings->default_tenure_months);
            if ($tenureMonths < 1 || $interestRateMonthly < 0) { // the service guards its own terms, not only the form
                throw new LogicException('Tenure must be at least 1 month and the interest rate cannot be negative.');
            }
            $minLockMonths        = (int) ($params['min_lock_months'] ?? $settings->default_min_lock_months);
            $gracePeriodDays      = (int) ($params['grace_period_days'] ?? $settings->grace_period_days);
            $minInterestMonths    = (int) ($params['min_interest_months'] ?? $settings->default_min_interest_months);
            $loanDate             = Carbon::parse($params['loan_date'] ?? today());
            // Brought forward (owner 2026-11-12): a paper loan entered as it stands today. Its money moved before the
            // shop used the app, so nothing lands on its old date: no payout, no fee, one opening ledger line today.
            $broughtForward       = ! empty($params['brought_forward']);
            if ($broughtForward) {
                $minLockMonths = 0;     // the paper loan's minimum period and minimum interest were settled on paper
                $minInterestMonths = 0;
            }
            // a back-dated loan must not land in a closed period; a brought-forward one books only today
            $this->assertOpen((int) $shop->id, $broughtForward ? today() : $loanDate);
            $goldRateOnDate       = (float) $params['gold_rate_on_date'];
            $silverRateOnDate     = isset($params['silver_rate_on_date']) ? (float) $params['silver_rate_on_date'] : null;
            $paymentMethod        = $params['payment_method'] ?? 'cash';
            $createdBy            = $params['created_by'] ?? null;

            // ── Compute per-item values ──────────────────────
            $computedItems    = [];
            $totalFineWeight  = 0.0;
            $totalCollateral  = 0.0;

            foreach ($items as $item) {
                $grossWeight     = (float) ($item['gross_weight'] ?? 0);
                $stoneWeight     = (float) ($item['stone_weight'] ?? 0);
                $ratePerGram     = (float) ($item['rate_per_gram_at_pledge'] ?? 0);
                $pledgeMetal     = (string) ($item['metal_type'] ?? 'gold');

                // Valuation mode:
                //  - 'appraised' : operator enters the market value directly (any
                //    item, and mandatory for 'other' collateral — diamonds,
                //    platinum, watches — which has no purity/fine-weight).
                //  - 'metal'     : gold/silver valued from fine weight × rate.
                $isOther    = $pledgeMetal === 'other';
                $valueMode  = $isOther ? 'appraised' : (string) ($item['value_mode'] ?? 'metal');

                $netMetalWeight = max(0.0, $grossWeight - $stoneWeight);

                if ($valueMode === 'appraised') {
                    // The typed market value is authoritative, but gold/silver still get their fine weight (net x purity); 'other' has none.
                    $purity      = $isOther ? 0.0 : (float) ($item['purity'] ?? 0);
                    $fineWeight  = $isOther ? 0.0 : $netMetalWeight * (\App\Services\MetalRegistry::fineWeightMultiplier($pledgeMetal, $purity) ?? 0.0);
                    $marketValue = round((float) ($item['market_value'] ?? 0), 2);
                    if ($marketValue <= 0) {
                        throw new \LogicException('An appraised market value is required for manually-valued collateral.');
                    }
                } else {
                    // Metal-melt valuation for gold/silver via the single authority.
                    $purity          = (float) $item['purity'];
                    $fineMultiplier  = \App\Services\MetalRegistry::fineWeightMultiplier($pledgeMetal, $purity);
                    if ($fineMultiplier === null) {
                        throw new \LogicException("Cannot derive fine weight for '{$pledgeMetal}' at purity {$purity}: purity must be above 0 and at most 24 karat (gold) or 1000 fineness (silver).");
                    }
                    $fineWeight  = $netMetalWeight * $fineMultiplier;
                    $marketValue = round($fineWeight * $ratePerGram, 2);
                }

                $totalFineWeight += $fineWeight;
                $totalCollateral += $marketValue;

                $computedItems[] = array_merge($item, [
                    'metal_type'             => $pledgeMetal,
                    'purity'                 => $purity,
                    'net_metal_weight'       => round($netMetalWeight, 6),
                    'fine_weight'            => round($fineWeight, 6),
                    'market_value'           => $marketValue,
                    // loan_value will be set after LTV is determined
                ]);
            }

            // ── Tiered LTV from settings ─────────────────────
            if ($principalAmount >= (float) $settings->high_value_threshold) {
                $ltvRatio = (float) $settings->high_value_ltv_ratio;
            } else {
                $ltvRatio = (float) $settings->default_ltv_ratio;
            }

            // Allow override but cap at settings value
            if (isset($params['ltv_ratio_applied'])) {
                $ltvRatio = min((float) $params['ltv_ratio_applied'], $ltvRatio);
            }

            // ── Apply LTV to each item ───────────────────────
            $totalLoanValue = 0.0;
            foreach ($computedItems as &$ci) {
                $ci['loan_value'] = round($ci['market_value'] * $ltvRatio / 100, 2);
                $totalLoanValue  += $ci['loan_value'];
            }
            unset($ci);

            // ── Validate principal against collateral ────────
            // (today's lending limits judge new lending, not a loan made on paper long ago)
            if (! $broughtForward && $principalAmount > $totalLoanValue) {
                throw new LogicException(
                    "Principal amount ({$principalAmount}) exceeds maximum loan value ({$totalLoanValue}) based on LTV ratio ({$ltvRatio}%)."
                );
            }

            // ── Validate min/max loan amount ─────────────────
            $minLoan = (float) $settings->min_loan_amount;
            $maxLoan = (float) $settings->max_loan_amount;

            if (! $broughtForward && $principalAmount < $minLoan) {
                throw new LogicException("Principal amount ({$principalAmount}) is below minimum loan amount ({$minLoan}).");
            }
            if (! $broughtForward && $principalAmount > $maxLoan) {
                throw new LogicException("Principal amount ({$principalAmount}) exceeds maximum loan amount ({$maxLoan}).");
            }

            // ── Processing fee ───────────────────────────────
            $processingFeeType = $params['processing_fee_type'] ?? $settings->processing_fee_type ?? 'flat';
            if ($broughtForward) {
                $processingFee = 0.0;
            } elseif (isset($params['processing_fee'])) {
                $processingFee = (float) $params['processing_fee'];
            } else {
                $feeValue = (float) ($settings->processing_fee_value ?? 0);
                if ($processingFeeType === 'percent') {
                    $processingFee = round($principalAmount * $feeValue / 100, 2);
                } else {
                    $processingFee = $feeValue;
                }
            }

            // ── Advance interest (the first N months taken at the counter) ──
            // Never for an old loan brought in from the paper book. The shop must have switched it on; 1..3 months.
            $advanceMonths = $broughtForward ? 0 : (int) ($params['advance_interest_months'] ?? 0);
            $advanceInterest = 0.0;
            if ($advanceMonths !== 0) {
                if (! $settings->advance_interest_enabled) {
                    throw new LogicException('Advance interest is not switched on for this shop.');
                }
                if ($advanceMonths < 1 || $advanceMonths > 3) {
                    throw new LogicException('Advance interest can cover 1 to 3 months.');
                }
                if ($advanceMonths > $tenureMonths) {
                    throw new LogicException('Advance interest cannot cover more months than the loan runs.');
                }
                // The first N months, each at its real number of days (rate / 30 per day): Nov 24 to Dec 24 is 30 days, Dec 24 to Jan 24 is 31.
                $advanceInterest = round($this->coverInterest($interestRateMonthly, $loanDate->copy(), $loanDate->copy()->addMonthsNoOverflow($advanceMonths), $principalAmount), 2);
                if ($advanceInterest <= 0 || $advanceInterest >= $principalAmount) {
                    throw new LogicException('Advance interest must be more than zero and less than the loan amount.');
                }
            }

            // ── Generate loan number ─────────────────────────
            $loanNumber = $broughtForward ? (string) $params['loan_number'] : $this->generateLoanNumber($shop);

            // ── Opening position (brought forward) ──────────
            // Principal still owed and interest from the paid-up date; with the shop's own unpaid-interest figure the
            // app takes that and adds interest only from today.
            $owed = $broughtForward ? round((float) $params['principal_owed'], 2) : $principalAmount;
            $shopsInterest = $broughtForward && ($params['unpaid_interest'] ?? null) !== null ? round((float) $params['unpaid_interest'], 2) : null;
            $accruedThrough = match (true) {
                ! $broughtForward      => $loanDate->toDateString(),
                $shopsInterest !== null => today()->toDateString(),
                default                => Carbon::parse($params['interest_paid_until'])->toDateString(),
            };

            // ── Create loan record ───────────────────────────
            $maturityDate = $loanDate->copy()->addMonthsNoOverflow($tenureMonths);

            $loan = DhiranLoan::create([
                'shop_id'                  => $shop->id,
                'customer_id'              => $customer->id,
                'loan_number'              => $loanNumber,
                'loan_date'                => $loanDate->toDateString(),
                'gold_rate_on_date'        => $goldRateOnDate,
                'silver_rate_on_date'      => $silverRateOnDate,
                'principal_amount'         => $principalAmount,
                'processing_fee'           => $processingFee,
                'processing_fee_type'      => $processingFeeType,
                'interest_rate_monthly'    => $interestRateMonthly,
                'interest_type'            => $interestType,
                'penalty_rate_monthly'     => $penaltyRateMonthly,
                'ltv_ratio_applied'        => $ltvRatio,
                'total_collateral_value'   => round($totalCollateral, 2),
                'total_fine_weight'        => round($totalFineWeight, 6),
                'outstanding_principal'    => $owed,
                'outstanding_interest'     => $shopsInterest ?? 0,
                'outstanding_penalty'      => 0,
                'interest_accrued_through' => $accruedThrough,
                'total_interest_collected' => $advanceInterest,
                'advance_interest_months'  => $advanceMonths,
                'interest_periods_paid'    => $advanceMonths,
                'interest_prepaid'         => $advanceInterest,
                'total_penalty_collected'  => 0,
                'total_principal_collected' => round($principalAmount - $owed, 2),
                'brought_forward'          => $broughtForward,
                'tenure_months'            => $tenureMonths,
                'maturity_date'            => $maturityDate->toDateString(),
                'min_lock_months'          => $minLockMonths,
                'grace_period_days'        => $gracePeriodDays,
                'min_interest_months'      => $minInterestMonths,
                // Status defaults to 'active' (internal/renewal callers keep their
                // behaviour). The customer-facing create flow passes
                // 'pending_evidence' so a loan cannot operate until the required
                // photo + ID proof are uploaded and the owner activates it.
                'status'                   => $params['status'] ?? 'active',
                'renewed_count'            => 0,
                'renewed_from_id'          => $params['renewed_from_id'] ?? null,
                'kyc_aadhaar'              => $params['kyc_aadhaar'] ?? null,
                'kyc_pan'                  => $params['kyc_pan'] ?? null,
                'kyc_photo_path'           => $params['kyc_photo_path'] ?? null,
                'terms_text'               => $params['terms_text'] ?? $settings->receipt_terms_text ?? null,
                'notes'                    => $params['notes'] ?? null,
                'created_by'               => $createdBy,
            ]);

            // ── Create loan items ────────────────────────────
            foreach ($computedItems as $ci) {
                DhiranLoanItem::create([
                    'shop_id'                => $shop->id,
                    'dhiran_loan_id'         => $loan->id,
                    'description'            => $ci['description'],
                    'category'               => $ci['category'] ?? null,
                    'metal_type'             => $ci['metal_type'] ?? 'gold',
                    'quantity'               => (int) ($ci['quantity'] ?? 1),
                    'gross_weight'           => $ci['gross_weight'] ?? 0,
                    'stone_weight'           => $ci['stone_weight'] ?? 0,
                    'net_metal_weight'       => $ci['net_metal_weight'],
                    'purity'                 => $ci['purity'] ?? 0,
                    'fine_weight'            => $ci['fine_weight'],
                    'rate_per_gram_at_pledge' => $ci['rate_per_gram_at_pledge'] ?? 0,
                    'market_value'           => $ci['market_value'],
                    'loan_value'             => $ci['loan_value'],
                    'photo_path'             => $ci['photo_path'] ?? null,
                    'huid'                   => $ci['huid'] ?? null,
                    'status'                 => 'pledged',
                ]);
            }

            if ($broughtForward) {
                DhiranLedgerEntry::record([
                    'shop_id'                => $shop->id,
                    'dhiran_loan_id'         => $loan->id,
                    'entry_type'             => 'brought_forward',
                    'direction'              => 'debit',
                    'amount'                 => $owed,
                    'balance_after'          => $owed,
                    'interest_balance_after' => $shopsInterest ?? 0,
                    'penalty_balance_after'  => 0,
                    'note'                   => "Brought forward from the paper book: {$loanNumber} (lent {$principalAmount} on {$loanDate->format('d M Y')})",
                    'created_by'             => $createdBy,
                ]);

                return $loan->fresh(['items', 'payments']);
            }

            // ── Disbursement payment (cash out) ──────────────
            $disbursement = DhiranPayment::record([
                'shop_id'                    => $shop->id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => $loanDate->toDateString(),
                'type'                       => 'disbursement',
                'amount'                     => $principalAmount,
                'direction'                  => 'out',
                'payment_method'             => $paymentMethod,
                'interest_component'         => 0,
                'penalty_component'          => 0,
                'principal_component'        => $principalAmount,
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => $principalAmount,
                'outstanding_interest_after' => 0,
                'outstanding_penalty_after'  => 0,
                'notes'                      => "Loan disbursed: {$loanNumber}",
                'created_by'                 => $createdBy,
            ]);

            DhiranCashEntry::record([
                'shop_id'            => $shop->id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $disbursement->id,
                'entry_date'         => $loanDate->toDateString(),
                'type'               => 'out',
                'amount'             => $principalAmount,
                'source_type'        => 'disbursement',
                'payment_method'     => $paymentMethod,
                'description'        => "Loan disbursed: {$loanNumber}",
                'created_by'         => $createdBy,
            ]);

            DhiranLedgerEntry::record([
                'shop_id'                 => $shop->id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $disbursement->id,
                'entry_type'              => 'disbursement',
                'direction'               => 'debit',
                'amount'                  => $principalAmount,
                'balance_after'           => $principalAmount,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Loan disbursed: {$loanNumber}",
                'created_by'              => $createdBy,
            ]);

            // ── Processing fee payment (cash in) if > 0 ─────
            if ($processingFee > 0) {
                $feePayment = DhiranPayment::record([
                    'shop_id'                    => $shop->id,
                    'dhiran_loan_id'             => $loan->id,
                    'payment_date'               => $loanDate->toDateString(),
                    'type'                       => 'processing_fee',
                    'amount'                     => $processingFee,
                    'direction'                  => 'in',
                    'payment_method'             => $paymentMethod,
                    'interest_component'         => 0,
                    'penalty_component'          => 0,
                    'principal_component'        => 0,
                    'processing_fee_component'   => $processingFee,
                    'outstanding_principal_after' => $principalAmount,
                    'outstanding_interest_after' => 0,
                    'outstanding_penalty_after'  => 0,
                    'notes'                      => "Processing fee: {$loanNumber}",
                    'created_by'                 => $createdBy,
                ]);

                DhiranCashEntry::record([
                    'shop_id'            => $shop->id,
                    'dhiran_loan_id'     => $loan->id,
                    'dhiran_payment_id'  => $feePayment->id,
                    'entry_date'         => $loanDate->toDateString(),
                    'type'               => 'in',
                    'amount'             => $processingFee,
                    'source_type'        => 'processing_fee',
                    'payment_method'     => $paymentMethod,
                    'description'        => "Processing fee: {$loanNumber}",
                    'created_by'         => $createdBy,
                ]);

                DhiranLedgerEntry::record([
                    'shop_id'                 => $shop->id,
                    'dhiran_loan_id'          => $loan->id,
                    'dhiran_payment_id'       => $feePayment->id,
                    'entry_type'              => 'processing_fee',
                    'direction'               => 'credit',
                    'amount'                  => $processingFee,
                    'balance_after'           => $principalAmount,
                    'interest_balance_after'  => 0,
                    'penalty_balance_after'   => 0,
                    'note'                    => "Processing fee collected: {$loanNumber}",
                    'created_by'              => $createdBy,
                ]);
            }

            // ── Advance interest (cash in): the customer is handed the loan less this ──
            if ($advanceInterest > 0) {
                $this->bookAdvanceInterest($loan, $advanceInterest, $paymentMethod, $createdBy, $loanDate->toDateString(), 0.0,
                    "Advance interest for {$advanceMonths} month(s): {$loanNumber}");
            }

            return $loan->fresh(['items', 'payments']);
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  1b. EVIDENCE GATE (pending_evidence → active)
     * ══════════════════════════════════════════════════════════ */

    /**
     * Whether a loan has the minimum evidence required to activate:
     *   - at least one pledged-item photo (owned by the loan or any of its items), AND
     *   - at least one borrower ID proof (id_proof_front/back, owned by the loan or
     *     its customer).
     * Returns ['item_photo' => bool, 'id_proof' => bool, 'ok' => bool].
     */
    public function evidenceStatus(DhiranLoan $loan): array
    {
        $itemIds = $loan->items->isNotEmpty()
            ? $loan->items->pluck('id')->all()
            : DhiranLoanItem::where('dhiran_loan_id', $loan->id)->pluck('id')->all();

        $hasItemPhoto = \App\Models\Dhiran\DhiranAttachment::query()
            ->where('document_type', 'item_photo')
            ->where(function ($q) use ($loan, $itemIds) {
                $q->where(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_LOAN)->where('owner_id', $loan->id))
                  ->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM)->whereIn('owner_id', $itemIds ?: [0]));
            })
            ->exists();

        $hasIdProof = \App\Models\Dhiran\DhiranAttachment::query()
            ->whereIn('document_type', ['id_proof_front', 'id_proof_back'])
            ->where(function ($q) use ($loan) {
                $q->where(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_LOAN)->where('owner_id', $loan->id));
                if ($loan->customer_id) {
                    $q->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_CUSTOMER)->where('owner_id', $loan->customer_id));
                }
            })
            ->exists();

        return [
            'item_photo' => $hasItemPhoto,
            'id_proof'   => $hasIdProof,
            'ok'         => $hasItemPhoto && $hasIdProof,
        ];
    }

    /**
     * Activate a pending_evidence loan once the required evidence exists.
     * Authoritative backend guard — never rely on UI hiding alone.
     */
    public function activateLoan(DhiranLoan $loan): void
    {
        DB::transaction(function () use ($loan): void {
            $loan = $this->lockActive($loan, 'pending_evidence'); // decided on the locked row: a concurrent cancel/close wins

            if (! $this->evidenceStatus($loan)['ok']) {
                throw new LogicException('Required evidence is missing: a pledged-item photo and a borrower ID proof must be uploaded first.');
            }

            $loan->status = 'active';
            $loan->save();
        });
    }

    /**
     * Cancel a loan still awaiting evidence (owner 2026-11-12, option "cancel with reversal"). Creation already booked
     * the payout and any processing fee; nothing booked is changed. New rows reverse them: the principal comes back in,
     * the fee goes back out, the ledger returns to 0, the items go back to the borrower, and the loan is 'cancelled'.
     */
    public function cancelPendingLoan(DhiranLoan $loan, string $reason): void
    {
        DB::transaction(function () use ($loan, $reason): void {
            // An old loan (brought in active from the paper book) can be removed if entered by mistake, until money moves.
            $loan = $this->lockActive($loan, $loan->brought_forward ? 'active' : 'pending_evidence');
            if ($loan->brought_forward && DhiranPayment::where('dhiran_loan_id', $loan->id)->exists()) {
                throw new LogicException('Money has been taken on this loan since it was brought in. Settle it the normal way.');
            }
            $actor = $this->actor($loan);
            $method = DhiranPayment::where('dhiran_loan_id', $loan->id)->where('type', 'disbursement')->value('payment_method') ?? 'cash';
            $principal = (float) $loan->outstanding_principal;
            $fee = (float) $loan->processing_fee;
            $base = ['shop_id' => $loan->shop_id, 'dhiran_loan_id' => $loan->id, 'created_by' => $actor];
            $note = "Loan cancelled before activation: {$loan->loan_number}" . ($reason !== '' ? " ({$reason})" : '');

            if ($loan->brought_forward) {
                // Nothing was paid out in the app: withdraw the opening ledger line only.
                DhiranLedgerEntry::record($base + ['entry_type' => 'cancellation', 'direction' => 'credit', 'amount' => $principal,
                    'balance_after' => 0, 'interest_balance_after' => 0, 'penalty_balance_after' => 0, 'note' => $note]);
            } else {
                $this->reversePayout($loan, $base, $method, $principal, $fee, $note, $loan->advanceInterestAmount());
            }

            \App\Services\Dhiran\LoanLog::bulkItems($loan, 'released', 'returned to the borrower (loan cancelled)');
            $loan->items()->where('status', 'pledged')->update(['status' => 'released', 'released_at' => now(), 'released_by' => $actor]);
            $loan->status = 'cancelled';
            $loan->outstanding_principal = 0;
            $loan->outstanding_interest = 0;
            $loan->interest_prepaid = 0;
            $loan->total_interest_collected = 0; // a cancelled loan never happened: the advance went back
            $loan->closed_at = now();
            $loan->closure_notes = $reason !== '' ? "Cancelled: {$reason}" : 'Cancelled';
            $loan->save();
        });
    }

    /** The cancellation's reversal of what creation booked: principal back in, fee back out, ledger to 0. */
    private function reversePayout(DhiranLoan $loan, array $base, string $method, float $principal, float $fee, string $note, float $advance = 0): void
    {
        $back = DhiranPayment::record($base + [
            'payment_date' => today()->toDateString(), 'type' => 'cancellation', 'amount' => $principal, 'direction' => 'in',
            'payment_method' => $method, 'interest_component' => 0, 'penalty_component' => 0, 'principal_component' => $principal,
            'processing_fee_component' => 0, 'outstanding_principal_after' => 0, 'outstanding_interest_after' => 0,
            'outstanding_penalty_after' => 0, 'notes' => $note,
        ]);
        DhiranCashEntry::record($base + ['dhiran_payment_id' => $back->id, 'entry_date' => today()->toDateString(), 'type' => 'in',
            'amount' => $principal, 'source_type' => 'cancellation', 'payment_method' => $method, 'description' => $note]);
        DhiranLedgerEntry::record($base + ['dhiran_payment_id' => $back->id, 'entry_type' => 'cancellation', 'direction' => 'credit',
            'amount' => $principal, 'balance_after' => 0, 'interest_balance_after' => 0, 'penalty_balance_after' => 0, 'note' => $note]);

        if ($fee > 0) {
            $refund = DhiranPayment::record($base + [
                'payment_date' => today()->toDateString(), 'type' => 'fee_refund', 'amount' => $fee, 'direction' => 'out',
                'payment_method' => $method, 'interest_component' => 0, 'penalty_component' => 0, 'principal_component' => 0,
                'processing_fee_component' => $fee, 'outstanding_principal_after' => 0, 'outstanding_interest_after' => 0,
                'outstanding_penalty_after' => 0, 'notes' => "Processing fee refunded: {$loan->loan_number}",
            ]);
            DhiranCashEntry::record($base + ['dhiran_payment_id' => $refund->id, 'entry_date' => today()->toDateString(), 'type' => 'out',
                'amount' => $fee, 'source_type' => 'fee_refund', 'payment_method' => $method, 'description' => "Processing fee refunded: {$loan->loan_number}"]);
            DhiranLedgerEntry::record($base + ['dhiran_payment_id' => $refund->id, 'entry_type' => 'fee_refund', 'direction' => 'debit',
                'amount' => $fee, 'balance_after' => 0, 'interest_balance_after' => 0, 'penalty_balance_after' => 0,
                'note' => "Processing fee refunded: {$loan->loan_number}"]);
        }

        if ($advance > 0) { // a cancelled pending loan gives the advance interest back: nothing was lent, nothing is kept
            $note = "Advance interest returned: {$loan->loan_number}";
            $refund = DhiranPayment::record($base + [
                'payment_date' => today()->toDateString(), 'type' => 'advance_refund', 'amount' => $advance, 'direction' => 'out',
                'payment_method' => $method, 'interest_component' => $advance, 'penalty_component' => 0, 'principal_component' => 0,
                'processing_fee_component' => 0, 'outstanding_principal_after' => 0, 'outstanding_interest_after' => 0,
                'outstanding_penalty_after' => 0, 'notes' => $note,
            ]);
            DhiranCashEntry::record($base + ['dhiran_payment_id' => $refund->id, 'entry_date' => today()->toDateString(), 'type' => 'out',
                'amount' => $advance, 'source_type' => 'advance_refund', 'payment_method' => $method, 'description' => $note]);
            DhiranLedgerEntry::record($base + ['dhiran_payment_id' => $refund->id, 'entry_type' => 'advance_refund', 'direction' => 'debit',
                'amount' => $advance, 'balance_after' => 0, 'interest_balance_after' => 0, 'penalty_balance_after' => 0, 'note' => $note]);
        }
    }

    /* ══════════════════════════════════════════════════════════
     *  1c. INTEREST PAID IN ADVANCE, MONTH BY MONTH
     * ══════════════════════════════════════════════════════════ */

    /**
     * Interest that covers [from, to) at the real number of days (rate / 100 / 30 per day) on the principal still owed. The same for flat,
     * daily and compound: an installment clears every bit of unpaid interest, so for a compound loan nothing is unpaid at an anniversary
     * (DhiranLoan::booted() also drops interest_capitalised to the interest still owed, i.e. 0) and no interest is charged on interest.
     */
    private function coverInterest(float $rateMonthly, Carbon $from, Carbon $to, float $principal): float
    {
        return $from->gte($to) ? 0.0 : $principal * ($rateMonthly / 100 / 30) * $from->diffInDays($to);
    }

    /** The cash-in payment, cash entry and ledger credit for interest taken ahead (day-0 advance and every monthly installment). */
    private function bookAdvanceInterest(DhiranLoan $loan, float $amount, string $method, ?int $createdBy, string $date, float $interestAfter, string $note): DhiranPayment
    {
        $payment = DhiranPayment::record([
            'shop_id' => $loan->shop_id, 'dhiran_loan_id' => $loan->id, 'payment_date' => $date, 'type' => 'advance_interest',
            'amount' => $amount, 'direction' => 'in', 'payment_method' => $method,
            'interest_component' => $amount, 'penalty_component' => 0, 'principal_component' => 0, 'processing_fee_component' => 0,
            'outstanding_principal_after' => (float) $loan->outstanding_principal, 'outstanding_interest_after' => $interestAfter,
            'outstanding_penalty_after' => (float) $loan->outstanding_penalty, 'notes' => $note, 'created_by' => $createdBy,
        ]);
        DhiranCashEntry::record([
            'shop_id' => $loan->shop_id, 'dhiran_loan_id' => $loan->id, 'dhiran_payment_id' => $payment->id, 'entry_date' => $date,
            'type' => 'in', 'amount' => $amount, 'source_type' => 'advance_interest', 'payment_method' => $method,
            'description' => $note, 'created_by' => $createdBy,
        ]);
        DhiranLedgerEntry::record([
            'shop_id' => $loan->shop_id, 'dhiran_loan_id' => $loan->id, 'dhiran_payment_id' => $payment->id, 'entry_type' => 'advance_interest',
            'direction' => 'credit', 'amount' => $amount, 'balance_after' => (float) $loan->outstanding_principal,
            'interest_balance_after' => $interestAfter, 'penalty_balance_after' => (float) $loan->outstanding_penalty,
            'note' => $note, 'created_by' => $createdBy,
        ]);

        return $payment;
    }

    /** The last period an installment paid today covers: the next unpaid one, or the month today falls in when the customer is several months late. */
    private function installmentTarget(DhiranLoan $loan): int
    {
        $loanDate = Carbon::parse($loan->loan_date)->startOfDay();
        $elapsed = 0;
        while ($loanDate->copy()->addMonthsNoOverflow($elapsed + 1)->lte(today())) {
            $elapsed++;
        }

        return max((int) $loan->interest_periods_paid + 1, $elapsed + 1);
    }

    /** What the installment costs today: the interest already owed plus the rest of the month today falls in (or the next unpaid month), less any advance still unspent. */
    private function installmentNeed(DhiranLoan $loan): float
    {
        $loanDate = Carbon::parse($loan->loan_date)->startOfDay();
        $end = $loanDate->copy()->addMonthsNoOverflow($this->installmentTarget($loan));
        $ahead = $this->coverInterest((float) $loan->interest_rate_monthly, today(), $end, (float) $loan->outstanding_principal);

        // interest_remainder: the fraction of a paisa accrued but not yet booked, so the amount is right to the paisa
        return max(0.0, round((float) $loan->outstanding_interest + (float) $loan->interest_remainder + $ahead - (float) $loan->interest_prepaid, 2));
    }

    /** The amount to collect for the next installment (up to date: accrues first). 0 for a loan that is not paid in advance. */
    public function installmentAmount(DhiranLoan $loan): float
    {
        if (! (int) $loan->advance_interest_months || $loan->status !== 'active') {
            return 0.0;
        }
        $this->accrueInterest($loan);

        return $this->installmentNeed($loan->refresh());
    }

    /**
     * Collect the next monthly installment of a loan paid in advance (due from 7 days before its date). The server works out the amount:
     * the interest already owed is cleared first, the rest is kept as advance that accrual spends day by day to the period end.
     * Returns null when the month is already fully covered (the due date still moves on).
     */
    public function payInstallment(DhiranLoan $loan, string $method = 'cash'): ?DhiranPayment
    {
        $this->assertActive($loan);
        if (! (int) $loan->advance_interest_months) {
            throw new LogicException('This loan is not paid in advance: it has no interest installments.');
        }
        $this->accrueInterest($loan);

        return DB::transaction(function () use ($loan, $method): ?DhiranPayment {
            $loan = $this->lockActive($loan);
            $due = $loan->nextInstallmentDue();
            if ($due->gt(today()->addDays(7))) {
                throw new LogicException('The next installment is not due yet (due ' . $due->format('d M Y') . ').');
            }
            $amount = $this->installmentNeed($loan);
            $target = $this->installmentTarget($loan);
            $owed = (float) $loan->outstanding_interest;
            $toOwed = round(min($amount, $owed), 2);
            $loan->outstanding_interest = round($owed - $toOwed, 2);
            $loan->interest_prepaid = round((float) $loan->interest_prepaid + ($amount - $toOwed), 2);
            $loan->total_interest_collected = round((float) $loan->total_interest_collected + $amount, 2);
            $loan->interest_periods_paid = $target;
            $loan->save();

            if ($amount <= 0) {
                return null;
            }
            $actor = $this->actor($loan);
            $month = $loan->nextInstallmentDue()->format('d M Y');

            return $this->bookAdvanceInterest($loan, $amount, $method, $actor, today()->toDateString(), (float) $loan->outstanding_interest,
                "Interest installment paid in advance, covers up to {$month}: {$loan->loan_number}");
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  2. ACCRUE INTEREST
     * ══════════════════════════════════════════════════════════ */

    /**
     * Calculate and accrue interest (and penalty if overdue) on a loan.
     * Idempotent for the same day — will not double-accrue.
     *
     * Note on flat-interest formula: flat interest is computed as
     * `monthly_interest / 30 * days`, i.e. a fixed 30-day month. This means a
     * 12-month loan held for 365 days will accrue ~12.17 months of interest
     * (since 365 / 30 ≈ 12.17). This is intentional per the flat-rate pawn
     * convention used in the industry — do not "fix" it by switching to
     * calendar-month accounting without a settings-level opt-in.
     */
    public function accrueInterest(DhiranLoan $loan): void
    {
        // Outer guard — cheap optimization to skip non-active loans without
        // opening a transaction. Authoritative re-check happens inside the
        // transaction closure below after we acquire the row lock.
        if ($loan->status !== 'active') {
            return;
        }

        DB::transaction(function () use ($loan): void {
            // Re-fetch with lock to prevent concurrent accrual. All state
            // (days, fromDate) is derived AFTER the lock is held so that two
            // concurrent accrual requests cannot both read the same stale
            // `interest_accrued_through` and double-post interest.
            $loan = DhiranLoan::lockForUpdate()->find($loan->id);
            // ponytail: no period-lock check here; accrual books interest owed, not cash, and runs on every loan view.

            // Re-check status under the lock — another process may have
            // closed/renewed/forfeited this loan between the outer guard and
            // here.
            if ($loan->status !== 'active') {
                return;
            }

            $today    = today();
            $fromDate = $loan->interest_accrued_through
                ? Carbon::parse($loan->interest_accrued_through)
                : Carbon::parse($loan->loan_date);

            $days = (int) $fromDate->diffInDays($today, false);

            if ($days <= 0) {
                return;
            }

            $principalAmount       = (float) $loan->principal_amount;
            $outstandingPrincipal  = (float) $loan->outstanding_principal;
            $outstandingInterest   = (float) $loan->outstanding_interest;
            $outstandingPenalty    = (float) $loan->outstanding_penalty;
            $interestRateMonthly   = (float) $loan->interest_rate_monthly;
            $penaltyRateMonthly    = (float) $loan->penalty_rate_monthly;
            $interestType          = $loan->interest_type;

            // ── Interest accrual ─────────────────────────────
            // Owner 2026-11-12: interest runs on the principal still OWED (it used to stay on the original amount after
            // part-payments, overcharging). Compound interest compounds at month ends (the loan's monthly anniversary):
            // between them the base is principal owed + the interest already unpaid at the last month end
            // (interest_capitalised), so the result no longer depends on how often accrual runs.
            $perDay = $interestRateMonthly / 100 / 30;
            if ($interestType === 'compound') {
                $capitalised = (float) $loan->interest_capitalised;
                $accrued = 0.0;
                $cursor = $fromDate->copy();
                $loanDate = Carbon::parse($loan->loan_date);
                for ($k = 1; ($monthEnd = $loanDate->copy()->addMonthsNoOverflow($k))->lte($today); $k++) {
                    if ($monthEnd->lte($cursor)) {
                        continue;
                    }
                    $accrued += ($outstandingPrincipal + $capitalised) * $perDay * $cursor->diffInDays($monthEnd);
                    $capitalised = max(0.0, $outstandingInterest + $accrued - (float) $loan->interest_prepaid); // everything unpaid (net of the advance) at this month end now earns interest
                    $cursor = $monthEnd;
                }
                $accrued += ($outstandingPrincipal + $capitalised) * $perDay * $cursor->diffInDays($today);
                $loan->interest_capitalised = round($capitalised, 2);
                $interestAccrual = $accrued;
            } else {
                // flat and daily: simple interest on the principal still owed, per day at the monthly rate / 30
                $interestAccrual = $outstandingPrincipal * $perDay * $days;
            }

            // book whole paise, carry the fraction to the next accrual (result independent of how often this runs)
            $rawInterest = $interestAccrual + (float) $loan->interest_remainder;
            $interestAccrual = round($rawInterest, 2);
            $loan->interest_remainder = $rawInterest - $interestAccrual;

            // ── Penalty accrual (if overdue past grace) ──────
            $penaltyAccrual = 0.0;

            if ($loan->maturity_date && $penaltyRateMonthly > 0) {
                $gracePeriodEnd = Carbon::parse($loan->maturity_date)->addDays($loan->grace_period_days);

                if ($today->gt($gracePeriodEnd)) {
                    // Only accrue penalty for the overdue days within this accrual period
                    $overduePeriodStart = $fromDate->gt($gracePeriodEnd) ? $fromDate : $gracePeriodEnd;
                    $overdueDaysInPeriod = (int) $overduePeriodStart->diffInDays($today, false);

                    if ($overdueDaysInPeriod > 0) {
                        $rawPenalty = $outstandingPrincipal * $penaltyRateMonthly / 100 / 30 * $overdueDaysInPeriod + (float) $loan->penalty_remainder;
                        $penaltyAccrual = round($rawPenalty, 2);
                        $loan->penalty_remainder = $rawPenalty - $penaltyAccrual;
                    }
                }
            }

            // ── Update loan balances ─────────────────────────
            // The advance taken at the counter pays the first days: it is spent before the customer owes anything.
            $prepaid = (float) $loan->interest_prepaid;
            $prepaidUsed = round(min($prepaid, $interestAccrual), 2);
            $loan->interest_prepaid = round($prepaid - $prepaidUsed, 2);
            $interestBeforeOffset = round($outstandingInterest + $interestAccrual, 2);
            $newOutstandingInterest = round($interestBeforeOffset - $prepaidUsed, 2);
            $newOutstandingPenalty  = round($outstandingPenalty + $penaltyAccrual, 2);

            $loan->outstanding_interest     = $newOutstandingInterest;
            $loan->outstanding_penalty      = $newOutstandingPenalty;
            $loan->interest_accrued_through = $today->toDateString();
            $loan->save();

            // ── Ledger entry for interest accrual ────────────
            if ($interestAccrual > 0) {
                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'entry_type'              => 'interest_accrual',
                    'direction'               => 'debit',
                    'amount'                  => $interestAccrual,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => $interestBeforeOffset,
                    'penalty_balance_after'   => $newOutstandingPenalty,
                    'note'                    => "Interest accrual: {$days} days ({$loan->interest_type})",
                    'meta'                    => json_encode([
                        'days'            => $days,
                        'interest_type'   => $interestType,
                        'rate_monthly'    => $interestRateMonthly,
                        'accrual_amount'  => $interestAccrual,
                    ]),
                ]);
            }

            // ── The log line for this accrual (a system step: nobody typed it) ──
            if ($interestAccrual > 0 || $penaltyAccrual > 0) {
                \App\Services\Dhiran\LoanLog::forLoan($loan, 'interest.accrued', 'system',
                    'Interest accrued ₹' . \App\Support\IndianNumber::format($interestAccrual, 2) . ($penaltyAccrual > 0 ? ' and penalty ₹' . \App\Support\IndianNumber::format($penaltyAccrual, 2) : '') . " for {$days} day" . ($days === 1 ? '' : 's')
                    . ($prepaidUsed > 0 ? ' (₹' . \App\Support\IndianNumber::format($prepaidUsed, 2) . ' covered by interest paid in advance)' : ''),
                    ['days' => $days, 'interest' => $interestAccrual, 'penalty' => $penaltyAccrual, 'covered_by_advance' => $prepaidUsed, 'interest_owed' => $newOutstandingInterest, 'penalty_owed' => $newOutstandingPenalty],
                    ['id' => null, 'name' => 'System']); // accrual runs when anyone opens the loan: it is the system's step, not the viewer's
            }

            // ── Ledger entry for the advance spent against this accrual ──
            if ($prepaidUsed > 0) {
                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'entry_type'              => 'advance_applied',
                    'direction'               => 'credit',
                    'amount'                  => $prepaidUsed,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => $newOutstandingInterest,
                    'penalty_balance_after'   => $newOutstandingPenalty,
                    'note'                    => 'Advance interest applied (already collected at the counter)',
                    'meta'                    => json_encode(['applied' => $prepaidUsed, 'advance_left' => (float) $loan->interest_prepaid]),
                ]);
            }

            // ── Ledger entry for penalty accrual ─────────────
            if ($penaltyAccrual > 0) {
                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'entry_type'              => 'penalty_accrual',
                    'direction'               => 'debit',
                    'amount'                  => $penaltyAccrual,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => $newOutstandingInterest,
                    'penalty_balance_after'   => $newOutstandingPenalty,
                    'note'                    => "Penalty accrual: overdue period",
                    'meta'                    => json_encode([
                        'penalty_rate_monthly' => $penaltyRateMonthly,
                        'penalty_amount'       => $penaltyAccrual,
                    ]),
                ]);
            }
        });

        // Refresh model in-place so callers see updated values. No additional
        // mid-transaction refresh is required: ledger inserts above use the
        // locally-updated values directly rather than re-reading $loan.
        $loan->refresh();
    }

    /* ══════════════════════════════════════════════════════════
     *  3. RECORD PAYMENT (penalty → interest → principal)
     * ══════════════════════════════════════════════════════════ */

    /**
     * Record a general payment. Splits: penalty first, then interest, then principal.
     * Auto-closes loan if balance reaches zero.
     */
    public function recordPayment(DhiranLoan $loan, float $amount, string $method = 'cash'): DhiranPayment
    {
        $this->assertActive($loan);
        $this->assertPaise($amount);

        // Accrue interest up to today before applying payment
        $this->accrueInterest($loan);

        return DB::transaction(function () use ($loan, $amount, $method): DhiranPayment {
            // Re-fetch with lock
            $loan = $this->lockActive($loan);

            $totalOutstanding = $loan->totalOutstanding();

            if ($amount > round($totalOutstanding, 2)) {
                throw new LogicException(
                    "Payment amount ({$amount}) exceeds total outstanding ({$totalOutstanding}). Overpayment not allowed."
                );
            }
            if ((int) round($totalOutstanding * 100) - (int) round($amount * 100) < 1) { // whole paise: float subtraction misreads 8.2 - 8.19
                $this->assertMaySettle($loan);
            }

            // ── Split payment: penalty → interest → principal
            $remaining = $amount;

            $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
            $remaining -= $penaltyComponent;

            $interestComponent = min($remaining, (float) $loan->outstanding_interest);
            $remaining -= $interestComponent;

            $principalComponent = min($remaining, (float) $loan->outstanding_principal);
            // Remaining after principal should be 0 due to overpayment check

            // Derive payment type from the dominant non-zero component so that
            // a payment covering only interest/penalty is not mislabelled as a
            // principal repayment.
            $type = $principalComponent > 0
                ? 'principal_repayment'
                : ($interestComponent > 0 ? 'interest_payment' : 'penalty_payment');

            // ── Update loan balances ─────────────────────────
            $loan->outstanding_penalty    = round((float) $loan->outstanding_penalty - $penaltyComponent, 2);
            $loan->outstanding_interest   = round((float) $loan->outstanding_interest - $interestComponent, 2);
            $loan->outstanding_principal  = round((float) $loan->outstanding_principal - $principalComponent, 2);
            $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
            $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
            $loan->total_principal_collected = round((float) $loan->total_principal_collected + $principalComponent, 2);
            $loan->save();

            // ── Create payment record ────────────────────────
            $payment = DhiranPayment::record([
                'shop_id'                    => $loan->shop_id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => today()->toDateString(),
                'type'                       => $type,
                'amount'                     => $amount,
                'direction'                  => 'in',
                'payment_method'             => $method,
                'interest_component'         => round($interestComponent, 2),
                'penalty_component'          => round($penaltyComponent, 2),
                'principal_component'        => round($principalComponent, 2),
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => (float) $loan->outstanding_principal,
                'outstanding_interest_after' => (float) $loan->outstanding_interest,
                'outstanding_penalty_after'  => (float) $loan->outstanding_penalty,
                'notes'                      => "Payment received: {$loan->loan_number}",
                'created_by'                 => $this->actor($loan),
            ]);

            // ── Cash entry ───────────────────────────────────
            DhiranCashEntry::record([
                'shop_id'            => $loan->shop_id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $payment->id,
                'entry_date'         => today()->toDateString(),
                'type'               => 'in',
                'amount'             => $amount,
                'source_type'        => 'principal_collection',
                'payment_method'     => $method,
                'description'        => "Payment: {$loan->loan_number} (P:{$principalComponent} I:{$interestComponent} Pen:{$penaltyComponent})",
                'created_by'         => $this->actor($loan),
            ]);

            // ── Ledger entry ─────────────────────────────────
            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $payment->id,
                'entry_type'              => 'principal_repayment',
                'direction'               => 'credit',
                'amount'                  => $amount,
                'balance_after'           => (float) $loan->outstanding_principal,
                'interest_balance_after'  => (float) $loan->outstanding_interest,
                'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                'note'                    => "Payment applied: P:{$principalComponent} I:{$interestComponent} Pen:{$penaltyComponent}",
                'created_by'              => $this->actor($loan),
            ]);

            // ── Auto-close if fully paid ─────────────────────
            // totalOutstanding() is rounded to 2 decimals, so anything under a
            // paisa is effectively zero — avoid float-equality pitfalls.
            if ($loan->totalOutstanding() < 0.01) {
                $this->closeLoanInternal($loan);
            }

            return $payment;
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  4. RECORD INTEREST PAYMENT (penalty + interest only)
     * ══════════════════════════════════════════════════════════ */

    /**
     * Record a payment that covers only penalty and interest — no principal reduction.
     */
    public function recordInterestPayment(DhiranLoan $loan, float $amount, string $method = 'cash'): DhiranPayment
    {
        $this->assertActive($loan);
        $this->assertPaise($amount);

        // Accrue interest up to today
        $this->accrueInterest($loan);

        return DB::transaction(function () use ($loan, $amount, $method): DhiranPayment {
            $loan = $this->lockActive($loan);

            $maxPayable = round((float) $loan->outstanding_penalty + (float) $loan->outstanding_interest, 2);

            if ($amount > $maxPayable) {
                throw new LogicException(
                    "Interest payment amount ({$amount}) exceeds outstanding penalty + interest ({$maxPayable})."
                );
            }

            // ── Split: penalty first, then interest ──────────
            $remaining = $amount;

            $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
            $remaining -= $penaltyComponent;

            $interestComponent = min($remaining, (float) $loan->outstanding_interest);

            // ── Update loan balances ─────────────────────────
            $loan->outstanding_penalty    = round((float) $loan->outstanding_penalty - $penaltyComponent, 2);
            $loan->outstanding_interest   = round((float) $loan->outstanding_interest - $interestComponent, 2);
            $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
            $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
            $loan->save();

            // ── Payment record ───────────────────────────────
            $payment = DhiranPayment::record([
                'shop_id'                    => $loan->shop_id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => today()->toDateString(),
                'type'                       => 'interest_payment',
                'amount'                     => $amount,
                'direction'                  => 'in',
                'payment_method'             => $method,
                'interest_component'         => round($interestComponent, 2),
                'penalty_component'          => round($penaltyComponent, 2),
                'principal_component'        => 0,
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => (float) $loan->outstanding_principal,
                'outstanding_interest_after' => (float) $loan->outstanding_interest,
                'outstanding_penalty_after'  => (float) $loan->outstanding_penalty,
                'notes'                      => "Interest payment: {$loan->loan_number}",
                'created_by'                 => $this->actor($loan),
            ]);

            DhiranCashEntry::record([
                'shop_id'            => $loan->shop_id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $payment->id,
                'entry_date'         => today()->toDateString(),
                'type'               => 'in',
                'amount'             => $amount,
                'source_type'        => 'interest_collection',
                'payment_method'     => $method,
                'description'        => "Interest payment: {$loan->loan_number} (I:{$interestComponent} Pen:{$penaltyComponent})",
                'created_by'         => $this->actor($loan),
            ]);

            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $payment->id,
                'entry_type'              => 'interest_collection',
                'direction'               => 'credit',
                'amount'                  => $amount,
                'balance_after'           => (float) $loan->outstanding_principal,
                'interest_balance_after'  => (float) $loan->outstanding_interest,
                'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                'note'                    => "Interest payment applied: I:{$interestComponent} Pen:{$penaltyComponent}",
                'created_by'              => $this->actor($loan),
            ]);

            // ── Auto-close if fully paid ─────────────────────
            // Symmetrical with recordPayment(): although this path never
            // touches principal, a loan whose principal was already settled
            // via prior releaseItem()/recordPayment() calls can have its final
            // interest/penalty cleared here — in which case totalOutstanding()
            // hits zero and the loan should close automatically.
            if ($loan->totalOutstanding() < 0.01) {
                $this->assertMaySettle($loan); // paying the last of the interest/penalty settles the loan
                $this->closeLoanInternal($loan);
            }

            return $payment;
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  5. RELEASE ITEM (partial release with proportional payment)
     * ══════════════════════════════════════════════════════════ */

    /**
     * Release a single pledged item. Requires proportional principal payment.
     */
    /**
     * What must be paid to release one pledged item: the penalty and interest due (a payment settles those first),
     * plus the item's share of the principal by market value among the items STILL pledged, so releasing the last
     * item clears the principal. (The loan's total_collateral_value never shrinks on release, so it cannot be the
     * basis: with two equal items the second release would ask for half of what is left.)
     */
    public function releaseMinimum(DhiranLoan $loan, DhiranLoanItem $item): float
    {
        $pledgedValue = (float) DhiranLoanItem::query()
            ->where('dhiran_loan_id', $loan->id)
            ->where('status', 'pledged')
            ->sum('market_value');
        if ($pledgedValue <= 0) {
            throw new LogicException('No pledged value is left on this loan — cannot calculate the release amount.');
        }

        $principalShare = round(((float) $item->market_value / $pledgedValue) * (float) $loan->outstanding_principal, 2);
        // the last item settles the loan, so it also carries any minimum-interest shortfall (charged on release)
        $isLast = ! DhiranLoanItem::query()->where('dhiran_loan_id', $loan->id)->where('status', 'pledged')->whereKeyNot($item->id)->exists();
        $shortfall = $isLast ? max(0.0, round($loan->minimumInterestAmount() - (float) $loan->total_interest_collected - (float) $loan->outstanding_interest, 2)) : 0.0;

        return round((float) $loan->outstanding_penalty + (float) $loan->outstanding_interest + $principalShare + $shortfall, 2);
    }

    public function releaseItem(
        DhiranLoan $loan,
        DhiranLoanItem $item,
        float $paymentAmount,
        string $method,
        string $conditionNote
    ): DhiranPayment {
        // ── Validations ──────────────────────────────────────
        $this->assertActive($loan);
        if ((int) $item->dhiran_loan_id !== (int) $loan->id) {
            throw new LogicException('Item does not belong to this loan.');
        }
        if ((int) $item->shop_id !== (int) $loan->shop_id) {
            throw new LogicException('Item does not belong to this shop.');
        }
        if ($item->status !== 'pledged') {
            throw new LogicException('Only pledged items can be released.');
        }
        $this->assertPaise($paymentAmount);

        // Accrue interest first
        $this->accrueInterest($loan);

        $actorId = auth()->id();

        return DB::transaction(function () use ($loan, $item, $paymentAmount, $method, $conditionNote, $actorId): DhiranPayment {
            $loan = $this->lockActive($loan);
            $item = DhiranLoanItem::lockForUpdate()->find($item->id);
            if (! $item || $item->status !== 'pledged') { // re-check under the lock: a stale copy must not release twice
                throw new LogicException('Only pledged items can be released.');
            }

            // releasing the last item settles the loan: lock period applies, and the minimum interest is charged (as pre-close does)
            if ($loan->items()->where('status', 'pledged')->whereKeyNot($item->id)->doesntExist()) {
                if (! $loan->canPreClose()) {
                    throw new LogicException('This release would settle the loan during its lock period. Settlement is allowed once the lock period ends.');
                }
                $this->applyMinimumInterest($loan);
            }
            $required = $this->releaseMinimum($loan, $item);
            $proportionalPrincipal = round($required - (float) $loan->outstanding_penalty - (float) $loan->outstanding_interest, 2);
            if ($paymentAmount < $required) {
                throw new LogicException(
                    'Payment amount (' . number_format($paymentAmount, 2) . ') is less than the ' . number_format($required, 2)
                    . ' needed to release this item (interest and penalty due, plus its share of the principal).'
                );
            }

            // Cap at total outstanding to prevent overpayment
            $paymentAmount = min($paymentAmount, $loan->totalOutstanding());

            // ── Record the payment (standard split) ──────────
            $remaining = $paymentAmount;

            $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
            $remaining -= $penaltyComponent;

            $interestComponent = min($remaining, (float) $loan->outstanding_interest);
            $remaining -= $interestComponent;

            $principalComponent = min($remaining, (float) $loan->outstanding_principal);

            // ── Update loan balances ─────────────────────────
            $loan->outstanding_penalty    = round((float) $loan->outstanding_penalty - $penaltyComponent, 2);
            $loan->outstanding_interest   = round((float) $loan->outstanding_interest - $interestComponent, 2);
            $loan->outstanding_principal  = round((float) $loan->outstanding_principal - $principalComponent, 2);
            $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
            $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
            $loan->total_principal_collected = round((float) $loan->total_principal_collected + $principalComponent, 2);
            $loan->save();

            // ── Mark item released ───────────────────────────
            $item->status                 = 'released';
            $item->released_at            = now();
            $item->release_condition_note = $conditionNote;
            $item->released_by            = $actorId ?? $loan->created_by;
            $item->save();

            // ── Payment record ───────────────────────────────
            $payment = DhiranPayment::record([
                'shop_id'                    => $loan->shop_id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => today()->toDateString(),
                'type'                       => 'principal_repayment',
                'amount'                     => $paymentAmount,
                'direction'                  => 'in',
                'payment_method'             => $method,
                'interest_component'         => round($interestComponent, 2),
                'penalty_component'          => round($penaltyComponent, 2),
                'principal_component'        => round($principalComponent, 2),
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => (float) $loan->outstanding_principal,
                'outstanding_interest_after' => (float) $loan->outstanding_interest,
                'outstanding_penalty_after'  => (float) $loan->outstanding_penalty,
                'notes'                      => "Item release payment: {$item->description}",
                'created_by'                 => $this->actor($loan),
            ]);

            DhiranCashEntry::record([
                'shop_id'            => $loan->shop_id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $payment->id,
                'entry_date'         => today()->toDateString(),
                'type'               => 'in',
                'amount'             => $paymentAmount,
                'source_type'        => 'principal_collection',
                'payment_method'     => $method,
                'description'        => "Item release: {$item->description} — {$loan->loan_number}",
                'created_by'         => $this->actor($loan),
            ]);

            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $payment->id,
                'entry_type'              => 'item_release',
                'direction'               => 'credit',
                'amount'                  => $paymentAmount,
                'balance_after'           => (float) $loan->outstanding_principal,
                'interest_balance_after'  => (float) $loan->outstanding_interest,
                'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                'note'                    => "Item released: {$item->description}",
                'meta'                    => json_encode([
                    'item_id'                => $item->id,
                    'proportional_principal' => $proportionalPrincipal,
                    'release_minimum'        => $required,
                    'condition_note'         => $conditionNote,
                ]),
                'created_by'              => $this->actor($loan),
            ]);

            // ── If all items released and zero balance → close
            // Tolerance comparison: totalOutstanding() is rounded to 2 dp.
            $pledgedCount = $loan->items()->where('status', 'pledged')->count();
            if ($pledgedCount === 0 && $loan->totalOutstanding() < 0.01) {
                $this->closeLoanInternal($loan);
            }

            return $payment;
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  6. PRE-CLOSE LOAN
     * ══════════════════════════════════════════════════════════ */

    /**
     * Pre-close a loan: enforce lock period and minimum interest, then pay full outstanding.
     */
    public function preCloseLoan(DhiranLoan $loan, string $method = 'cash'): ?DhiranPayment
    {
        $this->assertActive($loan);
        if (! $loan->canPreClose()) {
            throw new LogicException('Loan cannot be pre-closed during the lock period.');
        }

        // Accrue interest up to today
        $this->accrueInterest($loan);

        return DB::transaction(function () use ($loan, $method): ?DhiranPayment {
            $loan = $this->lockActive($loan);

            $this->applyMinimumInterest($loan);

            // ── Pay full outstanding ─────────────────────────
            $totalOutstanding = $loan->totalOutstanding();

            if ($totalOutstanding <= 0) {
                // Already fully paid — just close without a phantom payment row.
                $this->closeLoanInternal($loan);

                return null;
            }

            $remaining = $totalOutstanding;

            $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
            $remaining -= $penaltyComponent;

            $interestComponent = min($remaining, (float) $loan->outstanding_interest);
            $remaining -= $interestComponent;

            $principalComponent = min($remaining, (float) $loan->outstanding_principal);

            // ── Update loan ──────────────────────────────────
            $loan->outstanding_penalty    = 0;
            $loan->outstanding_interest   = 0;
            $loan->outstanding_principal  = 0;
            $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
            $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
            $loan->total_principal_collected = round((float) $loan->total_principal_collected + $principalComponent, 2);
            $loan->save();

            // ── Payment ──────────────────────────────────────
            $payment = DhiranPayment::record([
                'shop_id'                    => $loan->shop_id,
                'dhiran_loan_id'             => $loan->id,
                'payment_date'               => today()->toDateString(),
                'type'                       => 'pre_closure',
                'amount'                     => $totalOutstanding,
                'direction'                  => 'in',
                'payment_method'             => $method,
                'interest_component'         => round($interestComponent, 2),
                'penalty_component'          => round($penaltyComponent, 2),
                'principal_component'        => round($principalComponent, 2),
                'processing_fee_component'   => 0,
                'outstanding_principal_after' => 0,
                'outstanding_interest_after' => 0,
                'outstanding_penalty_after'  => 0,
                'notes'                      => "Pre-closure: {$loan->loan_number}",
                'created_by'                 => $this->actor($loan),
            ]);

            DhiranCashEntry::record([
                'shop_id'            => $loan->shop_id,
                'dhiran_loan_id'     => $loan->id,
                'dhiran_payment_id'  => $payment->id,
                'entry_date'         => today()->toDateString(),
                'type'               => 'in',
                'amount'             => $totalOutstanding,
                'source_type'        => 'pre_closure',
                'payment_method'     => $method,
                'description'        => "Pre-closure: {$loan->loan_number}",
                'created_by'         => $this->actor($loan),
            ]);

            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'dhiran_payment_id'       => $payment->id,
                'entry_type'              => 'pre_closure',
                'direction'               => 'credit',
                'amount'                  => $totalOutstanding,
                'balance_after'           => 0,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Pre-closure completed: {$loan->loan_number}",
                'created_by'              => $this->actor($loan),
            ]);

            // ── Close the loan ───────────────────────────────
            $this->closeLoanInternal($loan);

            return $payment;
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  7. RENEW LOAN
     * ══════════════════════════════════════════════════════════ */

    /**
     * Renew a loan: pay outstanding interest+penalty, mark old loan renewed,
     * create new loan with same collateral. No new cash disbursement.
     *
     * Note: gold_rate_on_date / silver_rate_on_date on the new loan are copied
     * from the old loan by design. Renewal is not a re-pledge — LTV is already
     * satisfied by the original collateral valuation and we never re-value on
     * renewal. If market rates must be refreshed, close and re-issue instead.
     */
    /**
     * What renewing collects now: interest and penalty due, topped up to the minimum interest (same rule as
     * renewLoan). Uses the loan's stored figures; renewLoan accrues today's interest first.
     */
    public function renewalDue(DhiranLoan $loan): float
    {
        $shortfall = max(0, $loan->minimumInterestAmount() - ((float) $loan->total_interest_collected + (float) $loan->outstanding_interest));

        return round((float) $loan->outstanding_interest + (float) $loan->outstanding_penalty + $shortfall, 2);
    }

    public function renewLoan(DhiranLoan $loan, ?int $newTenure = null, ?float $newRate = null, string $method = 'cash'): DhiranLoan
    {
        $this->assertActive($loan);

        // Accrue interest up to today
        $this->accrueInterest($loan);

        return DB::transaction(function () use ($loan, $newTenure, $newRate, $method): DhiranLoan {
            $loan = $this->lockActive($loan);

            // Enforce minimum interest on renewal (same rule as pre-close)
            // so a same-day renewal cannot bypass min_interest_months.
            $minimumInterest = $loan->minimumInterestAmount();
            $totalInterestCollected = (float) $loan->total_interest_collected + (float) $loan->outstanding_interest;

            if ($totalInterestCollected < $minimumInterest) {
                $shortfall = round($minimumInterest - $totalInterestCollected, 2);
                $loan->outstanding_interest = round((float) $loan->outstanding_interest + $shortfall, 2);
                $loan->save();

                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'entry_type'              => 'interest_accrual',
                    'direction'               => 'debit',
                    'amount'                  => $shortfall,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => (float) $loan->outstanding_interest,
                    'penalty_balance_after'   => (float) $loan->outstanding_penalty,
                    'note'                    => "Minimum interest enforcement (renewal): shortfall {$shortfall}",
                    'meta'                    => json_encode([
                        'min_interest_months' => $loan->min_interest_months,
                        'minimum_amount'      => $minimumInterest,
                        'shortfall'           => $shortfall,
                    ]),
                ]);
            }

            // ── Outstanding interest + penalty must be zero ──
            $interestAndPenalty = round((float) $loan->outstanding_interest + (float) $loan->outstanding_penalty, 2);

            if ($interestAndPenalty > 0) {
                // Auto-pay interest+penalty via recordInterestPayment
                // We need to do this inside the transaction
                $remaining = $interestAndPenalty;

                $penaltyComponent = min($remaining, (float) $loan->outstanding_penalty);
                $remaining -= $penaltyComponent;
                $interestComponent = min($remaining, (float) $loan->outstanding_interest);

                $loan->outstanding_penalty    = 0;
                $loan->outstanding_interest   = 0;
                $loan->total_penalty_collected  = round((float) $loan->total_penalty_collected + $penaltyComponent, 2);
                $loan->total_interest_collected = round((float) $loan->total_interest_collected + $interestComponent, 2);
                $loan->save();

                $renewalPayment = DhiranPayment::record([
                    'shop_id'                    => $loan->shop_id,
                    'dhiran_loan_id'             => $loan->id,
                    'payment_date'               => today()->toDateString(),
                    'type'                       => 'renewal_interest',
                    'amount'                     => $interestAndPenalty,
                    'direction'                  => 'in',
                    'payment_method'             => $method,
                    'interest_component'         => round($interestComponent, 2),
                    'penalty_component'          => round($penaltyComponent, 2),
                    'principal_component'        => 0,
                    'processing_fee_component'   => 0,
                    'outstanding_principal_after' => (float) $loan->outstanding_principal,
                    'outstanding_interest_after' => 0,
                    'outstanding_penalty_after'  => 0,
                    'notes'                      => "Interest cleared for renewal: {$loan->loan_number}",
                    'created_by'                 => $this->actor($loan),
                ]);

                DhiranCashEntry::record([
                    'shop_id'            => $loan->shop_id,
                    'dhiran_loan_id'     => $loan->id,
                    'dhiran_payment_id'  => $renewalPayment->id,
                    'entry_date'         => today()->toDateString(),
                    'type'               => 'in',
                    'amount'             => $interestAndPenalty,
                    'source_type'        => 'interest_collection',
                    'payment_method'     => $method,
                    'description'        => "Renewal interest cleared: {$loan->loan_number}",
                    'created_by'         => $this->actor($loan),
                ]);

                DhiranLedgerEntry::record([
                    'shop_id'                 => $loan->shop_id,
                    'dhiran_loan_id'          => $loan->id,
                    'dhiran_payment_id'       => $renewalPayment->id,
                    'entry_type'              => 'interest_collection',
                    'direction'               => 'credit',
                    'amount'                  => $interestAndPenalty,
                    'balance_after'           => (float) $loan->outstanding_principal,
                    'interest_balance_after'  => 0,
                    'penalty_balance_after'   => 0,
                    'note'                    => "Interest+penalty cleared for renewal",
                    'created_by'              => $this->actor($loan),
                ]);
            }

            // ── Validate renewal principal against loan limits ──
            // Renewal bypasses createLoan(), so replicate its min/max guard
            // here to prevent a largely-paid-down loan from renewing below
            // the configured floor, or a settings change to max from being
            // bypassed. Throwing inside the closure ensures a clean rollback.
            $oldPrincipal = (float) $loan->outstanding_principal;
            $settings     = DhiranSettings::getForShop($loan->shop_id);

            if ($oldPrincipal < (float) $settings->min_loan_amount) {
                throw new LogicException(
                    "Renewal principal ({$oldPrincipal}) is below minimum loan amount ({$settings->min_loan_amount}). Close and re-issue instead."
                );
            }
            if ($oldPrincipal > (float) $settings->max_loan_amount) {
                throw new LogicException(
                    "Renewal principal ({$oldPrincipal}) exceeds maximum loan amount ({$settings->max_loan_amount})."
                );
            }

            // ── Re-validate LTV ceiling against current settings ──
            // A settings change to default_ltv_ratio / high_value_ltv_ratio /
            // high_value_threshold between origination and renewal can push a
            // previously-valid principal above the current ceiling. We do NOT
            // re-price collateral (market_value and rate_per_gram_at_pledge are
            // carried forward by design, per the class docblock) — we only
            // re-apply the ratio to the existing market_value of still-pledged
            // items. If the old principal now breaches the ceiling, force a
            // partial repayment before allowing renewal.
            $pledgedItems = $loan->items()->where('status', 'pledged')->get();
            $totalMarketValue = round(
                (float) $pledgedItems->sum(fn ($i) => (float) $i->market_value),
                2
            );

            if ($totalMarketValue >= (float) $settings->high_value_threshold) {
                $ltvRatio = (float) $settings->high_value_ltv_ratio;
            } else {
                $ltvRatio = (float) $settings->default_ltv_ratio;
            }

            $newMaxPrincipal = round($totalMarketValue * $ltvRatio / 100, 2);

            if ($oldPrincipal > $newMaxPrincipal) {
                throw new LogicException(
                    "Renewal principal ({$oldPrincipal}) exceeds current LTV ceiling ({$newMaxPrincipal}) for market value {$totalMarketValue} at {$ltvRatio}% LTV. Partial repayment required before renewal."
                );
            }

            // Recompute each pledged item's loan_value at the current ratio so
            // the transferred items on the new loan reflect current policy.
            // market_value and rate_per_gram_at_pledge remain untouched.
            foreach ($pledgedItems as $pledgedItem) {
                $pledgedItem->loan_value = round((float) $pledgedItem->market_value * $ltvRatio / 100, 2);
                $pledgedItem->save();
            }

            // ── Mark old loan as renewed ─────────────────────
            $loan->status        = 'renewed';
            // The principal moves to the new loan (its ledger row below already says balance 0); leaving it here
            // counted it twice wherever loans are summed.
            $loan->outstanding_principal = 0;
            $loan->renewed_count = (int) $loan->renewed_count + 1;
            $loan->closed_at     = now();
            $loan->closure_notes = 'Renewed';
            $loan->save();

            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'entry_type'              => 'renewal',
                'direction'               => 'credit',
                'amount'                  => $oldPrincipal,
                'balance_after'           => 0,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Loan renewed: {$loan->loan_number}",
                'created_by'              => $this->actor($loan),
            ]);

            // ── Create new loan ──────────────────────────────
            // $settings was already fetched above for the min/max validation.
            $shop           = Shop::findOrFail($loan->shop_id);
            $newLoanNumber  = $this->generateLoanNumber($shop);
            $newTenureVal   = $newTenure ?? (int) $loan->tenure_months;
            $newRateVal     = $newRate ?? (float) $loan->interest_rate_monthly;
            if ($newTenureVal < 1 || $newRateVal < 0) {
                throw new LogicException('Tenure must be at least 1 month and the interest rate cannot be negative.');
            }
            $newLoanDate    = today();
            $newMaturity    = $newLoanDate->copy()->addMonthsNoOverflow($newTenureVal);

            // A loan paid in advance stays paid in advance: the new loan takes the same number of first months now
            // (the old loan's unspent advance stays with the shop). Only while the shop still allows it.
            // Never more months than the old loan took, the new tenure, or the 1..3 limit createLoan enforces.
            $keepMonths = ((int) $loan->advance_interest_months > 0 && DhiranSettings::getForShop($loan->shop_id)->advance_interest_enabled)
                ? min((int) $loan->advance_interest_months, $newTenureVal, 3) : 0;
            $newAdvance = $keepMonths ? round($this->coverInterest($newRateVal, $newLoanDate->copy(), $newLoanDate->copy()->addMonthsNoOverflow($keepMonths), $oldPrincipal), 2) : 0.0;
            if ($newAdvance <= 0 || $newAdvance >= $oldPrincipal) {
                [$keepMonths, $newAdvance] = [0, 0.0];
            }

            $newLoan = DhiranLoan::create([
                'shop_id'                  => $loan->shop_id,
                'customer_id'              => $loan->customer_id,
                'loan_number'              => $newLoanNumber,
                'loan_date'                => $newLoanDate->toDateString(),
                'gold_rate_on_date'        => $loan->gold_rate_on_date,
                'silver_rate_on_date'      => $loan->silver_rate_on_date,
                'principal_amount'         => $oldPrincipal,
                'processing_fee'           => 0,
                'processing_fee_type'      => 'flat',
                'interest_rate_monthly'    => $newRateVal,
                'interest_type'            => $loan->interest_type,
                'penalty_rate_monthly'     => (float) $loan->penalty_rate_monthly,
                // Use the freshly re-validated LTV ratio so the new loan row
                // matches the current policy snapshot (and the per-item
                // loan_value values re-computed just above).
                'ltv_ratio_applied'        => $ltvRatio,
                'total_collateral_value'   => (float) $loan->total_collateral_value,
                'total_fine_weight'        => (float) $loan->total_fine_weight,
                'outstanding_principal'    => $oldPrincipal,
                'outstanding_interest'     => 0,
                'outstanding_penalty'      => 0,
                'interest_accrued_through' => $newLoanDate->toDateString(),
                'total_interest_collected' => $newAdvance,
                'advance_interest_months'  => $keepMonths,
                'interest_periods_paid'    => $keepMonths,
                'interest_prepaid'         => $newAdvance,
                'total_penalty_collected'  => 0,
                'total_principal_collected' => 0,
                'tenure_months'            => $newTenureVal,
                'maturity_date'            => $newMaturity->toDateString(),
                'min_lock_months'          => (int) $loan->min_lock_months,
                'grace_period_days'        => (int) $loan->grace_period_days,
                'min_interest_months'      => (int) $loan->min_interest_months,
                'status'                   => 'active',
                'renewed_count'            => 0,
                'renewed_from_id'          => $loan->id,
                'kyc_aadhaar'              => $loan->kyc_aadhaar,
                'kyc_pan'                  => $loan->kyc_pan,
                'kyc_photo_path'           => $loan->kyc_photo_path,
                'terms_text'               => $loan->terms_text,
                'notes'                    => "Renewed from {$loan->loan_number}",
                'created_by'               => auth()->id() ?? $loan->created_by,
            ]);

            // ── Transfer pledged items to new loan ───────────
            \App\Services\Dhiran\LoanLog::bulkItems($loan, 'moved', "moved to the renewed loan {$newLoan->loan_number}", $newLoan);
            $loan->items()->where('status', 'pledged')->update([
                'dhiran_loan_id' => $newLoan->id,
            ]);

            // ── Ledger entry for new loan (no cash disbursement) ─
            DhiranLedgerEntry::record([
                'shop_id'                 => $newLoan->shop_id,
                'dhiran_loan_id'          => $newLoan->id,
                'entry_type'              => 'renewal',
                'direction'               => 'debit',
                'amount'                  => $oldPrincipal,
                'balance_after'           => $oldPrincipal,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Renewed from {$loan->loan_number}",
                'meta'                    => json_encode([
                    'renewed_from_id'     => $loan->id,
                    'renewed_from_number' => $loan->loan_number,
                ]),
                'created_by'              => $this->actor($loan),
            ]);

            if ($newAdvance > 0) {
                $this->bookAdvanceInterest($newLoan, $newAdvance, $method, $this->actor($loan), $newLoanDate->toDateString(), 0.0,
                    "Advance interest for {$keepMonths} month(s) on renewal: {$newLoanNumber}");
            }

            return $newLoan->fresh(['items']);
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  8. CLOSE LOAN
     * ══════════════════════════════════════════════════════════ */

    /**
     * Close a loan. Validates zero outstanding balance.
     */
    public function closeLoan(DhiranLoan $loan): void
    {
        $this->assertActive($loan);
        $this->accrueInterest($loan); // never discharge interest that is owed but not yet booked

        DB::transaction(function () use ($loan): void {
            $loan = $this->lockActive($loan);
            if ($loan->totalOutstanding() > 0) { // decided on the locked, freshly accrued row
                throw new LogicException(
                    'Cannot close loan with outstanding balance of ' . $loan->totalOutstanding() . '. Pay in full first.'
                );
            }
            $this->assertMaySettle($loan); // closing is a settlement too (e.g. after a non-last release paid everything off)
            $this->closeLoanInternal($loan);
        });
    }

    /**
     * Internal close — assumes caller is inside a transaction and has validated preconditions.
     */
    private function closeLoanInternal(DhiranLoan $loan): void
    {
        // Capture actor once — auth()->id() in web context, falling back to
        // the loan's original creator for CLI/background invocations.
        $actorId = auth()->id() ?? $loan->created_by;

        // Release all remaining pledged items. released_by must be set for
        // audit consistency with the per-item releaseItem() path (see M5 fix).
        \App\Services\Dhiran\LoanLog::bulkItems($loan, 'released', 'returned to the borrower (loan closed)');
        $loan->items()->where('status', 'pledged')->update([
            'status'      => 'released',
            'released_at' => now(),
            'released_by' => $actorId,
        ]);

        $loan->status    = 'closed';
        $loan->closed_at = now();
        $loan->save();

        DhiranLedgerEntry::record([
            'shop_id'                 => $loan->shop_id,
            'dhiran_loan_id'          => $loan->id,
            'entry_type'              => 'closure',
            'direction'               => 'credit',
            'amount'                  => 0,
            'balance_after'           => 0,
            'interest_balance_after'  => 0,
            'penalty_balance_after'   => 0,
            'note'                    => "Loan closed: {$loan->loan_number}",
            'created_by'              => $actorId,
        ]);
    }

    /* ══════════════════════════════════════════════════════════
     *  9. SEND FORFEITURE NOTICE
     * ══════════════════════════════════════════════════════════ */

    /** Refuse unless the loan is at $wanted on the forfeiture road (DhiranLoan::forfeitureStage), saying why. */
    private function assertForfeitureStage(DhiranLoan $loan, string $wanted): void
    {
        $stage = $loan->forfeitureStage();
        if ($stage === $wanted) {
            return;
        }
        $deadline = $loan->forfeitureDeadline();

        throw new LogicException(match (true) {
            $stage === null => self::notActive((string) $loan->status),
            $wanted === 'notice_due' && $stage === 'current' => 'Forfeiture notice can only be sent for overdue loans.',
            $wanted === 'notice_due' && $stage === 'grace' => 'Loan is still within the grace period. Cannot send forfeiture notice yet.',
            $wanted === 'notice_due' => 'A forfeiture notice was already sent on ' . $loan->forfeiture_notice_sent_at->format('d M Y') . '.',
            $stage === 'notice_sent' => 'Forfeiture notice period has not elapsed. ' . (int) today()->diffInDays($deadline, false)
                . " days remaining (the notice gives until {$deadline->format('d M Y')}).",
            default => 'Forfeiture notice must be sent before executing forfeiture.',
        });
    }

    /**
     * Send forfeiture notice to borrower (RBI 30-day notice requirement).
     */
    public function sendForfeitureNotice(DhiranLoan $loan): void
    {
        $this->assertForfeitureStage($loan, 'notice_due');

        // The notice quotes the balance, so bring interest and penalty up to today first (as release and renewal do).
        $this->accrueInterest($loan);

        DB::transaction(function () use ($loan): void {
            $loan = $this->lockActive($loan);
            $this->assertForfeitureStage($loan, 'notice_due'); // a notice sent from another page meanwhile

            $settings = DhiranSettings::getForShop($loan->shop_id);
            $rupees = fn ($v) => '₹' . number_format((float) $v, 2);

            // The printed page carries the "Forfeiture Notice" title; the saved text starts with the facts.
            $noticeText = "Loan Number: {$loan->loan_number}\n"
                . "Loan Date: {$loan->loan_date->format('d-M-Y')}\n"
                . 'Principal Amount: ' . $rupees($loan->principal_amount) . "\n"
                . 'Outstanding Balance: ' . $rupees($loan->totalOutstanding()) . "\n"
                . "Maturity Date: {$loan->maturity_date->format('d-M-Y')}\n"
                . "Days Overdue: {$loan->daysOverdue()}\n\n"
                . "This is to inform you that your gold loan is overdue. "
                . "If the outstanding amount is not settled within {$settings->forfeiture_notice_days} days "
                . "from the date of this notice, the pledged items will be forfeited.\n\n"
                . "Date of Notice: " . today()->format('d-M-Y');

            $loan->forfeiture_notice_sent_at = now();
            $loan->forfeiture_notice_text    = $noticeText;
            // The deadline the notice promises; forfeiture waits for it whatever the setting says later.
            $loan->forfeiture_deadline       = today()->addDays((int) $settings->forfeiture_notice_days)->toDateString();
            $loan->save();
        });
    }

    /* ══════════════════════════════════════════════════════════
     *  10. EXECUTE FORFEITURE
     * ══════════════════════════════════════════════════════════ */

    /**
     * Execute forfeiture: validate notice period elapsed, forfeit items, write off balances.
     */
    public function executeForfeit(DhiranLoan $loan): void
    {
        $this->assertForfeitureStage($loan, 'ready');

        $actorId = auth()->id() ?? $loan->created_by;

        DB::transaction(function () use ($loan, $actorId): void {
            $loan = $this->lockActive($loan);
            $this->assertForfeitureStage($loan, 'ready');
            $this->accrueInterest($loan); // the write-off records everything owed up to today, not up to the last nightly run

            $writtenOffPrincipal = (float) $loan->outstanding_principal;
            $writtenOffInterest  = (float) $loan->outstanding_interest;
            $writtenOffPenalty   = (float) $loan->outstanding_penalty;
            $totalWrittenOff     = round($writtenOffPrincipal + $writtenOffInterest + $writtenOffPenalty, 2);

            // ── Mark all pledged items as forfeited ──────────
            \App\Services\Dhiran\LoanLog::bulkItems($loan, 'forfeited', 'forfeited (loan forfeited)');
            $loan->items()->where('status', 'pledged')->update([
                'status'       => 'forfeited',
                'forfeited_at' => now(),
            ]);

            // ── Write off all outstanding balances ───────────
            $loan->outstanding_principal = 0;
            $loan->outstanding_interest  = 0;
            $loan->outstanding_penalty   = 0;
            $loan->status                = 'forfeited';
            $loan->forfeited_at          = now();
            $loan->forfeited_by          = $actorId;
            $loan->save();

            // ── Ledger entry for forfeiture ──────────────────
            DhiranLedgerEntry::record([
                'shop_id'                 => $loan->shop_id,
                'dhiran_loan_id'          => $loan->id,
                'entry_type'              => 'forfeiture',
                'direction'               => 'credit',
                'amount'                  => $totalWrittenOff,
                'balance_after'           => 0,
                'interest_balance_after'  => 0,
                'penalty_balance_after'   => 0,
                'note'                    => "Forfeiture executed: wrote off P:{$writtenOffPrincipal} I:{$writtenOffInterest} Pen:{$writtenOffPenalty}",
                'meta'                    => json_encode([
                    'written_off_principal' => $writtenOffPrincipal,
                    'written_off_interest'  => $writtenOffInterest,
                    'written_off_penalty'   => $writtenOffPenalty,
                ]),
                'created_by'              => $actorId,
            ]);
        });

        $loan->refresh();
    }

    /* ══════════════════════════════════════════════════════════
     *  11. LOAN SUMMARY
     * ══════════════════════════════════════════════════════════ */

    /**
     * Full computed snapshot of a loan's current state. PURE READ (Phase 5,
     * Part D): this NO LONGER accrues interest, so it is safe on any HTTP GET
     * (show / receipt / report) and on a read-replica. The returned interest /
     * penalty reflect state as of the last accrual (interest_accrued_through).
     *
     * Callers that must act on TODAY's numbers before a financial write (payment,
     * pre-close, renew, forfeit) already call accrueInterest() inside their own
     * transaction; for a read context that explicitly wants fresh figures, use
     * accrueAndSummary() instead.
     */
    public function loanSummary(DhiranLoan $loan): array
    {
        $loan->loadMissing(['items', 'payments', 'customer']);

        $pledgedItems  = $loan->items->where('status', 'pledged');
        $releasedItems = $loan->items->where('status', 'released');
        $forfeitedItems = $loan->items->where('status', 'forfeited');

        return [
            'loan_id'                  => $loan->id,
            'loan_number'              => $loan->loan_number,
            'status'                   => $loan->status,
            'customer_name'            => $loan->customer->name ?? null,
            'customer_id'              => $loan->customer_id,
            'loan_date'                => $loan->loan_date->toDateString(),
            'maturity_date'            => $loan->maturity_date->toDateString(),
            'tenure_months'            => $loan->tenure_months,
            'principal_amount'         => (float) $loan->principal_amount,
            'interest_rate_monthly'    => (float) $loan->interest_rate_monthly,
            'interest_type'            => $loan->interest_type,
            'penalty_rate_monthly'     => (float) $loan->penalty_rate_monthly,
            'ltv_ratio_applied'        => (float) $loan->ltv_ratio_applied,
            'total_collateral_value'   => (float) $loan->total_collateral_value,
            'total_fine_weight'        => (float) $loan->total_fine_weight,
            'outstanding_principal'    => (float) $loan->outstanding_principal,
            'outstanding_interest'     => (float) $loan->outstanding_interest,
            'outstanding_penalty'      => (float) $loan->outstanding_penalty,
            'total_outstanding'        => $loan->totalOutstanding(),
            'total_interest_collected' => (float) $loan->total_interest_collected,
            'total_penalty_collected'  => (float) $loan->total_penalty_collected,
            'total_principal_collected' => (float) $loan->total_principal_collected,
            'processing_fee'           => (float) $loan->processing_fee,
            'is_overdue'               => $loan->isOverdue(),
            'days_overdue'             => $loan->daysOverdue(),
            'days_till_maturity'       => $loan->daysTillMaturity(),
            'is_in_lock_period'        => $loan->isInLockPeriod(),
            'can_pre_close'            => $loan->canPreClose(),
            'minimum_interest_amount'  => $loan->minimumInterestAmount(),
            'renewed_count'            => (int) $loan->renewed_count,
            'renewed_from_id'          => $loan->renewed_from_id,
            'items_count'              => $loan->items->count(),
            'pledged_items_count'      => $pledgedItems->count(),
            'released_items_count'     => $releasedItems->count(),
            'forfeited_items_count'    => $forfeitedItems->count(),
            'payments_count'           => $loan->payments->count(),
            'interest_accrued_through' => $loan->interest_accrued_through
                ? Carbon::parse($loan->interest_accrued_through)->toDateString()
                : null,
            'closed_at'                => $loan->closed_at?->toDateTimeString(),
            'forfeited_at'             => $loan->forfeited_at?->toDateTimeString(),
            'forfeiture_notice_sent_at' => $loan->forfeiture_notice_sent_at?->toDateTimeString(),
        ];
    }

    /**
     * Accrue interest to today (a WRITE), then return the fresh snapshot. Use this
     * only from an explicit POST action / scheduled job / service call that intends
     * to bring the loan current before reading — never from a plain GET. GET routes
     * should call the pure loanSummary() instead.
     */
    public function accrueAndSummary(DhiranLoan $loan): array
    {
        if ($loan->status === 'active') {
            $this->accrueInterest($loan);
            $loan->refresh();
        }

        return $this->loanSummary($loan);
    }

    /* ══════════════════════════════════════════════════════════
     *  12. BATCH INTEREST ACCRUAL
     * ══════════════════════════════════════════════════════════ */

    /**
     * Accrue interest on all active loans for a shop. Returns count processed.
     */
    public function accrueInterestBatch(int $shopId): int
    {
        // Callers run this inside TenantContext::runFor($shopId); the explicit
        // where('shop_id') is belt-and-suspenders alongside the tenant scope.
        $loans = DhiranLoan::where('shop_id', $shopId)
            ->where('status', 'active')
            ->get();

        $count = 0;

        foreach ($loans as $loan) {
            // One bad loan must not abort the rest of the shop's batch.
            try {
                $this->accrueInterest($loan);
                $count++;
            } catch (\Throwable $e) {
                \Log::error("dhiran accrueInterestBatch: loan #{$loan->id} failed", ['exception' => $e]);
            }
        }

        return $count;
    }

    /* ══════════════════════════════════════════════════════════
     *  13. CUSTOMER LOAN HISTORY
     * ══════════════════════════════════════════════════════════ */

    /**
     * Get all loans for a customer, optionally filtered by status.
     */
    public function customerLoanHistory(Customer $customer, ?string $status = null): Collection
    {
        $query = DhiranLoan::where('customer_id', $customer->id)
            ->where('shop_id', $customer->shop_id);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->with(['items'])
            ->orderByDesc('loan_date')
            ->orderByDesc('id')
            ->get();
    }

    /* ══════════════════════════════════════════════════════════
     *  14. GET OVERDUE LOANS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Active loans past maturity date.
     */
    public function getOverdueLoans(int $shopId): Collection
    {
        return DhiranLoan::where('shop_id', $shopId)
            ->overdue()
            ->with(['customer', 'items'])
            ->orderBy('maturity_date')
            ->get();
    }

    /* ══════════════════════════════════════════════════════════
     *  15. GET DEFAULT RISK LOANS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Loans past maturity + grace period — at risk of forfeiture.
     */
    public function getDefaultRiskLoans(int $shopId): Collection
    {
        return DhiranLoan::where('shop_id', $shopId)
            ->defaultRisk()
            ->with(['customer', 'items'])
            ->orderBy('maturity_date')
            ->get();
    }

    /* ══════════════════════════════════════════════════════════
     *  16. GET UNPROFITABLE LOANS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Loans where total interest collected is less than expected minimum.
     * "Expected" = principal * rate * months_elapsed / 100
     */
    public function getUnprofitableLoans(int $shopId): Collection
    {
        return DhiranLoan::where('shop_id', $shopId)
            ->where('status', 'active')
            ->with(['customer'])
            ->get()
            ->filter(function (DhiranLoan $loan): bool {
                $loanDate = Carbon::parse($loan->loan_date);
                $monthsElapsed = max(1, (int) ceil($loanDate->diffInDays(today()) / 30));
                $expectedInterest = (float) $loan->principal_amount
                    * (float) $loan->interest_rate_monthly / 100
                    * $monthsElapsed;

                $actualCollected = (float) $loan->total_interest_collected;

                return $actualCollected < $expectedInterest;
            })
            ->values();
    }

    /* ══════════════════════════════════════════════════════════
     *  17. GENERATE LOAN NUMBER
     * ══════════════════════════════════════════════════════════ */

    /**
     * Generate the next loan number using BusinessIdentifierService pattern
     * with ShopCounter and lockForUpdate for concurrency safety.
     */
    public function generateLoanNumber(Shop $shop): string
    {
        // Concurrency-safe even when invoked outside createLoan's transaction:
        // nextCounter() opens its own DB::transaction with lockForUpdate on the
        // ShopCounter row, and dhiran_loans has a UNIQUE(shop_id, loan_number)
        // constraint as a belt-and-braces safeguard against duplicates.
        $settings = DhiranSettings::getForShop($shop->id);
        $prefix   = trim($settings->loan_number_prefix ?: 'DH-');

        // A prefix that ends in digits can reproduce an older number (prefix 'DH-1' + 1 and 'DH-' + 11 are both 'DH-11'): skip taken numbers.
        do {
            $sequence = BusinessIdentifierService::nextCounter($shop->id, BusinessIdentifierService::KEY_DHIRAN);
            $number = $prefix . str_pad((string) $sequence, max(1, (int) ($settings->loan_number_digits ?: 6)), '0', STR_PAD_LEFT); // digits = 1 means no leading zeros
        } while (DhiranLoan::withoutGlobalScopes()->where('shop_id', $shop->id)->where('loan_number', $number)->exists());

        return $number;
    }
}
