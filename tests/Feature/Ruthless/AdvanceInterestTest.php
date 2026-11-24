<?php

namespace Tests\Feature\Ruthless;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Interest collected in advance (owner 2026-11-24). A loan with advance_interest_months > 0 is "paid in advance, month by month":
 * installment 1 (the first N months) is taken on the loan day, the next is due when that period ends and pays the following month.
 * Periods are calendar months from the loan day (24 Nov, 24 Dec, 24 Jan, ...) and each costs its ACTUAL days at rate/30 per day:
 *   100000 at 2%: 66.6667 a day; Nov 24 to Dec 24 = 30 days = 2000.00; Dec 24 to Jan 24 = 31 days = 2066.67.
 * Interest runs from day 1 as always; the advance is spent day by day, so nothing is owed while it lasts. The shop keeps whatever
 * is left when the loan closes.
 */
class AdvanceInterestTest extends TestCase
{
    use RefreshDatabase;

    private const START = '2026-11-24';

    private DhiranService $svc;
    private Shop $shop;
    private Customer $customer;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at(self::START);
        $this->svc = app(DhiranService::class);
        $this->shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390078000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390078000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0,
            'default_min_lock_months' => 0, 'default_min_interest_months' => 0, 'default_penalty_rate_monthly' => 0,
            'advance_interest_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390078000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'a@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $this->customer = $this->t(fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Adv', 'last_name' => 'Ance', 'mobile' => '9815580001']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $date): void
    {
        Carbon::setTestNow(Carbon::parse("{$date} 10:00", 'Asia/Kolkata'));
    }

    /** The date n days after the loan day. */
    private function day(int $n): string
    {
        return Carbon::parse(self::START)->addDays($n)->toDateString();
    }

    private function t(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, $fn);
    }

    private function loan(array $p = []): DhiranLoan
    {
        $this->at(self::START);

        return $this->t(fn () => $this->svc->createLoan($this->shop, $this->customer, [[
            'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 24, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000,
        ]], array_merge([
            'principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2.0, 'interest_type' => 'flat',
            'penalty_rate_monthly' => 0, 'tenure_months' => 12, 'min_lock_months' => 0, 'min_interest_months' => 0,
            'grace_period_days' => 30, 'loan_date' => self::START, 'created_by' => null, 'advance_interest_months' => 1,
        ], $p)));
    }

    private function fresh(DhiranLoan $l): DhiranLoan
    {
        return DhiranLoan::withoutGlobalScopes()->findOrFail($l->id);
    }

    private function accrueOn(DhiranLoan $l, string $date): DhiranLoan
    {
        $this->at($date);
        $this->t(fn () => $this->svc->accrueInterest($this->fresh($l)));

        return $this->fresh($l);
    }

    private function cash(DhiranLoan $l, string $type): float
    {
        return round((float) DB::table('dhiran_cash_entries')->where('dhiran_loan_id', $l->id)->where('type', $type)->sum('amount'), 2);
    }

    private function amountOn(DhiranLoan $l, string $date): float
    {
        $this->at($date);

        return $this->t(fn () => $this->svc->installmentAmount($this->fresh($l)));
    }

    private function payInstallmentOn(DhiranLoan $l, string $date)
    {
        $this->at($date);

        return $this->t(fn () => $this->svc->payInstallment($this->fresh($l)));
    }

    // ── first installment, taken on the loan day ─────────────────────────────

    public function test_creation_takes_the_first_month_at_the_counter(): void
    {
        $l = $this->loan();

        $this->assertSame(1, (int) $l->advance_interest_months);
        $this->assertSame(1, (int) $l->interest_periods_paid);
        $this->assertEquals(2000.00, (float) $l->interest_prepaid, 'Nov 24 to Dec 24 is 30 days');
        $this->assertEquals(2000.00, (float) $l->total_interest_collected);
        $this->assertEquals(0.00, (float) $l->outstanding_interest);
        $this->assertEquals(100000.00, (float) $l->outstanding_principal);
        $this->assertSame(100000.00, $this->cash($l, 'out'));
        $this->assertSame(2000.00, $this->cash($l, 'in'), 'net cash handed over is 98000');
        $adv = DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->where('type', 'advance_interest')->first();
        $this->assertNotNull($adv);
        $this->assertSame('in', $adv->direction);
        $this->assertEquals(2000.00, (float) $adv->interest_component);
        $this->assertSame('2026-12-24', $this->fresh($l)->nextInstallmentDue()->toDateString());
    }

    public function test_the_advance_counts_the_real_days_of_each_month(): void
    {
        $this->assertEquals(4066.67, (float) $this->loan(['advance_interest_months' => 2])->interest_prepaid, 'Nov 24 to Jan 24 = 30 + 31 = 61 days');
        $this->assertEquals(6133.33, (float) $this->loan(['advance_interest_months' => 3])->interest_prepaid, '92 days');
        $this->assertEquals(1500.00, (float) $this->loan(['interest_rate_monthly' => 1.5])->interest_prepaid);

        $this->at('2026-12-24');
        $dec = $this->t(fn () => $this->svc->createLoan($this->shop, $this->customer, [[
            'description' => 'Ring', 'metal_type' => 'gold', 'purity' => 24, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000,
        ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2.0, 'interest_type' => 'flat',
            'tenure_months' => 12, 'loan_date' => '2026-12-24', 'advance_interest_months' => 1]));
        $this->assertEquals(2066.67, (float) $dec->interest_prepaid, 'a 31-day month costs 31 days');
    }

    public function test_compound_pays_the_same_first_months_as_flat(): void
    {
        $this->assertEquals(4066.67, (float) $this->loan(['interest_type' => 'compound', 'advance_interest_months' => 2])->interest_prepaid, 'paid ahead, so nothing compounds');
    }

    public function test_nothing_is_owed_while_the_advance_lasts_then_interest_runs(): void
    {
        $l = $this->loan();

        $d29 = $this->accrueOn($l, $this->day(29));
        $this->assertEquals(0.00, (float) $d29->outstanding_interest);
        $this->assertEqualsWithDelta(66.67, (float) $d29->interest_prepaid, 0.011);

        $d30 = $this->accrueOn($l, $this->day(30));
        $this->assertEquals(0.00, (float) $d30->outstanding_interest);
        $this->assertEqualsWithDelta(0.0, (float) $d30->interest_prepaid, 0.011);

        $d31 = $this->accrueOn($l, $this->day(31));
        $this->assertEqualsWithDelta(66.67, (float) $d31->outstanding_interest, 0.011);
    }

    public function test_accrual_is_the_same_however_often_it_runs(): void
    {
        $once = $this->loan();
        $this->accrueOn($once, $this->day(40));
        $many = $this->loan();
        foreach ([5, 20, 33, 40, 40] as $n) {
            $this->accrueOn($many, $this->day($n));
        }
        $a = $this->fresh($once);
        $b = $this->fresh($many);
        $this->assertEqualsWithDelta((float) $a->outstanding_interest, (float) $b->outstanding_interest, 0.011);
        $this->assertEqualsWithDelta((float) $a->interest_prepaid, (float) $b->interest_prepaid, 0.011);
        $this->assertEqualsWithDelta(40 * 100000 * 0.02 / 30 - 2000, (float) $a->outstanding_interest, 0.02, '40 days of interest minus the 2000 advance');
    }

    // ── closing early: the shop keeps the advance ─────────────────────────────

    public function test_closing_on_day_ten_or_twenty_nine_keeps_the_whole_advance(): void
    {
        foreach ([10, 29] as $n) {
            $l = $this->loan();
            $this->at($this->day($n));
            $this->t(fn () => $this->svc->recordPayment($this->fresh($l), 100000));

            $this->assertSame('closed', $this->fresh($l)->status, "day {$n}");
            $this->assertSame(100000.00, $this->cash($l, 'out'), 'only the disbursement ever leaves the till');
            $this->assertSame(0, DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->whereIn('type', ['advance_refund', 'interest_refund'])->count());
        }
    }

    public function test_the_advance_counts_towards_the_minimum_interest(): void
    {
        $l = $this->loan(['min_interest_months' => 1]);
        $this->at($this->day(10));
        $pay = $this->t(fn () => $this->svc->preCloseLoan($this->fresh($l)));

        $this->assertEquals(100000.00, (float) $pay->amount, 'the advance already is the minimum month: nothing more is charged');
        $this->assertSame('closed', $this->fresh($l)->status);
    }

    public function test_a_part_payment_before_the_advance_is_used_up_cuts_the_later_accrual(): void
    {
        $l = $this->loan();
        $this->at($this->day(10));
        $this->t(fn () => $this->svc->recordPayment($this->fresh($l), 50000));
        $f = $this->accrueOn($l, $this->day(40)); // 10 days on 100000 + 30 days on 50000 = 666.67 + 1000.00

        $this->assertEquals(0.00, (float) $f->outstanding_interest);
        $this->assertEqualsWithDelta(333.33, (float) $f->interest_prepaid, 0.02);
    }

    public function test_cancelling_a_pending_loan_gives_the_advance_back(): void
    {
        $l = $this->loan(['status' => 'pending_evidence']);
        $this->t(fn () => $this->svc->cancelPendingLoan($this->fresh($l), 'entered by mistake'));

        $f = $this->fresh($l);
        $this->assertSame('cancelled', $f->status);
        $this->assertEquals(0.00, (float) $f->interest_prepaid);
        $this->assertEquals(0.00, (float) $f->total_interest_collected);
        $this->assertSame($this->cash($l, 'in'), $this->cash($l, 'out'), 'the till nets to zero: nothing was lent, nothing kept');
    }

    // ── the next installments ─────────────────────────────────────────────────

    public function test_the_second_installment_is_due_when_the_first_month_ends_and_pays_the_next_month(): void
    {
        $l = $this->loan();

        $this->assertEquals(2066.67, $this->amountOn($l, '2026-12-24'), 'Dec 24 to Jan 24 is 31 days');
        $pay = $this->payInstallmentOn($l, '2026-12-24');

        $this->assertSame('advance_interest', $pay->type);
        $this->assertSame('in', $pay->direction);
        $this->assertEquals(2066.67, (float) $pay->amount);
        $f = $this->fresh($l);
        $this->assertSame(2, (int) $f->interest_periods_paid);
        $this->assertSame('2027-01-24', $f->nextInstallmentDue()->toDateString());
        $this->assertEquals(4066.67, (float) $f->total_interest_collected);
        $this->assertSame(round(2000.00 + 2066.67, 2), $this->cash($l, 'in'));

        $end = $this->accrueOn($l, '2027-01-24');
        $this->assertEqualsWithDelta(0.0, (float) $end->outstanding_interest, 0.02, 'month 2 is paid for exactly');
        $this->assertEqualsWithDelta(0.0, (float) $end->interest_prepaid, 0.02);
    }

    public function test_paying_the_second_installment_late_still_costs_exactly_its_month(): void
    {
        $l = $this->loan();
        // Jan 8 is 45 days in: Dec 25 to Jan 8 (15 days, 1000.00) is already owed, the rest of the period is still ahead.
        $this->assertEquals(2066.67, $this->amountOn($l, '2027-01-08'), 'late days + the rest of the month = the whole month');
        $this->payInstallmentOn($l, '2027-01-08');

        $f = $this->fresh($l);
        $this->assertEquals(0.00, (float) $f->outstanding_interest, 'the late days were settled by the payment');
        $this->assertSame(2, (int) $f->interest_periods_paid);
        $end = $this->accrueOn($l, '2027-01-24');
        $this->assertEqualsWithDelta(0.0, (float) $end->outstanding_interest, 0.02);
        $this->assertEqualsWithDelta(0.0, (float) $end->interest_prepaid, 0.02);
    }

    public function test_a_part_payment_lowers_the_next_installment(): void
    {
        $l = $this->loan();
        $this->at($this->day(15)); // Dec 9
        $this->t(fn () => $this->svc->recordPayment($this->fresh($l), 20000));

        // days 1-15 on 100000 = 1000.00, days 16-30 on 80000 = 800.00: 200.00 of the first advance is left on Dec 24.
        // Dec 24 to Jan 24 on 80000 = 31 x 53.3333 = 1653.33, less the 200 left = 1453.33.
        $this->assertEquals(1453.33, $this->amountOn($l, '2026-12-24'));
        $this->payInstallmentOn($l, '2026-12-24');
        $end = $this->accrueOn($l, '2027-01-24');
        $this->assertEqualsWithDelta(0.0, (float) $end->outstanding_interest, 0.03);
        $this->assertEqualsWithDelta(0.0, (float) $end->interest_prepaid, 0.03);
    }

    #[DataProvider('interestTypes')]
    public function test_a_paid_installment_is_spent_to_zero_at_the_period_end_for_every_interest_type(string $type): void
    {
        // One on-time installment, then the customer vanishes for 9 days past the next due date (arrears build up, and for compound
        // they compound at the anniversary), then settles that period.
        $l = $this->loan(['interest_type' => $type]);
        $this->payInstallmentOn($l, '2026-12-24');           // period 2, on time
        $late = $this->accrueOn($l, '2027-02-02');           // Jan 24 to Feb 2 unpaid
        $this->assertGreaterThan(0.0, (float) $late->outstanding_interest);

        $this->payInstallmentOn($l, '2027-02-02');           // period 3 (Jan 24 to Feb 24): late days + the rest
        $f = $this->fresh($l);
        $this->assertSame(3, (int) $f->interest_periods_paid);
        $this->assertEquals(0.00, (float) $f->outstanding_interest);

        $end = $this->accrueOn($l, '2027-02-24');
        $this->assertEqualsWithDelta(0.0, (float) $end->outstanding_interest, 0.05, "{$type}: period 3 is paid exactly");
        $this->assertEqualsWithDelta(0.0, (float) $end->interest_prepaid, 0.05, "{$type}: nothing left over");
    }

    #[DataProvider('interestTypes')]
    public function test_every_month_paid_on_time_costs_its_real_days_and_ends_at_zero_for_every_type(string $type): void
    {
        // due date => days in the period it pays: Dec 24 to Jan 24 = 31, Jan 24 to Feb 24 = 31, Feb 24 to Mar 24 = 28, Mar 24 to Apr 24 = 31
        $l = $this->loan(['interest_type' => $type]);
        $periods = ['2026-12-24' => [31, '2027-01-24'], '2027-01-24' => [31, '2027-02-24'], '2027-02-24' => [28, '2027-03-24'], '2027-03-24' => [31, '2027-04-24']];
        foreach ($periods as $due => [$days, $end]) {
            $expected = round(100000 * 0.02 / 30 * $days, 2);
            $this->assertEqualsWithDelta($expected, $this->amountOn($l, $due), 0.02, "{$type}: {$due} pays {$days} days");
            $this->payInstallmentOn($l, $due);
            $f = $this->accrueOn($l, $end);
            $this->assertEqualsWithDelta(0.0, (float) $f->outstanding_interest, 0.05, "{$type}: nothing owed at {$end}");
            $this->assertEqualsWithDelta(0.0, (float) $f->interest_prepaid, 0.05, "{$type}: nothing left over at {$end}");
        }
        $this->assertSame(5, (int) $this->fresh($l)->interest_periods_paid);
    }

    #[DataProvider('interestTypes')]
    public function test_a_part_payment_then_the_installment_ends_at_zero_for_every_type(string $type): void
    {
        $l = $this->loan(['interest_type' => $type]);
        $this->at($this->day(15));
        $this->t(fn () => $this->svc->recordPayment($this->fresh($l), 20000));

        $this->assertEquals(1453.33, $this->amountOn($l, '2026-12-24'), "{$type}: 31 days on 80000 less the 200 left");
        $this->payInstallmentOn($l, '2026-12-24');
        $end = $this->accrueOn($l, '2027-01-24');
        $this->assertEqualsWithDelta(0.0, (float) $end->outstanding_interest, 0.05, $type);
        $this->assertEqualsWithDelta(0.0, (float) $end->interest_prepaid, 0.05, $type);
    }

    #[DataProvider('interestTypes')]
    public function test_the_first_advance_and_early_close_follow_every_type(string $type): void
    {
        $this->assertEquals(2000.00, (float) $this->loan(['interest_type' => $type])->interest_prepaid, "{$type}: 30 days");
        $this->assertEquals(4066.67, (float) $this->loan(['interest_type' => $type, 'advance_interest_months' => 2])->interest_prepaid, "{$type}: 61 days");

        $l = $this->loan(['interest_type' => $type]);
        $this->at($this->day(20));
        $this->t(fn () => $this->svc->recordPayment($this->fresh($l), 100000));
        $this->assertSame('closed', $this->fresh($l)->status, "{$type}: pays the principal only, the advance stays with the shop");
    }

    #[DataProvider('interestTypes')]
    public function test_a_missed_month_is_settled_exactly_for_every_type_and_only_compound_charges_interest_on_interest(string $type): void
    {
        $l = $this->loan(['interest_type' => $type]);
        $this->payInstallmentOn($l, '2026-12-24');
        // nothing paid after Jan 24: Mar 5 is 40 days later (31 to Feb 24, then 9)
        $mar = $this->accrueOn($l, '2027-03-05');
        $simple = round(100000 * 0.02 / 30 * 40, 2); // Jan 24 to Mar 5 on 100000

        if ($type === 'compound') {
            $this->assertGreaterThan($simple + 0.5, (float) $mar->outstanding_interest, 'unpaid interest at the Feb 24 anniversary earns interest');
        } else {
            $this->assertEqualsWithDelta($simple, (float) $mar->outstanding_interest, 0.05, "{$type}: simple interest, nothing on interest");
        }
        $this->payInstallmentOn($l, '2027-03-05'); // period 3 (to Feb 24, already past): settles what is owed
        $this->payInstallmentOn($l, '2027-03-05'); // period 4 (Feb 24 to Mar 24): the rest of March
        $f = $this->fresh($l);
        $this->assertEquals(0.00, (float) $f->outstanding_interest);
        $end = $this->accrueOn($l, '2027-03-24');
        $this->assertEqualsWithDelta(0.0, (float) $end->outstanding_interest, 0.05, $type);
        $this->assertEqualsWithDelta(0.0, (float) $end->interest_prepaid, 0.05, $type);
    }

    public static function interestTypes(): array
    {
        return ['flat' => ['flat'], 'daily' => ['daily'], 'compound' => ['compound']];
    }

    public function test_a_compound_loan_that_missed_a_month_owes_the_compounded_amount(): void
    {
        $flat = $this->loan(['interest_type' => 'flat']);
        $comp = $this->loan(['interest_type' => 'compound']);
        $f = $this->amountOn($flat, '2027-02-02');
        $c = $this->amountOn($comp, '2027-02-02');

        $this->assertGreaterThan($f, $c, 'unpaid interest at the Dec 24 and Jan 24 anniversaries earns interest');
    }

    public function test_an_installment_can_be_taken_a_week_early_but_not_two(): void
    {
        $l = $this->loan();
        $this->at('2026-12-10'); // due Dec 24: 14 days away
        try {
            $this->t(fn () => $this->svc->payInstallment($this->fresh($l)));
            $this->fail('two weeks early must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('not due yet', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->fresh($l)->interest_periods_paid);

        // Dec 17 is 7 days early: 7 days of the first advance are still unspent, so it still costs exactly the month.
        $this->assertEquals(2066.67, $this->amountOn($l, '2026-12-17'));
        $this->payInstallmentOn($l, '2026-12-17');
        $this->assertSame(2, (int) $this->fresh($l)->interest_periods_paid);
    }

    public function test_only_a_paid_in_advance_active_loan_has_installments(): void
    {
        $later = $this->loan(['advance_interest_months' => 0]);
        $this->assertNull($this->fresh($later)->nextInstallmentDue());
        $this->expectException(LogicException::class);
        $this->payInstallmentOn($later, '2026-12-24');
    }

    public function test_a_closed_loan_has_no_installment(): void
    {
        $l = $this->loan();
        $this->at($this->day(10));
        $this->t(fn () => $this->svc->recordPayment($this->fresh($l), 100000));
        $this->expectException(LogicException::class);
        $this->payInstallmentOn($l, '2026-12-24');
    }

    // ── reports, renewal, switches ───────────────────────────────────────────

    public function test_the_interest_report_nets_a_cancelled_advance_to_zero(): void
    {
        $l = $this->loan(['status' => 'pending_evidence']);
        $this->t(fn () => $this->svc->cancelPendingLoan($this->fresh($l), 'mistake'));

        $data = $this->t(function () {
            $this->actingAs($this->owner);
            $req = \Illuminate\Http\Request::create('http://127.0.0.1:8787/dhiran/reports', 'GET', ['type' => 'interest']);
            $this->app->instance('request', $req);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return app(\App\Http\Controllers\DhiranController::class)->reportInterest($req)->getData();
        });
        $this->assertEquals(0.0, $data['totalInterest'], 'the advance in and the same advance back out net to nothing');
        $this->assertEquals(0.0, (float) $data['footer']['interest']);
    }

    public function test_a_renewal_does_not_inherit_the_advance(): void
    {
        foreach ([10, 40] as $n) {
            $l = $this->loan();
            $this->at($this->day($n));
            $new = $this->t(fn () => $this->svc->renewLoan($this->fresh($l)));
            $this->assertSame(0, (int) $new->advance_interest_months, "day {$n}");
            $this->assertSame(0, (int) $new->interest_periods_paid, "day {$n}");
            $this->assertEquals(0.00, (float) $new->interest_prepaid, "day {$n}");
            $this->assertSame('renewed', $this->fresh($l)->status);
        }
    }

    public function test_it_is_refused_when_the_shop_has_not_enabled_it(): void
    {
        $this->expectException(LogicException::class);
        DhiranSettings::getForShop($this->shop->id)->update(['advance_interest_enabled' => false]);
        $this->loan();
    }

    public function test_four_months_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->loan(['advance_interest_months' => 4]);
    }

    public function test_zero_months_is_the_ordinary_loan_paid_afterwards(): void
    {
        $l = $this->loan(['advance_interest_months' => 0]);

        $this->assertEquals(0.00, (float) $l->interest_prepaid);
        $this->assertSame(0, (int) $l->interest_periods_paid);
        $this->assertSame(0.00, $this->cash($l, 'in'));
        $f = $this->accrueOn($l, $this->day(30));
        $this->assertEqualsWithDelta(2000.00, (float) $f->outstanding_interest, 0.011);
    }
}
