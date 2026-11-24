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
use Tests\TestCase;

/**
 * Advance interest (owner 2026-11-24): the loan creator may take the first N months' interest at the counter.
 * Advance A = principal * rate% * N (flat 2% of 100000 for 1 month = 2000). The ticket principal stays 100000, the cash handed
 * out is 100000 - 2000 on the books as an out of 100000 and an in of 2000. Interest runs from day 1 as always, but the first 30*N days
 * are already paid for, so nothing is owed until day 31. The shop keeps the whole advance when the customer closes early.
 */
class AdvanceInterestTest extends TestCase
{
    use RefreshDatabase;

    private DhiranService $svc;
    private Shop $shop;
    private Customer $customer;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-01-01');
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

    private function t(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, $fn);
    }

    private function loan(array $p = []): DhiranLoan
    {
        $this->at('2026-01-01');

        return $this->t(fn () => $this->svc->createLoan($this->shop, $this->customer, [[
            'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 24, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000,
        ]], array_merge([
            'principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2.0, 'interest_type' => 'flat',
            'penalty_rate_monthly' => 0, 'tenure_months' => 12, 'min_lock_months' => 0, 'min_interest_months' => 0,
            'grace_period_days' => 30, 'loan_date' => '2026-01-01', 'created_by' => null, 'advance_interest_months' => 1,
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

    public function test_creation_takes_the_first_month_at_the_counter(): void
    {
        $l = $this->loan();

        $this->assertSame(1, (int) $l->advance_interest_months);
        $this->assertEquals(2000.00, (float) $l->interest_prepaid);
        $this->assertEquals(2000.00, (float) $l->total_interest_collected);
        $this->assertEquals(0.00, (float) $l->outstanding_interest);
        $this->assertEquals(100000.00, (float) $l->outstanding_principal);
        $this->assertSame(100000.00, $this->cash($l, 'out'));
        $this->assertSame(2000.00, $this->cash($l, 'in'), 'net cash handed over is 98000');
        $adv = DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->where('type', 'advance_interest')->first();
        $this->assertNotNull($adv);
        $this->assertSame('in', $adv->direction);
        $this->assertEquals(2000.00, (float) $adv->interest_component);
    }

    public function test_two_months_and_percentages_are_exact(): void
    {
        $l = $this->loan(['advance_interest_months' => 3, 'interest_rate_monthly' => 1.5]);
        $this->assertEquals(4500.00, (float) $l->interest_prepaid); // 100000 * 1.5% * 3
    }

    public function test_nothing_is_owed_for_the_first_thirty_days_then_interest_runs(): void
    {
        $l = $this->loan();

        $d29 = $this->accrueOn($l, '2026-01-30');
        $this->assertEquals(0.00, (float) $d29->outstanding_interest);
        $this->assertEqualsWithDelta(66.67, (float) $d29->interest_prepaid, 0.011, 'day 29 used 29 of 30 days');

        $d30 = $this->accrueOn($l, '2026-01-31');
        $this->assertEquals(0.00, (float) $d30->outstanding_interest);
        $this->assertEqualsWithDelta(0.0, (float) $d30->interest_prepaid, 0.011);

        $d31 = $this->accrueOn($l, '2026-02-01');
        $this->assertEqualsWithDelta(66.67, (float) $d31->outstanding_interest, 0.011, 'day 31 owes its own day');
        $this->assertEquals(0.00, (float) $d31->interest_prepaid);
    }

    public function test_accrual_is_the_same_however_often_it_runs(): void
    {
        $once = $this->loan();
        $this->accrueOn($once, '2026-02-10');

        $many = $this->loan();
        foreach (['2026-01-05', '2026-01-20', '2026-02-02', '2026-02-10', '2026-02-10'] as $d) {
            $this->accrueOn($many, $d);
        }
        $a = $this->fresh($once);
        $b = $this->fresh($many);
        $this->assertEqualsWithDelta((float) $a->outstanding_interest, (float) $b->outstanding_interest, 0.011);
        $this->assertEqualsWithDelta((float) $a->interest_prepaid, (float) $b->interest_prepaid, 0.011);
        $this->assertEqualsWithDelta(40 * 100000 * 0.02 / 30 - 2000, (float) $a->outstanding_interest, 0.02, '40 days of interest minus the 2000 advance');
    }

    public function test_closing_on_day_ten_keeps_the_whole_advance_and_refunds_nothing(): void
    {
        $l = $this->loan();
        $this->at('2026-01-11');
        $this->t(fn () => $this->svc->recordPayment($this->fresh($l), 100000));

        $f = $this->fresh($l);
        $this->assertSame('closed', $f->status);
        $this->assertSame(100000.00, $this->cash($l, 'out'), 'only the disbursement ever leaves the till');
        $this->assertSame(0, DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id)->whereIn('type', ['advance_refund', 'interest_refund'])->count());
    }

    public function test_closing_on_day_twenty_nine_keeps_the_whole_advance_too(): void
    {
        $l = $this->loan();
        $this->at('2026-01-30');
        $this->t(fn () => $this->svc->recordPayment($this->fresh($l), 100000));

        $this->assertSame('closed', $this->fresh($l)->status);
        $this->assertSame(100000.00, $this->cash($l, 'out'));
    }

    public function test_the_advance_counts_towards_the_minimum_interest(): void
    {
        $l = $this->loan(['min_interest_months' => 1]);
        $this->at('2026-01-11');
        $pay = $this->t(fn () => $this->svc->preCloseLoan($this->fresh($l)));

        $this->assertEquals(100000.00, (float) $pay->amount, 'the advance already is the minimum month: nothing more is charged');
        $this->assertSame('closed', $this->fresh($l)->status);
    }

    public function test_a_part_payment_before_the_advance_is_used_up_cuts_the_later_accrual(): void
    {
        $l = $this->loan();
        $this->at('2026-01-11');
        $this->t(fn () => $this->svc->recordPayment($this->fresh($l), 50000));
        $f = $this->accrueOn($l, '2026-02-10'); // 10 days on 100000 + 30 days on 50000 = 666.67 + 1000.00 = 1666.67

        $this->assertEquals(0.00, (float) $f->outstanding_interest);
        $this->assertEqualsWithDelta(333.33, (float) $f->interest_prepaid, 0.02);
    }

    public function test_compound_loans_match_flat_through_the_first_month_end(): void
    {
        $l = $this->loan(['interest_type' => 'compound']);
        $f = $this->accrueOn($l, '2026-01-31');

        $this->assertEquals(0.00, (float) $f->outstanding_interest);
        $this->assertEqualsWithDelta(0.0, (float) $f->interest_prepaid, 0.011);
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

    public function test_it_is_refused_when_the_shop_has_not_enabled_it_or_the_months_are_out_of_range(): void
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

    public function test_zero_months_is_the_ordinary_loan(): void
    {
        $l = $this->loan(['advance_interest_months' => 0]);

        $this->assertEquals(0.00, (float) $l->interest_prepaid);
        $this->assertSame(0.00, $this->cash($l, 'in'));
        $f = $this->accrueOn($l, '2026-01-31');
        $this->assertEqualsWithDelta(2000.00, (float) $f->outstanding_interest, 0.011);
    }

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
        foreach (['2026-01-11', '2026-02-10'] as $day) {
            $l = $this->loan();
            $this->at($day);
            $new = $this->t(fn () => $this->svc->renewLoan($this->fresh($l)));
            $this->assertSame(0, (int) $new->advance_interest_months, $day);
            $this->assertEquals(0.00, (float) $new->interest_prepaid, $day);
            $this->assertSame('renewed', $this->fresh($l)->status);
        }
    }

    public function test_compound_across_several_month_ends_is_the_same_in_one_accrual_or_many(): void
    {
        foreach ([1, 2] as $months) {
            $a = $this->loan(['interest_type' => 'compound', 'advance_interest_months' => $months]);
            $b = $this->loan(['interest_type' => 'compound', 'advance_interest_months' => $months]);
            $this->accrueOn($a, '2026-04-05');
            foreach (['2026-01-20', '2026-02-01', '2026-02-25', '2026-03-01', '2026-03-20', '2026-04-05'] as $d) {
                $this->accrueOn($b, $d);
            }
            $x = $this->fresh($a);
            $y = $this->fresh($b);
            foreach (['outstanding_interest', 'interest_prepaid', 'interest_capitalised'] as $col) {
                $this->assertEqualsWithDelta((float) $x->$col, (float) $y->$col, 0.03, "{$months} month(s): {$col}");
            }
        }
    }
}
