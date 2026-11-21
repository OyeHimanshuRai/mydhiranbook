<?php

namespace Tests\Feature\Ruthless;

use App\Models\Customer;
use App\Models\Dhiran\DhiranAttachment;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Adversarial loan-engine suite. Every test fixes "now" in Asia/Kolkata and asserts an exact value derived from the
 * stated business rule. Shared rules:
 *   interest (flat/daily)   = outstanding principal * rate% / 30 * actual days, rounded to the paisa per accrual
 *   penalty                 = outstanding principal * penalty% / 30 * days after (maturity + grace)
 *   allocation of a payment = penalty, then interest, then principal
 *   books balance           = per payment components sum to the amount; cash book = payments; balances never < 0
 */
class LoanEngineTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';

    private DhiranService $svc;
    private Shop $shop;
    private User $owner;
    private Customer $customer;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-01-01');
        $this->svc = app(DhiranService::class);
        $this->shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390077000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390077000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0,
            'default_min_lock_months' => 0, 'default_min_interest_months' => 0, 'default_penalty_rate_monthly' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390077000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'email' => 'r@example.com', 'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $this->customer = $this->t(fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Rut', 'last_name' => 'Hless', 'mobile' => '9815570001']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function at(string $date, string $time = '10:00'): void
    {
        Carbon::setTestNow(Carbon::parse("{$date} {$time}", 'Asia/Kolkata'));
    }

    private function t(callable $fn, ?int $shopId = null)
    {
        return TenantContext::runFor($shopId ?? $this->shop->id, $fn);
    }

    private function gold(float $grams = 100, float $purity = 24, float $rate = 6000, string $desc = 'Gold chain'): array
    {
        return ['description' => $desc, 'metal_type' => 'gold', 'purity' => $purity, 'gross_weight' => $grams, 'rate_per_gram_at_pledge' => $rate];
    }

    /** Default: 100 g 24k gold @6000 = 600000 market, 75% => 450000 cap; principal 100000; flat 2%; 12 months. */
    private function loan(array $p = [], string $date = '2026-01-01', ?array $items = null): DhiranLoan
    {
        $this->at($date);
        $customer = $this->customer;

        return $this->t(fn () => $this->svc->createLoan($this->shop, $customer, $items ?? [$this->gold()], array_merge([
            'principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2.0,
            'interest_type' => 'flat', 'penalty_rate_monthly' => 0, 'tenure_months' => 12, 'min_lock_months' => 0,
            'min_interest_months' => 0, 'grace_period_days' => 30, 'loan_date' => $date, 'created_by' => null,
        ], $p)));
    }

    private function fresh(DhiranLoan $l): DhiranLoan
    {
        return DhiranLoan::withoutGlobalScopes()->findOrFail($l->id);
    }

    private function accrue(DhiranLoan $l): DhiranLoan
    {
        $this->t(fn () => $this->svc->accrueInterest($this->fresh($l)));

        return $this->fresh($l);
    }

    private function pay(DhiranLoan $l, float $amt)
    {
        return $this->t(fn () => $this->svc->recordPayment($this->fresh($l), $amt));
    }

    private function payInt(DhiranLoan $l, float $amt)
    {
        return $this->t(fn () => $this->svc->recordInterestPayment($this->fresh($l), $amt));
    }

    private function sum(string $table, int $loanId, string $col, array $where = []): float
    {
        $q = DB::table($table)->where('dhiran_loan_id', $loanId);
        foreach ($where as $k => $v) {
            $q->where($k, $v);
        }

        return round((float) $q->sum($col), 2);
    }

    private function counts(DhiranLoan $l): array
    {
        return [
            DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->count(),
            DB::table('dhiran_cash_entries')->where('dhiran_loan_id', $l->id)->count(),
            DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $l->id)->count(),
        ];
    }

    private function refused(callable $fn, string $why): void
    {
        try {
            $fn();
        } catch (LogicException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("expected a LogicException: {$why}");
    }

    /** The books rules every sequence of operations must keep. */
    private function assertBooks(DhiranLoan $l): void
    {
        $id = $l->id;
        $row = (array) DB::table('dhiran_loans')->find($id);
        foreach (DB::table('dhiran_payments')->where('dhiran_loan_id', $id)->get() as $p) {
            $parts = round((float) $p->interest_component + (float) $p->penalty_component + (float) $p->principal_component + (float) $p->processing_fee_component, 2);
            $this->assertEqualsWithDelta((float) $p->amount, $parts, 0.0001, "payment #{$p->id} ({$p->type}): components must sum to amount");
        }
        foreach (['in', 'out'] as $dir) {
            $this->assertEqualsWithDelta($this->sum('dhiran_payments', $id, 'amount', ['direction' => $dir]),
                $this->sum('dhiran_cash_entries', $id, 'amount', ['type' => $dir]), 0.0001, "cash book {$dir} must equal payments {$dir}");
        }
        foreach (['outstanding_principal', 'outstanding_interest', 'outstanding_penalty'] as $c) {
            $this->assertGreaterThanOrEqual(0.0, (float) $row[$c], "{$c} never negative");
        }
        if (in_array($row['status'], ['active', 'closed'], true) && ! $row['brought_forward']) {
            $this->assertEqualsWithDelta((float) $row['principal_amount'] - (float) $row['total_principal_collected'], (float) $row['outstanding_principal'], 0.0001, 'principal owed = lent - collected');
            $this->assertEqualsWithDelta($this->sum('dhiran_ledger_entries', $id, 'amount', ['entry_type' => 'interest_accrual']) - (float) $row['total_interest_collected'], (float) $row['outstanding_interest'], 0.0001, 'interest owed = accrued - collected');
            $this->assertEqualsWithDelta($this->sum('dhiran_ledger_entries', $id, 'amount', ['entry_type' => 'penalty_accrual']) - (float) $row['total_penalty_collected'], (float) $row['outstanding_penalty'], 0.0001, 'penalty owed = accrued - collected');
            $last = DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $id)->orderByDesc('id')->first();
            $this->assertEqualsWithDelta((float) $row['outstanding_principal'], (float) $last->balance_after, 0.0001, 'ledger running principal = loan principal');
            $this->assertEqualsWithDelta((float) $row['outstanding_interest'], (float) $last->interest_balance_after, 0.0001, 'ledger running interest = loan interest');
        }
    }

    // ═════════════════ INTEREST MATH ═════════════════

    public function test_flat_interest_counts_actual_calendar_days_across_february(): void
    {
        // Rule: 31 Jan -> 28 Feb = 28 days; 100000 * 2% / 30 * 28 = 1866.666 -> 1866.67.
        $l = $this->loan([], '2026-01-31');
        $this->at('2026-02-28');
        $this->assertSame('1866.67', $this->accrue($l)->outstanding_interest);
    }

    public function test_leap_day_is_a_real_day_of_interest(): void
    {
        // Rule: 3%/month on 100000 = 100.00 a day. 28 Feb -> 1 Mar 2028 (leap) is 2 days; 2027 is 1 day.
        $leap = $this->loan(['interest_rate_monthly' => 3], '2028-02-28');
        $this->at('2028-03-01');
        $this->assertSame('200.00', $this->accrue($leap)->outstanding_interest);

        $plain = $this->loan(['interest_rate_monthly' => 3], '2027-02-28');
        $this->at('2027-03-01');
        $this->assertSame('100.00', $this->accrue($plain)->outstanding_interest);
    }

    public function test_interest_on_the_maturity_day_is_a_full_period(): void
    {
        // Rule: loan 1 Jan, 1 month tenure, maturity 1 Feb: 31 days * 66.6667 = 2066.67 owed on that day.
        $l = $this->loan(['tenure_months' => 1], '2026-01-01');
        $this->assertSame('2026-02-01', $l->maturity_date->toDateString());
        $this->at('2026-02-01');
        $this->assertSame('2066.67', $this->accrue($l)->outstanding_interest);
    }

    public function test_a_month_end_loan_matures_at_the_end_of_the_target_month(): void
    {
        // Rule (same month-end convention interest accrual uses): 31 Jan + 1 month = 28 Feb. addMonths() overflows
        // to 3 Mar, giving the borrower 3 extra days before penalty/forfeiture.
        $l = $this->loan(['tenure_months' => 1], '2026-01-31');
        $this->assertSame('2026-02-28', $l->maturity_date->toDateString());
    }

    public function test_a_leap_day_loan_matures_on_28_february_a_year_later(): void
    {
        // Rule: 29 Feb 2024 + 12 months = 28 Feb 2025 (not 1 Mar 2025).
        $l = $this->loan(['tenure_months' => 12], '2024-02-29');
        $this->assertSame('2025-02-28', $l->maturity_date->toDateString());
    }

    public function test_compound_interest_compounds_at_each_month_end(): void
    {
        // Rule: month 1 (31 days) = 100000*2%/30*31 = 2066.6667; month 2 (28 days) on 102066.6667 = 1905.2444.
        // Total at 1 Mar = 3971.9111 -> 3971.91.
        $l = $this->loan(['interest_type' => 'compound'], '2026-01-01');
        $this->at('2026-03-01');
        $this->assertSame('3971.91', $this->accrue($l)->outstanding_interest);
    }

    public function test_same_day_accrual_twice_changes_nothing(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $a = $this->accrue($l);
        $before = $this->counts($a);
        $b = $this->accrue($a);
        $this->assertSame($a->outstanding_interest, $b->outstanding_interest);
        $this->assertSame($before, $this->counts($b));
    }

    public function test_interest_does_not_depend_on_how_often_the_loan_page_was_opened(): void
    {
        // Rule: 33333 at 1%/month for 90 days = 33333 * 0.01 / 30 * 90 = 999.99, however many times it is accrued.
        // Accruing daily rounds 11.111 to 11.11 each time and drifts to 999.90.
        $p = ['principal_amount' => 33333, 'interest_rate_monthly' => 1];
        $once = $this->loan($p, '2026-01-01');
        $this->at('2026-04-01');
        $this->assertSame('999.99', $this->accrue($once)->outstanding_interest);

        $daily = $this->loan($p, '2026-01-01');
        for ($d = Carbon::parse('2026-01-02'); $d->lte(Carbon::parse('2026-04-01')); $d->addDay()) {
            $this->at($d->toDateString());
            $daily = $this->accrue($daily);
        }
        $this->assertSame('999.99', $daily->outstanding_interest, 'daily accrual must not drift from one-shot accrual');
    }

    public function test_penalty_is_zero_on_the_last_grace_day_and_one_day_the_day_after(): void
    {
        // Rule: maturity 1 Feb, grace 5 => grace ends 6 Feb. Penalty 3%/month on 100000 = 100.00/day, from 7 Feb.
        $l = $this->loan(['tenure_months' => 1, 'grace_period_days' => 5, 'penalty_rate_monthly' => 3]);
        $this->at('2026-02-06');
        $this->assertSame('0.00', $this->accrue($l)->outstanding_penalty);
        $this->at('2026-02-07');
        $this->assertSame('100.00', $this->accrue($l)->outstanding_penalty);
    }

    public function test_penalty_does_not_depend_on_when_accruals_happened(): void
    {
        // Rule: grace ends 6 Feb; by 16 Feb there are 10 penalty days = 1000.00 whether or not accrued on 3 Feb too.
        $a = $this->loan(['tenure_months' => 1, 'grace_period_days' => 5, 'penalty_rate_monthly' => 3]);
        $b = $this->loan(['tenure_months' => 1, 'grace_period_days' => 5, 'penalty_rate_monthly' => 3]);
        $this->at('2026-02-03');
        $this->accrue($a);
        $this->at('2026-02-16');
        $this->assertSame('1000.00', $this->accrue($a)->outstanding_penalty);
        $this->assertSame('1000.00', $this->accrue($b)->outstanding_penalty);
    }

    // ═════════════════ PAYMENT AMOUNTS ═════════════════

    #[DataProvider('badAmounts')]
    public function test_zero_and_negative_amounts_are_refused_and_book_nothing(float $amount): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $before = $this->counts($l);
        foreach (['recordPayment', 'recordInterestPayment'] as $fn) {
            try {
                $this->t(fn () => $this->svc->{$fn}($this->fresh($l), $amount));
                $this->fail("{$fn}({$amount}) must be refused");
            } catch (LogicException $e) {
                $this->assertStringContainsString('greater than zero', $e->getMessage());
            }
        }
        $this->assertSame($before, $this->counts($l));
    }

    public static function badAmounts(): array
    {
        return ['zero' => [0.0], 'negative paisa' => [-0.01], 'negative' => [-500.0], 'huge negative' => [-1e12]];
    }

    public function test_a_payment_below_one_paisa_is_refused_not_booked_as_zero_rupees(): void
    {
        // Rule: money moves in whole paise. 0.004 rounds to 0.00: a payment row and cash entry of INR 0.00 is noise
        // that lets a clerk "record" a payment that changes no balance.
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->accrue($l);
        $before = $this->counts($l);
        try {
            $this->payInt($l, 0.004);
        } catch (LogicException) {
        }
        $this->assertSame($before, $this->counts($l), 'no payment/cash/ledger row for INR 0.00');
    }

    public function test_a_three_decimal_payment_moves_balances_by_exactly_what_was_booked(): void
    {
        // Rule: 1000.005 is not a payable amount; whatever is booked, the stored amount must equal the balance
        // reduction and the sum of its components (no phantom half paisa). Refusing it is also correct.
        $l = $this->loan();
        $this->at('2026-01-31');                       // interest owed 2000.00
        try {
            $this->payInt($l, 1000.005);
        } catch (LogicException) {
            $this->assertBooks($l);

            return;
        }
        $f = $this->fresh($l);
        $booked = (float) DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->where('type', 'interest_payment')->value('amount');
        $this->assertEqualsWithDelta(2000.00 - $booked, (float) $f->outstanding_interest, 0.0001);
        $this->assertBooks($l);
    }

    public function test_paying_exactly_the_dues_closes_the_loan_and_books_every_rupee(): void
    {
        // Rule: 30 days: dues = 100000 + 2000 = 102000.00. Exact payment closes the loan, releases the items.
        $l = $this->loan();
        $this->at('2026-01-31');
        $p = $this->pay($l, 102000.00)->fresh();
        $f = $this->fresh($l);
        $this->assertSame('closed', $f->status);
        $this->assertSame('0.00', $f->outstanding_principal);
        $this->assertEquals(2000.00, $p->interest_component);
        $this->assertEquals(100000.00, $p->principal_component);
        $this->assertSame(102000.00, $this->sum('dhiran_cash_entries', $l->id, 'amount', ['type' => 'in']));
        $this->assertSame(0, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $l->id)->where('status', 'pledged')->count());
        $this->assertBooks($l);
    }

    public function test_one_paisa_over_the_dues_is_refused(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $before = $this->counts($l);
        $this->refused(fn () => $this->pay($l, 102000.01), 'overpayment');
        $this->assertSame($before[0], $this->counts($l)[0]);
        $this->assertSame('active', $this->fresh($l)->status);
    }

    public function test_one_paisa_short_of_the_dues_leaves_the_loan_open_owing_a_paisa(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->pay($l, 101999.99);
        $f = $this->fresh($l);
        $this->assertSame('active', $f->status);
        $this->assertSame('0.01', $f->outstanding_principal);
        $this->assertSame('0.00', $f->outstanding_interest);
        $this->assertBooks($l);
    }

    public function test_interest_payment_exactly_equal_to_interest_leaves_principal_alone(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $p = $this->payInt($l, 2000.00);
        $f = $this->fresh($l);
        $this->assertSame('interest_payment', $p->type);
        $this->assertSame('0.00', $f->outstanding_interest);
        $this->assertSame('100000.00', $f->outstanding_principal);
        $this->assertSame('active', $f->status);
        $this->assertBooks($l);
    }

    public function test_interest_payment_one_paisa_over_the_interest_is_refused(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->refused(fn () => $this->payInt($l, 2000.01), 'more than interest owed');
    }

    public function test_interest_payment_on_the_day_the_loan_is_made_has_nothing_to_pay(): void
    {
        // Rule: no interest accrued on day 0, so even INR 1 of interest exceeds what is owed.
        $l = $this->loan();
        $before = $this->counts($l);
        $this->refused(fn () => $this->payInt($l, 1.00), 'nothing owed on day 0');
        $this->assertSame($before, $this->counts($l));
    }

    public function test_payments_apply_penalty_then_interest_then_principal(): void
    {
        // Loan 1 Jan, 1 month, grace 0, penalty 3%. On 1 Mar: interest 59d = 3933.33; penalty 28d (1 Feb->1 Mar) = 2800.00.
        $l = $this->loan(['tenure_months' => 1, 'grace_period_days' => 0, 'penalty_rate_monthly' => 3]);
        $this->at('2026-03-01');

        $p1 = $this->pay($l, 2000)->fresh();     // all into penalty
        $this->assertEquals([2000.00, 0.0, 0.0], [(float) $p1->penalty_component, (float) $p1->interest_component, (float) $p1->principal_component]);
        $this->assertSame('penalty_payment', $p1->type);

        $p2 = $this->pay($l, 1000)->fresh();     // 800 penalty left, then 200 interest
        $this->assertEquals([800.00, 200.00, 0.0], [(float) $p2->penalty_component, (float) $p2->interest_component, (float) $p2->principal_component]);
        $this->assertSame('interest_payment', $p2->type);

        $p3 = $this->pay($l, 4000)->fresh();     // 3733.33 interest, 266.67 principal
        $this->assertEquals([0.0, 3733.33, 266.67], [(float) $p3->penalty_component, (float) $p3->interest_component, (float) $p3->principal_component]);
        $this->assertSame('principal_repayment', $p3->type);

        $f = $this->fresh($l);
        $this->assertSame('99733.33', $f->outstanding_principal);
        $this->assertSame('0.00', $f->outstanding_interest);
        $this->assertSame('0.00', $f->outstanding_penalty);
        $this->assertBooks($l);
    }

    public function test_many_small_payments_keep_every_book_in_balance(): void
    {
        // 45 days of mixed odd-sized repayments: nothing negative, components = amounts, cash = payments, ledger = loan.
        $l = $this->loan(['principal_amount' => 77777.77, 'interest_rate_monthly' => 2.35, 'penalty_rate_monthly' => 1.5, 'tenure_months' => 1, 'grace_period_days' => 3]);
        $amounts = [123.45, 0.37, 999.99, 1.01, 250.5, 33.33, 0.01];
        for ($i = 1; $i <= 45; $i++) {
            $this->at(Carbon::parse('2026-01-01')->addDays($i)->toDateString());
            $a = $amounts[$i % count($amounts)];
            try {
                $i % 3 === 0 ? $this->payInt($l, $a) : $this->pay($l, $a);
            } catch (LogicException) {
                // an interest payment above the interest owed is legitimately refused; books must still balance
            }
            $this->assertBooks($l);
        }
        $this->assertSame('active', $this->fresh($l)->status);
    }

    public function test_a_hundred_one_paisa_payments_reduce_exactly_one_rupee(): void
    {
        // Rule: 100 x INR 0.01 against INR 66.67 of interest (1 day on 100000 at 2%) takes exactly 1.00 off.
        $l = $this->loan();
        $this->at('2026-01-02');
        for ($i = 0; $i < 100; $i++) {
            $this->payInt($l, 0.01);
        }
        $f = $this->fresh($l);
        $this->assertSame('65.67', $f->outstanding_interest);
        $this->assertSame('1.00', $f->total_interest_collected);
        $this->assertBooks($l);
    }

    // ═════════════════ MIDNIGHT / TIMEZONE ═════════════════

    public function test_a_payment_just_after_ist_midnight_is_dated_the_new_ist_day(): void
    {
        // Rule: 00:05 IST on 1 Mar is already 1 Mar for the shop (it is still 28 Feb in UTC).
        $l = $this->loan();
        $this->at('2026-03-01', '00:05');
        $this->payInt($l, 100);
        $this->at('2026-03-02', '23:59');
        $this->payInt($l, 100);
        $dates = DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->where('type', 'interest_payment')->orderBy('id')->pluck('payment_date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $this->assertSame(['2026-03-01', '2026-03-02'], $dates);
    }

    public function test_interest_ticks_over_at_ist_midnight(): void
    {
        // Rule: 23:59:59 on 31 Jan = 30 days (2000.00); 00:00:01 on 1 Feb = 31 days (2066.67).
        $l = $this->loan();
        $this->at('2026-01-31', '23:59:59');
        $this->assertSame('2000.00', $this->accrue($l)->outstanding_interest);
        $this->at('2026-02-01', '00:00:01');
        $this->assertSame('2066.67', $this->accrue($l)->outstanding_interest);
    }

    // ═════════════════ MINIMUM INTEREST / LOCK ═════════════════

    public function test_pre_close_on_day_zero_collects_the_minimum_interest(): void
    {
        // Rule: min 3 months at 2% on 100000 = 6000.00 even if closed the same day.
        $l = $this->loan(['min_interest_months' => 3]);
        $p = $this->t(fn () => $this->svc->preCloseLoan($this->fresh($l)))->fresh();
        $this->assertEquals(106000.00, $p->amount);
        $this->assertEquals(6000.00, $p->interest_component);
        $this->assertSame('closed', $this->fresh($l)->status);
        $this->assertBooks($l);
    }

    public function test_pre_close_after_the_minimum_pays_only_real_interest(): void
    {
        // Rule: 100 days accrue 6666.67 (> the 6000 minimum), so no shortfall is added.
        $l = $this->loan(['min_interest_months' => 3]);
        $this->at('2026-04-11');
        $p = $this->t(fn () => $this->svc->preCloseLoan($this->fresh($l)))->fresh();
        $this->assertEquals(6666.67, $p->interest_component);
        $this->assertEquals(106666.67, $p->amount);
        $this->assertBooks($l);
    }

    public function test_interest_already_collected_counts_towards_the_minimum(): void
    {
        // Rule: 2000.00 paid at day 30; minimum 6000 so pre-close tops up the other 4000 (total 6000, not 8000).
        $l = $this->loan(['min_interest_months' => 3]);
        $this->at('2026-01-31');
        $this->payInt($l, 2000);
        $p = $this->t(fn () => $this->svc->preCloseLoan($this->fresh($l)))->fresh();
        $this->assertEquals(104000.00, $p->amount);
        $this->assertSame('6000.00', $this->fresh($l)->total_interest_collected);
        $this->assertBooks($l);
    }

    public function test_pre_close_is_refused_the_day_before_the_lock_ends_and_allowed_on_it(): void
    {
        // Rule: 3-month lock from 15 Jan ends 15 Apr: 14 Apr refused, 15 Apr allowed.
        $l = $this->loan(['min_lock_months' => 3], '2026-01-15');
        $this->at('2026-04-14');
        $this->refused(fn () => $this->t(fn () => $this->svc->preCloseLoan($this->fresh($l))), 'lock period');
        $this->at('2026-04-15');
        $this->t(fn () => $this->svc->preCloseLoan($this->fresh($l)));
        $this->assertSame('closed', $this->fresh($l)->status);
    }

    public function test_repaying_everything_cannot_dodge_the_lock_period(): void
    {
        // Rule: a loan in its minimum lock period cannot be closed early. recordPayment() auto-closes on a zero
        // balance without the lock check preCloseLoan() makes, so a full repay on day 0 closes a locked loan.
        $l = $this->loan(['min_lock_months' => 3]);
        try {
            $this->pay($l, 100000);
        } catch (LogicException) {
        }
        $this->assertSame('active', $this->fresh($l)->status, 'loan in lock period was closed by a full repayment');
    }

    public function test_repaying_everything_cannot_dodge_the_minimum_interest(): void
    {
        // Rule: closing collects at least min_interest_months of interest (6000 here). A full repay on day 0 via
        // recordPayment() closes the loan having collected INR 0 interest.
        $l = $this->loan(['min_interest_months' => 3]);
        try {
            $this->pay($l, 100000);
        } catch (LogicException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertGreaterThanOrEqual(6000.00, (float) $this->fresh($l)->total_interest_collected, 'closed with less than the minimum interest');
    }

    public function test_releasing_the_last_item_cannot_dodge_the_minimum_interest(): void
    {
        // Rule: same as above through the item-release path.
        $l = $this->loan(['min_interest_months' => 3]);
        $item = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->first();
        try {
            $this->t(fn () => $this->svc->releaseItem($this->fresh($l), $item, 100000, 'cash', ''));
        } catch (LogicException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertGreaterThanOrEqual(6000.00, (float) $this->fresh($l)->total_interest_collected, 'closed with less than the minimum interest');
    }

    // ═════════════════ RENEWAL ═════════════════

    public function test_renewing_a_loan_with_dues_collects_exactly_interest_and_penalty(): void
    {
        // 1 Mar dues = interest 3933.33 + penalty 2800.00 = 6733.33; principal carries over.
        $l = $this->loan(['tenure_months' => 1, 'grace_period_days' => 0, 'penalty_rate_monthly' => 3]);
        $this->at('2026-03-01');
        $new = $this->t(fn () => $this->svc->renewLoan($this->fresh($l), 6, 2.5));

        $old = $this->fresh($l);
        $this->assertSame('renewed', $old->status);
        $this->assertSame('0.00', $old->outstanding_principal);
        $r = DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->where('type', 'renewal_interest')->first();
        $this->assertEquals([6733.33, 3933.33, 2800.00, 0.0], [(float) $r->amount, (float) $r->interest_component, (float) $r->penalty_component, (float) $r->principal_component]);

        $this->assertSame('100000.00', $new->outstanding_principal);
        $this->assertSame('0.00', $new->outstanding_interest);
        $this->assertSame('2026-03-01', $new->loan_date->toDateString());
        $this->assertSame('2026-09-01', $new->maturity_date->toDateString());
        $this->assertSame('2.50', $new->interest_rate_monthly);
        $this->assertSame($l->id, (int) $new->renewed_from_id);
        $this->assertSame(1, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $new->id)->where('status', 'pledged')->count());
        $this->assertSame(0, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $l->id)->count());
        $this->assertSame(100000.00, $this->sum('dhiran_cash_entries', $l->id, 'amount', ['type' => 'out']));
        $this->assertSame(6733.33, $this->sum('dhiran_cash_entries', $l->id, 'amount', ['type' => 'in']));
        $this->assertSame(0, DB::table('dhiran_payments')->where('dhiran_loan_id', $new->id)->count(), 'no new disbursement');
        $this->assertBooks($l);
    }

    public function test_renewal_enforces_the_minimum_interest_on_day_zero(): void
    {
        // Rule: min 3 months = 6000.00 owed even on a same-day renewal.
        $l = $this->loan(['min_interest_months' => 3]);
        $this->t(fn () => $this->svc->renewLoan($this->fresh($l)));
        $this->assertSame(6000.00, $this->sum('dhiran_payments', $l->id, 'amount', ['type' => 'renewal_interest']));
    }

    public function test_renewal_after_part_repayment_carries_only_the_principal_still_owed(): void
    {
        // Rule: 40000 repaid on day 0 -> renewed loan principal is 60000.00.
        $l = $this->loan();
        $this->pay($l, 40000);
        $new = $this->t(fn () => $this->svc->renewLoan($this->fresh($l)));
        $this->assertSame('60000.00', $new->principal_amount);
        $this->assertSame('60000.00', $new->outstanding_principal);
        $this->assertBooks($l);
    }

    public function test_renewal_below_the_minimum_loan_amount_is_refused_atomically(): void
    {
        // Rule: min loan 5000; 2500 left (after 99500 paid: 2000 interest + 97500 principal) cannot renew, and the
        // interest payment made inside the renewal is rolled back with it.
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->pay($l, 99500);
        $this->assertSame('2500.00', $this->fresh($l)->outstanding_principal);
        DhiranSettings::getForShop($this->shop->id)->update(['min_loan_amount' => 5000]);
        $this->at('2026-02-10');
        $this->accrue($l);
        $before = $this->counts($l);
        $this->refused(fn () => $this->t(fn () => $this->svc->renewLoan($this->fresh($l))), 'below minimum');
        $this->assertSame($before, $this->counts($l));
        $this->assertSame('active', $this->fresh($l)->status);
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    public function test_service_refuses_a_negative_interest_rate_on_renewal(): void
    {
        // Rule: a negative rate would make the shop pay the borrower interest. The controller caps min:0 but the
        // service accepts it.
        $l = $this->loan();
        $this->refused(fn () => $this->t(fn () => $this->svc->renewLoan($this->fresh($l), null, -2.0)), 'negative rate');
    }

    public function test_service_refuses_zero_tenure_and_negative_rate_on_a_new_loan(): void
    {
        // Rule: tenure >= 1 month and rate >= 0. Tenure 0 makes the loan overdue the moment it is made.
        $this->refused(fn () => $this->loan(['tenure_months' => 0, 'interest_rate_monthly' => -3]), 'bad terms');
    }

    public function test_service_refuses_gold_purity_above_24_karat(): void
    {
        // Rule: 30 "karat" gold inflates fine weight (x1.25) and so the loan ceiling: 100 g @6000 => 750000 market
        // => 562500 cap, so a 500000 loan passes where 24k would cap at 450000. HTTP has an allow-list; the shared
        // service does not.
        $this->refused(fn () => $this->loan(['principal_amount' => 500000], '2026-01-01', [$this->gold(100, 30)]), 'purity 30');
    }

    // ═════════════════ ILLEGAL STATE TRANSITIONS ═════════════════

    private function inState(string $state): DhiranLoan
    {
        switch ($state) {
            case 'pending_evidence':
                $l = $this->loan(['status' => 'pending_evidence']);
                break;
            case 'closed':
                $l = $this->loan();
                $this->t(fn () => $this->svc->preCloseLoan($this->fresh($l)));
                break;
            case 'renewed':
                $l = $this->loan();
                $this->t(fn () => $this->svc->renewLoan($this->fresh($l)));
                break;
            case 'cancelled':
                $l = $this->loan(['status' => 'pending_evidence']);
                $this->t(fn () => $this->svc->cancelPendingLoan($this->fresh($l), 'test'));
                break;
            case 'forfeited':
                $l = $this->loan(['tenure_months' => 1, 'grace_period_days' => 0]);
                $this->at('2026-03-01');
                $this->t(fn () => $this->svc->sendForfeitureNotice($this->fresh($l)));
                $this->at('2026-04-05');
                $this->t(fn () => $this->svc->executeForfeit($this->fresh($l)));
                break;
            default:
                $l = $this->loan();
        }
        $this->at('2026-04-10');

        return $this->fresh($l);
    }

    public static function illegal(): array
    {
        $acts = ['pay', 'payInterest', 'preClose', 'renew', 'close', 'release', 'notice', 'forfeit', 'cancel', 'activate'];
        $rows = [];
        foreach (['closed', 'renewed', 'cancelled', 'forfeited', 'pending_evidence'] as $s) {
            foreach ($acts as $a) {
                if ($s === 'pending_evidence' && $a === 'cancel') {
                    continue; // the one legal action: cancel with reversal
                }
                $rows["{$s}/{$a}"] = [$s, $a];
            }
        }
        $rows['active/cancel'] = ['active', 'cancel'];
        $rows['active/activate'] = ['active', 'activate'];

        return $rows;
    }

    #[DataProvider('illegal')]
    public function test_an_action_a_loan_state_does_not_allow_is_refused_and_changes_nothing(string $state, string $action): void
    {
        $l = $this->inState($state);
        $item = DhiranLoanItem::withoutGlobalScopes()->where('shop_id', $this->shop->id)->orderByDesc('id')->first();
        $rowBefore = (array) DB::table('dhiran_loans')->find($l->id);
        unset($rowBefore['updated_at']);
        $counts = $this->counts($l);
        $itemsBefore = DB::table('dhiran_loan_items')->orderBy('id')->get(['id', 'status', 'dhiran_loan_id'])->toArray();

        $call = match ($action) {
            'pay' => fn () => $this->svc->recordPayment($l, 1.00),
            'payInterest' => fn () => $this->svc->recordInterestPayment($l, 1.00),
            'preClose' => fn () => $this->svc->preCloseLoan($l),
            'renew' => fn () => $this->svc->renewLoan($l),
            'close' => fn () => $this->svc->closeLoan($l),
            'release' => fn () => $this->svc->releaseItem($l, $item, 999999, 'cash', ''),
            'notice' => fn () => $this->svc->sendForfeitureNotice($l),
            'forfeit' => fn () => $this->svc->executeForfeit($l),
            'cancel' => fn () => $this->svc->cancelPendingLoan($l, 'again'),
            'activate' => fn () => $this->svc->activateLoan($l),
        };

        $this->refused(fn () => $this->t($call), "{$action} on a {$state} loan");

        $rowAfter = (array) DB::table('dhiran_loans')->find($l->id);
        unset($rowAfter['updated_at']);
        $this->assertSame($rowBefore, $rowAfter, 'loan row untouched');
        $this->assertSame($counts, $this->counts($l), 'no payment/cash/ledger rows added');
        $this->assertEquals($itemsBefore, DB::table('dhiran_loan_items')->orderBy('id')->get(['id', 'status', 'dhiran_loan_id'])->toArray(), 'items untouched');
    }

    public function test_cancelling_a_pending_loan_with_a_fee_nets_the_cash_book_to_zero(): void
    {
        // Rule: payout 100000 out, fee 500 in, then reversal: 100000 in, 500 out. Net 0, ledger 0, items back.
        $l = $this->loan(['status' => 'pending_evidence', 'processing_fee' => 500]);
        $this->t(fn () => $this->svc->cancelPendingLoan($this->fresh($l), 'wrong customer'));
        $this->assertSame('cancelled', $this->fresh($l)->status);
        $this->assertSame(100500.00, $this->sum('dhiran_cash_entries', $l->id, 'amount', ['type' => 'in']));
        $this->assertSame(100500.00, $this->sum('dhiran_cash_entries', $l->id, 'amount', ['type' => 'out']));
        $this->assertSame(0, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $l->id)->where('status', 'pledged')->count());
        $last = DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $l->id)->orderByDesc('id')->first();
        $this->assertEquals(0.0, (float) $last->balance_after);
        $this->assertBooks($l);
    }

    // ═════════════════ STALE-COPY / CONCURRENT-STYLE REPEATS ═════════════════

    public function test_two_stale_copies_cannot_both_pay_off_the_loan(): void
    {
        $l = $this->loan();
        $a = $this->fresh($l);
        $b = $this->fresh($l);
        $this->t(fn () => $this->svc->recordPayment($a, 100000));
        $this->refused(fn () => $this->t(fn () => $this->svc->recordPayment($b, 100000)), 'second stale payoff');
        $this->assertSame(1, DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->where('direction', 'in')->count());
        $this->assertBooks($l);
    }

    public function test_a_second_stale_part_payment_is_judged_against_the_balance_left(): void
    {
        // Rule: 60000 paid; a stale tab then tries another 60000 of the 40000 left: refused, balance stays 40000.00.
        $l = $this->loan();
        $a = $this->fresh($l);
        $b = $this->fresh($l);
        $this->t(fn () => $this->svc->recordPayment($a, 60000));
        $this->refused(fn () => $this->t(fn () => $this->svc->recordPayment($b, 60000)), 'overpayment from stale copy');
        $this->assertSame('40000.00', $this->fresh($l)->outstanding_principal);
        $this->assertBooks($l);
    }

    public function test_two_stale_copies_cannot_both_renew(): void
    {
        $l = $this->loan();
        $a = $this->fresh($l);
        $b = $this->fresh($l);
        $this->t(fn () => $this->svc->renewLoan($a));
        $this->refused(fn () => $this->t(fn () => $this->svc->renewLoan($b)), 'second renewal');
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('renewed_from_id', $l->id)->count());
    }

    public function test_a_pledged_item_cannot_be_released_twice_from_a_stale_item_copy(): void
    {
        // Rule: an already-released item can't be released (and charged) again. releaseItem() checks $item->status
        // on the object it was handed, then re-fetches the row under lock without re-checking, so a second tab
        // holding the old item charges again.
        $l = $this->loan(['principal_amount' => 10000], '2026-01-01', [$this->gold(10, 24, 6000, 'A'), $this->gold(10, 24, 6000, 'B')]);
        $items = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->orderBy('id')->get();
        $stale = DhiranLoanItem::withoutGlobalScopes()->find($items[0]->id);
        $this->t(fn () => $this->svc->releaseItem($this->fresh($l), $items[0], 5000, 'cash', ''));
        $after = $this->counts($l);
        try {
            $this->t(fn () => $this->svc->releaseItem($this->fresh($l), $stale, 10000, 'cash', ''));
        } catch (LogicException) {
        }
        $this->assertSame($after, $this->counts($l), 'second release of the same item booked another payment');
    }

    // ═════════════════ MULTI-ITEM (SILVER + GOLD) ═════════════════

    private function mixed(float $principal = 46800): DhiranLoan
    {
        // Gold 10 g 22k @6000 = 10*22/24*6000 = 55000.00; silver 100 g 925 @80 = 100*0.925*80 = 7400.00.
        // Collateral 62400.00; LTV 75% => 46800.00 (gold 41250.00 + silver 5550.00).
        return $this->loan(['principal_amount' => $principal, 'silver_rate_on_date' => 80], '2026-01-01', [
            $this->gold(10, 22, 6000, 'Gold chain'),
            ['description' => 'Silver anklet', 'metal_type' => 'silver', 'purity' => 925, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 80],
        ]);
    }

    public function test_mixed_collateral_values_each_metal_on_its_own_scale(): void
    {
        $l = $this->mixed();
        $this->assertSame('62400.00', $l->total_collateral_value);
        $this->assertSame('101.666667', $l->total_fine_weight);          // 9.166667 + 92.5
        $items = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->orderBy('id')->get();
        $this->assertEquals([55000.00, 41250.00], [(float) $items[0]->market_value, (float) $items[0]->loan_value]);
        $this->assertEquals([7400.00, 5550.00], [(float) $items[1]->market_value, (float) $items[1]->loan_value]);
        $this->assertEquals(9.166667, (float) $items[0]->fine_weight);
        $this->assertEquals(92.5, (float) $items[1]->fine_weight);
    }

    public function test_principal_one_paisa_over_the_ltv_ceiling_is_refused(): void
    {
        $this->refused(fn () => $this->mixed(46800.01), 'over LTV ceiling');
    }

    public function test_releasing_items_one_by_one_charges_each_its_value_share_and_closes_on_the_last(): void
    {
        $l = $this->mixed();
        $ids = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->orderBy('id')->pluck('id');
        $item = fn ($i) => DhiranLoanItem::withoutGlobalScopes()->find($ids[$i]);

        // Gold is 55000/62400 of the pledged value => 41250.00 of the 46800.00 principal.
        $this->assertSame(41250.00, $this->t(fn () => $this->svc->releaseMinimum($this->fresh($l), $item(0))));
        $this->refused(fn () => $this->t(fn () => $this->svc->releaseItem($this->fresh($l), $item(0), 41249.99, 'cash', '')), 'one paisa short');
        $this->t(fn () => $this->svc->releaseItem($this->fresh($l), $item(0), 41250.00, 'cash', ''));
        $f = $this->fresh($l);
        $this->assertSame('active', $f->status);
        $this->assertSame('5550.00', $f->outstanding_principal);
        $this->assertSame('released', $item(0)->status);

        // The silver is now the only pledged value: it carries the whole 5550.00 left.
        $this->assertSame(5550.00, $this->t(fn () => $this->svc->releaseMinimum($this->fresh($l), $item(1))));
        $this->t(fn () => $this->svc->releaseItem($this->fresh($l), $item(1), 5550.00, 'cash', ''));
        $this->assertSame('closed', $this->fresh($l)->status);
        $this->assertSame(46800.00, $this->sum('dhiran_payments', $l->id, 'principal_component', ['direction' => 'in']));
        $this->assertBooks($l);
    }

    public function test_three_equal_items_release_without_rounding_drift(): void
    {
        // Rule: 10000 over three equal items: shares round but the principal collected is exactly 10000.00 and the
        // last release closes the loan at exactly 0.
        $items = [$this->gold(6, 24, 1000, 'a'), $this->gold(6, 24, 1000, 'b'), $this->gold(6, 24, 1000, 'c')];
        $l = $this->loan(['principal_amount' => 10000, 'gold_rate_on_date' => 1000], '2026-01-01', $items);
        $ids = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $req = $this->t(fn () => $this->svc->releaseMinimum($this->fresh($l), DhiranLoanItem::withoutGlobalScopes()->find($id)));
            $this->t(fn () => $this->svc->releaseItem($this->fresh($l), DhiranLoanItem::withoutGlobalScopes()->find($id), $req, 'cash', ''));
            $this->assertBooks($l);
        }
        $f = $this->fresh($l);
        $this->assertSame('closed', $f->status);
        $this->assertSame('0.00', $f->outstanding_principal);
        $this->assertSame(10000.00, $this->sum('dhiran_payments', $l->id, 'principal_component', ['direction' => 'in']));
    }

    public function test_a_release_offered_far_more_than_owed_books_only_what_is_owed(): void
    {
        // Rule: the cash book records what the loan owed (cap), never the 1e12 that was offered.
        $l = $this->loan(['principal_amount' => 10000], '2026-01-01', [$this->gold(10, 24, 6000)]);
        $item = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->first();
        $p = $this->t(fn () => $this->svc->releaseItem($this->fresh($l), $item, 1e12, 'cash', ''));
        $this->assertEquals(10000.00, $p->fresh()->amount);
        $this->assertSame(10000.00, $this->sum('dhiran_cash_entries', $l->id, 'amount', ['type' => 'in']));
        $this->assertSame('closed', $this->fresh($l)->status);
    }

    // ═════════════════ FORFEITURE ═════════════════

    public function test_forfeiture_follows_grace_then_notice_then_deadline_to_the_day(): void
    {
        // Loan 1 Jan, 1 month, grace 10: maturity 1 Feb, grace ends 11 Feb. Notice only from 12 Feb; deadline 30 days
        // later = 14 Mar; forfeiture refused 13 Mar, allowed 14 Mar.
        $l = $this->loan(['tenure_months' => 1, 'grace_period_days' => 10, 'penalty_rate_monthly' => 3]);
        $notice = fn () => $this->t(fn () => $this->svc->sendForfeitureNotice($this->fresh($l)));
        $forfeit = fn () => $this->t(fn () => $this->svc->executeForfeit($this->fresh($l)));

        $this->at('2026-02-11');
        $this->refused($notice, 'still in grace');
        $this->at('2026-02-12');
        $notice();
        $this->assertSame('2026-03-14', Carbon::parse($this->fresh($l)->forfeiture_deadline)->toDateString());
        $this->refused($notice, 'notice already sent');

        $this->at('2026-03-13');
        $this->refused($forfeit, 'deadline not reached');
        $this->at('2026-03-14');
        $before = $this->fresh($l);
        $sum = (float) $before->outstanding_principal + (float) $before->outstanding_interest + (float) $before->outstanding_penalty;
        $forfeit();

        $f = $this->fresh($l);
        $this->assertSame('forfeited', $f->status);
        $this->assertSame(['0.00', '0.00', '0.00'], [$f->outstanding_principal, $f->outstanding_interest, $f->outstanding_penalty]);
        $this->assertSame(0, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $l->id)->where('status', 'pledged')->count());
        $this->assertSame(1, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $l->id)->where('status', 'forfeited')->count());
        $this->assertEqualsWithDelta($sum, (float) DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $l->id)->where('entry_type', 'forfeiture')->value('amount'), 0.0001);
        $this->refused(fn () => $this->pay($l, 1), 'forfeited loans take no payment');
        $this->refused($forfeit, 'cannot forfeit twice');
    }

    public function test_settling_the_loan_after_the_notice_stops_forfeiture(): void
    {
        $l = $this->loan(['tenure_months' => 1, 'grace_period_days' => 0]);
        $this->at('2026-03-01');
        $this->t(fn () => $this->svc->sendForfeitureNotice($this->fresh($l)));
        $this->pay($l, $this->fresh($l)->totalOutstanding());
        $this->assertSame('closed', $this->fresh($l)->status);
        $this->at('2026-05-01');
        $this->refused(fn () => $this->t(fn () => $this->svc->executeForfeit($this->fresh($l))), 'closed loan');
    }

    // ═════════════════ EVIDENCE GATE ═════════════════

    private function attach(string $ownerType, int $ownerId, string $docType): void
    {
        $this->t(fn () => DhiranAttachment::create(['owner_type' => $ownerType, 'owner_id' => $ownerId, 'document_type' => $docType,
            'file_disk' => 'local', 'file_path' => 'x/' . Str::random(8) . '.jpg', 'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg',
            'size_bytes' => 10, 'uploaded_by' => $this->owner->id]));
    }

    public function test_evidence_for_another_loan_or_the_wrong_kind_does_not_activate_this_one(): void
    {
        $other = $this->t(fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Oth', 'last_name' => 'Er', 'mobile' => '9815570002']));
        $a = $this->loan(['status' => 'pending_evidence']);
        $b = $this->t(fn () => $this->svc->createLoan($this->shop, $other, [$this->gold()], ['principal_amount' => 10000, 'gold_rate_on_date' => 6000, 'status' => 'pending_evidence', 'loan_date' => '2026-01-01']));

        $this->attach(DhiranAttachment::OWNER_LOAN, $b->id, 'item_photo');          // complete evidence, but on B
        $this->attach(DhiranAttachment::OWNER_LOAN, $b->id, 'id_proof_front');
        $this->attach(DhiranAttachment::OWNER_CUSTOMER, $a->id, 'item_photo');      // A's numeric id, wrong owner kind
        $this->attach(DhiranAttachment::OWNER_LOAN, $a->id, 'address_proof');       // not an ID proof

        $this->assertSame(['item_photo' => false, 'id_proof' => false, 'ok' => false], $this->t(fn () => $this->svc->evidenceStatus($this->fresh($a)->load('items'))));
        $this->refused(fn () => $this->t(fn () => $this->svc->activateLoan($this->fresh($a))), 'no evidence for A');
        $this->assertSame('pending_evidence', $this->fresh($a)->status);

        $this->attach(DhiranAttachment::OWNER_LOAN, $a->id, 'item_photo');
        $this->refused(fn () => $this->t(fn () => $this->svc->activateLoan($this->fresh($a))), 'photo but no ID proof');

        $this->attach(DhiranAttachment::OWNER_CUSTOMER, $this->customer->id, 'id_proof_back');
        $this->t(fn () => $this->svc->activateLoan($this->fresh($a)));
        $this->assertSame('active', $this->fresh($a)->status);
        $this->assertSame('pending_evidence', $this->fresh($b)->status, 'B stays gated');
    }

    // ═════════════════ OLD LOANS (BROUGHT FORWARD) ═════════════════

    private function oldForm(array $over = []): array
    {
        return $over + [
            'customer_id' => '',
            'borrower' => ['first_name' => 'Paper', 'last_name' => 'Loan', 'mobile' => '9819990011', 'address' => 'Old Market', 'pan' => ''],
            'paper_loan_number' => 'P-' . (++$this->seq),
            'loan_date' => '2025-06-10', 'principal_amount' => 100000, 'principal_owed' => 60000,
            'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 12,
            'interest_paid_until' => '2026-02-28', 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]],
        ];
    }

    private function http(string $path, array $data)
    {
        return $this->t(fn () => $this->actingAs($this->owner)->post(self::HOST . $path, $data + ['idempotency_key' => Str::uuid()->toString()]));
    }

    private function oldLoan(string $ticket): DhiranLoan
    {
        return DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->where('brought_forward', true)->where('loan_number', $ticket)->firstOrFail();
    }

    public function test_an_old_loan_earns_interest_from_the_paid_up_date_to_today(): void
    {
        // Today 31 Mar; interest paid to 28 Feb = 31 days on the 60000 still owed: 60000*2%/30*31 = 1240.00.
        $this->at('2026-03-31');
        $this->http('/dhiran/old-loans', $this->oldForm(['paper_loan_number' => 'T1']))->assertSessionHasNoErrors();
        $l = $this->oldLoan('T1');
        $this->assertSame('60000.00', $l->outstanding_principal);
        $this->assertSame('40000.00', $l->total_principal_collected);
        $this->assertSame('1240.00', $this->accrue($l)->outstanding_interest);
        $this->assertSame(0, DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->count(), 'no payout/fee on an old loan');
        $this->assertSame(0, DB::table('dhiran_cash_entries')->where('dhiran_loan_id', $l->id)->count());
    }

    public function test_an_old_loan_paid_up_to_today_owes_no_interest_yet(): void
    {
        $this->at('2026-03-31');
        $this->http('/dhiran/old-loans', $this->oldForm(['paper_loan_number' => 'T2', 'interest_paid_until' => '2026-03-31']));
        $l = $this->accrue($this->oldLoan('T2'));
        $this->assertSame('0.00', $l->outstanding_interest);
        $this->assertSame(0, DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $l->id)->where('entry_type', 'interest_accrual')->count());
    }

    public function test_an_old_loan_with_the_shops_own_unpaid_interest_keeps_that_exact_figure(): void
    {
        // Rule: the shop's 1500.50 is taken as is; interest runs from the entry day: next day +60000*2%/30 = 40.00.
        $this->at('2026-03-31');
        $this->http('/dhiran/old-loans', $this->oldForm(['paper_loan_number' => 'T3', 'unpaid_interest' => '1500.50']));
        $l = $this->accrue($this->oldLoan('T3'));
        $this->assertSame('1500.50', $l->outstanding_interest);
        $this->at('2026-04-01');
        $this->assertSame('1540.50', $this->accrue($l)->outstanding_interest);
    }

    public function test_an_old_overdue_loan_charges_penalty_only_for_days_since_the_paid_up_date(): void
    {
        // Loan 15 Jan 2025, 12 months: maturity 15 Jan 2026, grace 30 => 14 Feb. Paid up 1 Mar, today 31 Mar:
        // interest 30d = 60000*2%/30*30 = 1200.00; penalty 3% 30d = 60000*3%/30*30 = 1800.00.
        $this->at('2026-03-31');
        $this->http('/dhiran/old-loans', $this->oldForm(['paper_loan_number' => 'T4', 'loan_date' => '2025-01-15', 'interest_paid_until' => '2026-03-01',
            'penalty_rate_monthly' => 3, 'grace_period_days' => 30]));
        $l = $this->accrue($this->oldLoan('T4'));
        $this->assertSame('1200.00', $l->outstanding_interest);
        $this->assertSame('1800.00', $l->outstanding_penalty);
    }

    public function test_an_old_loan_closed_the_day_it_is_brought_in_owes_only_what_the_paper_says(): void
    {
        // Rule: the paper loan's minimum interest was settled on paper, so pre-closing today (interest paid up to
        // today) collects just the 60000 owed.
        $this->at('2026-03-31');
        $this->http('/dhiran/old-loans', $this->oldForm(['paper_loan_number' => 'T5', 'interest_paid_until' => '2026-03-31']));
        $l = $this->oldLoan('T5');
        $p = $this->t(fn () => $this->svc->preCloseLoan($this->fresh($l)))->fresh();
        $this->assertEquals(60000.00, $p->amount);
        $this->assertEquals(0.0, (float) $p->interest_component);
        $this->assertBooks($l);
    }

    public function test_an_old_loan_from_29_february_matures_on_28_february(): void
    {
        $this->at('2026-03-31');
        $this->http('/dhiran/old-loans', $this->oldForm(['paper_loan_number' => 'T6', 'loan_date' => '2024-02-29', 'tenure_months' => 12, 'interest_paid_until' => '2026-03-01']));
        $this->assertSame('2025-02-28', $this->oldLoan('T6')->maturity_date->toDateString());
    }

    #[DataProvider('badOld')]
    public function test_impossible_old_loans_are_refused_and_nothing_is_saved(array $over): void
    {
        $this->at('2026-03-31');
        $this->http('/dhiran/old-loans', $this->oldForm($over))->assertSessionHasErrors();
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    public static function badOld(): array
    {
        return [
            'paid-up date before the loan date' => [['loan_date' => '2026-01-10', 'interest_paid_until' => '2026-01-09']],
            'paid-up date tomorrow'             => [['interest_paid_until' => '2026-04-01']],
            'loan date tomorrow'                => [['loan_date' => '2026-04-01', 'interest_paid_until' => '2026-04-01']],
            'owed more than was lent'           => [['principal_amount' => 50000, 'principal_owed' => 50000.01]],
            'owing nothing'                     => [['principal_owed' => 0]],
            'owing less than a paisa'           => [['principal_owed' => 0.004]],
            'negative unpaid interest'          => [['unpaid_interest' => -1]],
            'negative rate'                     => [['interest_rate_monthly' => -2]],
            'rate above the cap'                => [['interest_rate_monthly' => 10.01]],
            'zero tenure'                       => [['tenure_months' => 0]],
            'no items'                          => [['items' => []]],
        ];
    }

    public function test_the_same_paper_ticket_cannot_be_brought_in_twice(): void
    {
        $this->at('2026-03-31');
        $this->http('/dhiran/old-loans', $this->oldForm(['paper_loan_number' => 'DUP']))->assertSessionHasNoErrors();
        $this->http('/dhiran/old-loans', $this->oldForm(['paper_loan_number' => 'DUP', 'borrower' => ['first_name' => 'X', 'mobile' => '9819990012']]))->assertSessionHasErrors('paper_loan_number');
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    // ═════════════════ HTTP: VALIDATION, SUBMIT-ONCE, COLLECT-ONLY, TENANCY ═════════════════

    private function paymentsIn(DhiranLoan $l): int
    {
        return DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->where('direction', 'in')->count();
    }

    #[DataProvider('badHttpAmounts')]
    public function test_the_repay_form_refuses_unusable_amounts_without_booking(string $amount): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $r = $this->http("/dhiran/loans/{$l->id}/repay", ['amount' => $amount]);
        $this->assertTrue($r->isRedirect());
        $this->assertSame(0, $this->paymentsIn($l));
        $this->assertBooks($l);
    }

    public static function badHttpAmounts(): array
    {
        return ['zero' => ['0'], 'negative' => ['-100'], 'under a rupee' => ['0.99'], 'text' => ['abc'], 'infinity' => ['INF'], 'nan' => ['NAN'],
            'huge' => ['1e30'], 'one paisa over' => ['102000.01'], 'empty' => ['']];
    }

    public function test_pay_full_dues_with_two_different_keys_books_once_and_never_goes_negative(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->http("/dhiran/loans/{$l->id}/repay", ['amount' => '102000'])->assertSessionHas('success');
        $this->http("/dhiran/loans/{$l->id}/repay", ['amount' => '102000'])->assertSessionHas('error');
        $this->assertSame(1, $this->paymentsIn($l));
        $this->assertSame('closed', $this->fresh($l)->status);
        $this->assertBooks($l);
    }

    public function test_pay_interest_twice_with_different_keys_cannot_exceed_the_interest_owed(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->http("/dhiran/loans/{$l->id}/pay-interest", ['amount' => '2000'])->assertSessionHas('success');
        $this->http("/dhiran/loans/{$l->id}/pay-interest", ['amount' => '2000'])->assertSessionHas('error');
        $f = $this->fresh($l);
        $this->assertSame(1, $this->paymentsIn($l));
        $this->assertSame('0.00', $f->outstanding_interest);
        $this->assertSame('100000.00', $f->outstanding_principal);
    }

    public function test_one_key_cannot_be_reused_for_a_different_action_on_the_same_loan(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $key = 'shared-key-0001';
        $this->t(fn () => $this->actingAs($this->owner)->post(self::HOST . "/dhiran/loans/{$l->id}/repay", ['amount' => '1000', 'idempotency_key' => $key]))->assertSessionHas('success');
        $this->t(fn () => $this->actingAs($this->owner)->post(self::HOST . "/dhiran/loans/{$l->id}/pre-close", ['idempotency_key' => $key]))->assertSessionHas('error');
        $this->assertSame('active', $this->fresh($l)->status);
        $this->assertSame(1, $this->paymentsIn($l));
    }

    public function test_one_key_with_a_changed_payment_method_is_refused(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $key = 'method-key-0001';
        $post = fn (string $m) => $this->t(fn () => $this->actingAs($this->owner)->post(self::HOST . "/dhiran/loans/{$l->id}/repay", ['amount' => '1000', 'payment_method' => $m, 'idempotency_key' => $key]));
        $post('cash')->assertSessionHas('success');
        $post('upi')->assertSessionHas('error');
        $this->assertSame(['cash'], DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->where('direction', 'in')->pluck('payment_method')->all());
    }

    #[DataProvider('badKeys')]
    public function test_a_malformed_idempotency_key_books_nothing(string $key): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->t(fn () => $this->actingAs($this->owner)->post(self::HOST . "/dhiran/loans/{$l->id}/repay", ['amount' => '1000', 'idempotency_key' => $key]))->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, $this->paymentsIn($l));
    }

    public static function badKeys(): array
    {
        return ['too short' => ['abc'], 'spaces' => ['has a space in it'], 'symbols' => ['key;drop table--'], 'too long' => [str_repeat('a', 65)], 'unicode' => ['kéy-kéy-kéy-kéy']];
    }

    public function test_pre_close_twice_with_different_keys_books_one_closure(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->http("/dhiran/loans/{$l->id}/pre-close", [])->assertSessionHas('success');
        $this->http("/dhiran/loans/{$l->id}/pre-close", [])->assertSessionHas('error');
        $this->assertSame(1, DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->where('type', 'pre_closure')->count());
        $this->assertSame(1, DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $l->id)->where('entry_type', 'closure')->count());
        $this->assertBooks($l);
    }

    public function test_renewing_twice_with_different_keys_makes_one_new_loan(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->http("/dhiran/loans/{$l->id}/renew", [])->assertSessionHas('success');
        $this->http("/dhiran/loans/{$l->id}/renew", [])->assertSessionHas('error');
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('renewed_from_id', $l->id)->count());
        $this->assertSame(1, $this->paymentsIn($l), 'interest for the renewal collected once');
    }

    public function test_renewing_twice_with_the_same_key_makes_one_new_loan(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $k = ['idempotency_key' => 'renew-key-0001'];
        $this->t(fn () => $this->actingAs($this->owner)->post(self::HOST . "/dhiran/loans/{$l->id}/renew", $k))->assertSessionHas('success');
        $this->t(fn () => $this->actingAs($this->owner)->post(self::HOST . "/dhiran/loans/{$l->id}/renew", $k))->assertSessionHas('success', 'Already recorded: this form was sent before.');
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('renewed_from_id', $l->id)->count());
    }

    public function test_a_release_offered_an_absurd_amount_over_http_is_capped_to_the_dues(): void
    {
        $l = $this->loan(['principal_amount' => 10000], '2026-01-01', [$this->gold(10)]);
        $item = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->first();
        $this->http("/dhiran/loans/{$l->id}/release-item", ['item_id' => $item->id, 'payment_amount' => '1e15'])->assertSessionHas('success');
        $this->assertSame(10000.00, $this->sum('dhiran_cash_entries', $l->id, 'amount', ['type' => 'in']));
    }

    public function test_another_shops_loan_cannot_be_paid_or_closed_by_id(): void
    {
        $other = self::createDhiranShop(['name' => 'Rival', 'shop_type' => 'dhiran', 'phone' => '9390077111', 'owner_first_name' => 'R', 'owner_last_name' => 'V',
            'owner_mobile' => '9390077111', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($other->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_interest_months' => 0]);
        $cust = $this->t(fn () => Customer::create(['shop_id' => $other->id, 'first_name' => 'R', 'last_name' => 'C', 'mobile' => '9815570099']), $other->id);
        $this->at('2026-01-01');
        $theirs = $this->t(fn () => $this->svc->createLoan($other, $cust, [$this->gold()], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'loan_date' => '2026-01-01']), $other->id);

        $this->http("/dhiran/loans/{$theirs->id}/repay", ['amount' => '1000'])->assertNotFound();
        $this->http("/dhiran/loans/{$theirs->id}/pre-close", [])->assertNotFound();
        $this->http("/dhiran/loans/{$theirs->id}/renew", [])->assertNotFound();
        $this->assertSame(0, $this->paymentsIn($theirs));
        $this->assertSame('active', $this->fresh($theirs)->status);
    }

    private function lapse(): void
    {
        ShopSubscription::where('shop_id', $this->shop->id)->update(['status' => 'expired', 'ends_at' => today()->subDays(40), 'grace_ends_at' => today()->subDays(30)]);
    }

    public function test_after_the_plan_ends_collections_still_work_but_new_business_waits(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $this->lapse();

        $this->http("/dhiran/loans/{$l->id}/repay", ['amount' => '1000'])->assertSessionHas('success');
        $this->assertSame(1, $this->paymentsIn($l));

        $loansBefore = DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count();
        $this->http("/dhiran/loans/{$l->id}/renew", [])->assertRedirect(route('dhiran.dashboard'));
        $this->http('/dhiran', ['customer_id' => $this->customer->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]]])->assertRedirect(route('dhiran.dashboard'));
        $this->http('/dhiran/old-loans', $this->oldForm())->assertRedirect(route('dhiran.dashboard'));
        $this->assertSame($loansBefore, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
        $this->assertSame('active', $this->fresh($l)->status);

        $this->http("/dhiran/loans/{$l->id}/pre-close", [])->assertSessionHas('success');
        $this->assertSame('closed', $this->fresh($l)->status);
        $this->assertBooks($l);
    }

    public function test_a_renewal_turned_away_by_the_lapsed_plan_does_not_burn_its_key(): void
    {
        $l = $this->loan();
        $this->at('2026-01-31');
        $k = ['idempotency_key' => 'lapsed-key-0001'];
        $this->lapse();
        $this->t(fn () => $this->actingAs($this->owner)->post(self::HOST . "/dhiran/loans/{$l->id}/renew", $k))->assertRedirect(route('dhiran.dashboard'));
        $this->assertSame(0, DB::table('idempotency_keys')->where('key', 'dhiran:lapsed-key-0001')->count());

        ShopSubscription::where('shop_id', $this->shop->id)->update(['status' => 'active', 'ends_at' => today()->addYear(), 'grace_ends_at' => today()->addYear()->addDays(14)]);
        $this->t(fn () => $this->actingAs($this->owner)->post(self::HOST . "/dhiran/loans/{$l->id}/renew", $k))->assertSessionHas('success');
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('renewed_from_id', $l->id)->count());
    }
}
