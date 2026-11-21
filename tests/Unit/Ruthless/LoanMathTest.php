<?php

namespace Tests\Unit\Ruthless;

use App\Models\Dhiran\DhiranLoan;
use App\Services\MetalRegistry;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Adversarial pure-calculation tests: fine-weight maths, minimum interest, outstanding totals, lock period and the
 * forfeiture road. No database rows; each assertion states the business rule it checks.
 */
class LoanMathTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loan(array $attrs): DhiranLoan
    {
        return (new DhiranLoan())->forceFill($attrs);
    }

    private function at(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date . ' 10:00', 'Asia/Kolkata'));
    }

    // ── Fine weight (MetalRegistry) ─────────────────────────────────────────

    #[DataProvider('purities')]
    public function test_fine_weight_multiplier_follows_the_metal_scale(string $metal, float $purity, float $expected): void
    {
        // Rule: gold purity is karats out of 24; silver purity is fineness out of 1000.
        $this->assertEqualsWithDelta($expected, MetalRegistry::fineWeightMultiplier($metal, $purity), 1e-12);
    }

    public static function purities(): array
    {
        return [
            'gold 24k is pure'       => ['gold', 24, 1.0],
            'gold 22k'               => ['gold', 22, 22 / 24],
            'gold 18k'               => ['gold', 18, 0.75],
            'gold 0 is no metal'     => ['gold', 0, 0.0],
            'silver 999'             => ['silver', 999, 0.999],
            'silver 925 sterling'    => ['silver', 925, 0.925],
            'silver 0'               => ['silver', 0, 0.0],
        ];
    }

    public function test_fine_weight_is_rounded_to_six_places(): void
    {
        // Rule: 10.123457 g of 22k gold -> 10.123457 * 22/24 = 9.279...; stored to 6 dp.
        $this->assertSame(9.279836, MetalRegistry::fineWeight('gold', 10.123457, 22));
        $this->assertSame(0.0, MetalRegistry::fineWeight('gold', 0.0, 22));
    }

    public function test_gold_purity_above_24_karat_is_not_a_valid_scale(): void
    {
        // Rule: no gold is purer than 24k; a 30k "purity" would make fine weight exceed gross weight and inflate
        // the loan value. The single authority for fine weight must refuse it (or cap it at 1.0).
        $m = MetalRegistry::fineWeightMultiplier('gold', 30);
        $this->assertTrue($m === null || $m <= 1.0, "30 karat gold gave multiplier {$m} (> 1.0)");
    }

    public function test_negative_purity_is_not_a_valid_scale(): void
    {
        // Rule: negative purity would give negative fine weight / negative collateral value.
        $m = MetalRegistry::fineWeightMultiplier('silver', -925);
        $this->assertTrue($m === null || $m >= 0.0, "negative purity gave multiplier {$m}");
    }

    // ── Minimum interest ────────────────────────────────────────────────────

    public function test_minimum_interest_is_original_principal_times_monthly_rate_times_months(): void
    {
        // Rule: 100000 at 2%/month for a 3-month minimum = 6000.00.
        $this->assertSame(6000.0, $this->loan(['principal_amount' => '100000.00', 'interest_rate_monthly' => '2.00', 'min_interest_months' => 3])->minimumInterestAmount());
    }

    public function test_minimum_interest_rounds_to_the_paisa(): void
    {
        // Rule: 33333.33 * 1.5% * 2 = 999.9999 -> 1000.00.
        $this->assertSame(1000.0, $this->loan(['principal_amount' => '33333.33', 'interest_rate_monthly' => '1.50', 'min_interest_months' => 2])->minimumInterestAmount());
    }

    #[DataProvider('noMinimum')]
    public function test_no_minimum_interest_when_months_or_rate_is_zero(int $months, string $rate): void
    {
        $this->assertEquals(0, $this->loan(['principal_amount' => '50000.00', 'interest_rate_monthly' => $rate, 'min_interest_months' => $months])->minimumInterestAmount());
    }

    public static function noMinimum(): array
    {
        return ['zero months' => [0, '2.00'], 'zero rate' => [3, '0.00']];
    }

    // ── Outstanding total ───────────────────────────────────────────────────

    public function test_total_outstanding_does_not_drift_on_float_sums(): void
    {
        // Rule: 0.10 + 0.20 + 0.30 owed is exactly 0.60 (paisa-rounded), not 0.6000000000000001.
        $l = $this->loan(['status' => 'active', 'outstanding_principal' => '0.10', 'outstanding_interest' => '0.20', 'outstanding_penalty' => '0.30']);
        $this->assertSame(0.6, $l->totalOutstanding());
    }

    #[DataProvider('finished')]
    public function test_a_finished_loan_owes_nothing_whatever_its_stale_balances(string $status): void
    {
        $l = $this->loan(['status' => $status, 'outstanding_principal' => '5000.00', 'outstanding_interest' => '10.00', 'outstanding_penalty' => '1.00']);
        $this->assertSame(0.0, $l->totalOutstanding());
    }

    public static function finished(): array
    {
        return ['closed' => ['closed'], 'renewed' => ['renewed'], 'forfeited' => ['forfeited'], 'cancelled' => ['cancelled']];
    }

    // ── Lock period ─────────────────────────────────────────────────────────

    public function test_lock_period_ends_on_the_calendar_anniversary(): void
    {
        // Rule: a 3-month lock from 2026-01-15 ends 2026-04-15: locked on 04-14, free on 04-15.
        $l = $this->loan(['status' => 'active', 'loan_date' => '2026-01-15', 'min_lock_months' => 3]);
        $this->at('2026-04-14');
        $this->assertTrue($l->isInLockPeriod());
        $this->at('2026-04-15');
        $this->assertFalse($l->isInLockPeriod());
    }

    public function test_a_one_month_lock_from_the_31st_ends_at_the_end_of_the_next_month(): void
    {
        // Rule (month-end convention, same as interest accrual's addMonthsNoOverflow): 1 month from 31 Jan is
        // 28 Feb. On 28 Feb the lock must be over; Carbon addMonths() overflows to 3 Mar and keeps it locked.
        $l = $this->loan(['status' => 'active', 'loan_date' => '2026-01-31', 'min_lock_months' => 1]);
        $this->at('2026-02-28');
        $this->assertFalse($l->isInLockPeriod(), 'lock should have ended on 28 Feb 2026');
    }

    public function test_zero_lock_months_is_never_locked(): void
    {
        $this->at('2026-01-15');
        $this->assertFalse($this->loan(['status' => 'active', 'loan_date' => '2026-01-15', 'min_lock_months' => 0])->isInLockPeriod());
    }

    // ── Forfeiture road ─────────────────────────────────────────────────────

    #[DataProvider('road')]
    public function test_forfeiture_stage_by_day(string $today, string $expected): void
    {
        // Rule: maturity 2026-02-01, 10 grace days. Not overdue until the day AFTER maturity; penalty/notice only
        // after the grace period's last day (2026-02-11).
        $this->at($today);
        $l = $this->loan(['status' => 'active', 'maturity_date' => '2026-02-01', 'grace_period_days' => 10]);
        $this->assertSame($expected, $l->forfeitureStage());
    }

    public static function road(): array
    {
        return [
            'day before maturity'   => ['2026-01-31', 'current'],
            'maturity day itself'   => ['2026-02-01', 'current'],
            'first overdue day'     => ['2026-02-02', 'grace'],
            'last grace day'        => ['2026-02-11', 'grace'],
            'first day after grace' => ['2026-02-12', 'notice_due'],
        ];
    }

    #[DataProvider('afterNotice')]
    public function test_forfeiture_waits_for_the_deadline_day_and_is_ready_on_it(string $today, string $expected): void
    {
        // Rule: the notice promises 2026-03-13; forfeiture is refused before it and allowed from it.
        $this->at($today);
        $l = $this->loan(['status' => 'active', 'maturity_date' => '2026-02-01', 'grace_period_days' => 10,
            'forfeiture_notice_sent_at' => '2026-02-12 11:00:00', 'forfeiture_deadline' => '2026-03-13']);
        $this->assertSame($expected, $l->forfeitureStage());
    }

    public static function afterNotice(): array
    {
        return ['day before deadline' => ['2026-03-12', 'notice_sent'], 'deadline day' => ['2026-03-13', 'ready'], 'after' => ['2026-04-01', 'ready']];
    }

    public function test_a_non_active_loan_is_nowhere_on_the_forfeiture_road(): void
    {
        $this->assertNull($this->loan(['status' => 'closed', 'maturity_date' => '2020-01-01'])->forfeitureStage());
    }

    // ── Overdue display at the Asia/Kolkata midnight ────────────────────────

    public function test_overdue_flips_at_ist_midnight_not_utc_midnight(): void
    {
        // Rule: maturity 2026-02-28. At 00:30 IST on 1 Mar (= 19:00 UTC on 28 Feb) the loan is 1 day overdue.
        Carbon::setTestNow(Carbon::parse('2026-03-01 00:30:00', 'Asia/Kolkata'));
        $l = $this->loan(['status' => 'active', 'maturity_date' => '2026-02-28']);
        $this->assertTrue($l->isOverdue());
        $this->assertSame(1, $l->daysOverdue());

        Carbon::setTestNow(Carbon::parse('2026-02-28 23:59:59', 'Asia/Kolkata'));
        $this->assertFalse($l->isOverdue(), 'on the maturity day itself the loan is not yet overdue');
    }
}
