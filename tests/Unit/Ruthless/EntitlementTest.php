<?php
namespace Tests\Unit\Ruthless;

use App\Models\Platform\ShopSubscription;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Pure date logic of ShopSubscription (no DB): access is date-derived in Asia/Kolkata. */
class EntitlementTest extends TestCase
{
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function at(string $ist): void { Carbon::setTestNow(Carbon::parse($ist, 'Asia/Kolkata')); }

    private function sub(string $status, ?string $ends, ?string $grace): ShopSubscription
    {
        return new ShopSubscription(['status' => $status, 'ends_at' => $ends, 'grace_ends_at' => $grace]);
    }

    public static function boundaries(): array
    {
        // monthly plan: ends 2027-03-10, grace_days 7 -> grace_ends_at 2027-03-17. Access is inclusive of both days.
        return [
            'last second of ends_at'            => ['2027-03-10 23:59:59', true],
            'midnight after ends_at (in grace)' => ['2027-03-11 00:00:00', true],
            'last second of grace'              => ['2027-03-17 23:59:59', true],
            'midnight after grace'              => ['2027-03-18 00:00:00', false],
            'long after'                        => ['2027-06-01 12:00:00', false],
            'first second of ends_at day'       => ['2027-03-10 00:00:00', true],
            'day before start of anything'      => ['2027-01-01 00:00:00', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('boundaries')]
    public function test_active_term_access_flips_exactly_at_midnight_after_grace_end(string $now, bool $expected): void
    {
        $this->at($now);
        $this->assertSame($expected, $this->sub('active', '2027-03-10', '2027-03-17')->grantsAccessToday());
        // the 'grace' status label gives the same answer: access is derived from dates, the label only gates expired/cancelled
        $this->assertSame($expected, $this->sub('grace', '2027-03-10', '2027-03-17')->grantsAccessToday());
    }

    public function test_expired_and_cancelled_labels_never_grant_even_with_future_dates(): void
    {
        $this->at('2027-03-10 12:00:00');
        $this->assertFalse($this->sub('expired', '2028-03-10', '2028-03-24')->grantsAccessToday());   // label wins
        $this->assertFalse($this->sub('cancelled', '2028-03-10', '2028-03-24')->grantsAccessToday()); // refund/terminate wins
    }

    public function test_missing_dates_never_grant(): void
    {
        $this->at('2027-03-10 12:00:00');
        $this->assertFalse($this->sub('active', null, null)->grantsAccessToday()); // no end date = no proof of payment
    }

    public function test_grace_shorter_than_term_cannot_cut_the_paid_term(): void
    {
        $this->at('2027-03-10 12:00:00');
        // grace_ends_at before ends_at (bad data / grace_days 0 arithmetic): the later of the two is the cutoff
        $this->assertTrue($this->sub('active', '2027-03-12', '2027-03-01')->grantsAccessToday());
        $this->at('2027-03-13 00:00:00');
        $this->assertFalse($this->sub('active', '2027-03-12', '2027-03-01')->grantsAccessToday());
    }

    public function test_missing_grace_falls_back_to_ends_at(): void
    {
        $this->at('2027-03-12 23:59:59');
        $this->assertTrue($this->sub('active', '2027-03-12', null)->grantsAccessToday());
        $this->at('2027-03-13 00:00:00');
        $this->assertFalse($this->sub('active', '2027-03-12', null)->grantsAccessToday());
    }

    public function test_days_remaining_is_zero_on_ends_at_day_and_negative_after(): void
    {
        $s = $this->sub('active', '2027-03-10', '2027-03-17');
        $this->at('2027-03-09 23:59:59'); $this->assertSame(1, $s->daysRemaining());
        $this->at('2027-03-10 23:59:59'); $this->assertSame(0, $s->daysRemaining());
        $this->at('2027-03-11 00:00:00'); $this->assertSame(-1, $s->daysRemaining());
        $this->assertNull($this->sub('active', null, null)->daysRemaining());
    }
}
