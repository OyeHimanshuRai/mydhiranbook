<?php

namespace Tests\Feature\Adversarial;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopCounter;
use App\Models\User;
use App\Services\BusinessIdentifierService;
use App\Services\Dhiran\Notices;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adversarial QA of the loan state machines and the date logic (notices bell, list filters, forfeiture stages,
 * installments, accrual, loan numbers). Every test asserts the SAFE outcome. Tests prefixed test_BUG_ are expected to
 * FAIL today: each documents a defect found by reading the code (see the comment above it).
 *
 * "Today" is 2026-06-15 unless a test moves it. The shop is created on 2025-12-01 so its one-year term covers 2026.
 */
class LoanDatesAndStatesTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';

    /** Month-end loan dates (leap year 2028 and an ordinary 28 Feb) where "+ n months" has to clamp. */
    private const DATES = ['2028-01-29', '2028-01-30', '2028-01-31', '2028-02-28', '2028-02-29', '2027-02-28',
        '2028-03-31', '2028-04-30', '2028-05-31', '2028-08-31', '2028-10-31', '2028-12-31'];

    private Shop $shop;
    private User $owner;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2025-12-01');
        $this->shop = self::createDhiranShop(['name' => 'Adv Co', 'shop_type' => 'dhiran', 'phone' => '9390025100', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390025100', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0,
            'default_min_interest_months' => 0, 'default_penalty_rate_monthly' => 0, 'advance_interest_enabled' => true,
            'forfeiture_notice_days' => 30, 'loan_number_prefix' => 'QA-', 'loan_number_digits' => 6]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390025100', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'adv@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Ram', 'last_name' => 'Lal', 'mobile' => '9815635100']));
        $this->at('2026-06-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Notices::forget();
        parent::tearDown();
    }

    // ── helpers (none is named get/post/put/patch/delete/call/json) ─────────────────────

    private function at(string $date, string $time = '10:00'): void
    {
        Carbon::setTestNow(Carbon::parse("{$date} {$time}", 'Asia/Kolkata'));
        Notices::forget();
    }

    private function t(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, $fn);
    }

    private function svc(): DhiranService
    {
        return app(DhiranService::class);
    }

    private function chain(string $name = 'Chain'): array
    {
        return ['description' => $name, 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000];
    }

    /** A loan made through the service (so every side row exists), then the given columns forced. */
    private function mk(array $set = [], array $params = [], ?array $items = null): DhiranLoan
    {
        $this->actingAs($this->owner);
        $loan = $this->t(fn () => $this->svc()->createLoan($this->shop, $this->customer, $items ?? [$this->chain()], array_merge([
            'principal_amount' => 50000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'penalty_rate_monthly' => 0,
            'tenure_months' => 12, 'min_lock_months' => 0, 'min_interest_months' => 0, 'grace_period_days' => 5, 'loan_date' => '2026-06-01',
            'created_by' => $this->owner->id,
        ], $params)));

        return $set ? $this->upd($loan, $set) : $this->fresh($loan);
    }

    private function fresh(DhiranLoan $l): DhiranLoan
    {
        return DhiranLoan::withoutGlobalScopes()->findOrFail($l->id);
    }

    private function upd(DhiranLoan $l, array $set): DhiranLoan
    {
        DhiranLoan::withoutGlobalScopes()->whereKey($l->id)->update($set);
        Notices::forget();

        return $this->fresh($l);
    }

    /** key => count of the bell rows (the access-ending row is about the plan, not the loans). */
    private function keys(): array
    {
        Notices::forget();
        $k = collect(Notices::forShop($this->shop->id))->pluck('count', 'key')->except('plan_ending')->all();
        ksort($k);

        return $k;
    }

    /** The row keys that start with a prefix. */
    private function family(string $prefix): array
    {
        return array_values(array_filter(array_keys($this->keys()), fn ($k) => str_starts_with($k, $prefix)));
    }

    private function refused(callable $fn, string $why, string $contains = ''): void
    {
        try {
            $fn();
        } catch (LogicException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());

            return;
        }
        $this->fail("must be refused: {$why}");
    }

    private function numbers(string $html): array
    {
        preg_match_all('/QA-\d{6}/', $html, $m);
        $n = array_values(array_unique($m[0]));
        sort($n);

        return $n;
    }

    private function payments(DhiranLoan $l, ?string $type = null): int
    {
        $q = DB::table('dhiran_payments')->where('dhiran_loan_id', $l->id);

        return $type ? $q->where('type', $type)->count() : $q->count();
    }

    private function ledgers(DhiranLoan $l, ?string $type = null): int
    {
        $q = DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $l->id);

        return $type ? $q->where('entry_type', $type)->count() : $q->count();
    }

    private function lapse(): void
    {
        ShopSubscription::where('shop_id', $this->shop->id)->update(['status' => 'expired', 'ends_at' => today()->subDays(40), 'grace_ends_at' => today()->subDays(30)]);
    }

    /** The bell bucket an installment falls in, from first principles (days between today and the due date). */
    private function instKey(int $left, array $w = [3, 7, 30]): ?string
    {
        return match (true) {
            $left < 0 => 'inst_late', $left <= $w[0] => 'inst_w1', $left <= $w[1] => 'inst_w2', $left <= $w[2] => 'inst_w3', default => null,
        };
    }

    private function matKey(int $left, array $w = [3, 7, 30]): ?string
    {
        return match (true) {
            $left < 0 => null, $left <= $w[0] => 'mat_w1', $left <= $w[1] => 'mat_w2', $left <= $w[2] => 'mat_w3', default => null,
        };
    }

    private function days(Carbon $from, Carbon $to): int
    {
        return (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false));
    }

    /** Which list filters (installment / maturity / forfeit) pick up at least one loan of the shop. */
    private function filterHits(): array
    {
        $id = $this->shop->id;
        $q = fn () => DhiranLoan::withoutGlobalScopes()->where('shop_id', $id);
        $hit = [];
        foreach (['late', 'w1', 'w2', 'w3'] as $b) {
            Notices::installmentWhere($q(), $b, $id)->exists() && $hit[] = "installment={$b}";
        }
        foreach (['w1', 'w2', 'w3'] as $b) {
            Notices::maturityWhere($q(), $b, $id)->exists() && $hit[] = "maturity={$b}";
        }
        foreach (['soon', 'notice', 'ready'] as $s) {
            Notices::forfeitWhere($q(), $s, $id)->exists() && $hit[] = "forfeit={$s}";
        }

        return $hit;
    }

    /** All bell keys a loan belongs to, decided here from the rules (independent of the SQL under test). */
    private function expectedKeys(DhiranLoan $l, array $w = [3, 7, 30], int $forfeitDays = 30): array
    {
        if ($l->status === 'pending_evidence') {
            return ['pending'];
        }
        if ($l->status !== 'active') {
            return [];
        }
        $k = [];
        if ((int) $l->advance_interest_months > 0 && ($key = $this->instKey($this->days(today(), $l->nextInstallmentDue()), $w))) {
            $k[] = $key;
        }
        if ($key = $this->matKey($this->days(today(), $l->maturity_date), $w)) {
            $k[] = $key;
        }
        if ($l->forfeitureStage() === 'ready') {
            $k[] = 'forfeit_ready';
        } elseif (! $l->forfeiture_notice_sent_at) {
            $left = $this->days(today(), $l->maturity_date->copy()->addDays((int) $l->grace_period_days));
            if ($left < 0) {
                $k[] = 'forfeit_notice';
            } elseif ($left <= $forfeitDays) {
                $k[] = 'forfeit_soon';
            }
        }
        sort($k);

        return $k;
    }

    // ── (1) month-end installment dates: SQL in Notices == DhiranLoan::nextInstallmentDue() ───────────

    public function test_sql_next_due_date_equals_php_next_due_date_for_every_month_end_loan_date_and_period(): void
    {
        $loan = $this->mk();
        foreach (self::DATES as $date) {
            for ($n = 0; $n <= 24; $n++) {
                $l = $this->upd($loan, ['loan_date' => $date, 'advance_interest_months' => 1, 'interest_periods_paid' => $n]);
                $sql = DB::selectOne("select (loan_date::date + interest_periods_paid * INTERVAL '1 month')::date as d from dhiran_loans where id = ?", [$l->id])->d;
                $this->assertSame($l->nextInstallmentDue()->toDateString(), $sql, "loan {$date}, {$n} periods paid");
            }
        }
    }

    public function test_the_bell_puts_every_month_end_installment_in_the_bucket_php_computes(): void
    {
        $loan = $this->mk(['maturity_date' => '2040-01-01', 'grace_period_days' => 5]);
        foreach (['2028-01-31', '2028-01-30', '2028-02-29', '2027-02-28', '2028-03-31', '2028-08-31'] as $date) {
            for ($n = 0; $n <= 13; $n++) {
                $l = $this->upd($loan, ['loan_date' => $date, 'advance_interest_months' => 1, 'interest_periods_paid' => $n]);
                $due = $l->nextInstallmentDue();
                foreach ([-1, 0, 3, 4, 7, 8, 30, 31] as $left) {
                    $this->at($due->copy()->subDays($left)->toDateString());
                    $want = $this->instKey($left);
                    $this->assertSame($want ? [$want] : [], $this->family('inst_'), "loan {$date}, {$n} paid, due {$due->toDateString()}, {$left} days left");
                }
            }
        }
    }

    // ── (2) timezone edges ────────────────────────────────────────────────────────────

    public function test_buckets_flip_exactly_at_local_midnight_not_at_utc(): void
    {
        // installment and maturity both fall on 15 Jun
        $this->mk(['loan_date' => '2026-05-15', 'advance_interest_months' => 1, 'interest_periods_paid' => 1, 'maturity_date' => '2026-06-15', 'grace_period_days' => 90]);
        foreach ([['2026-06-14', '23:59'], ['2026-06-15', '00:01'], ['2026-06-15', '23:59']] as [$d, $t]) {
            $this->at($d, $t);
            $this->assertSame(['inst_w1' => 1, 'mat_w1' => 1], $this->keys(), "{$d} {$t} IST");
        }
        // 00:01 on the 16th is still the 15th in UTC (18:31): the shop's day has changed, so the installment is late and maturity is past
        $this->at('2026-06-16', '00:01');
        $this->assertSame(['inst_late' => 1], $this->keys());
    }

    public function test_forfeiture_buckets_and_stage_flip_together_at_local_midnight(): void
    {
        $l = $this->mk(['maturity_date' => '2026-06-10', 'grace_period_days' => 5]); // forfeiture point 15 Jun
        $this->at('2026-06-15', '23:59');
        $this->assertSame(['forfeit_soon' => 1], $this->keys());
        $this->assertSame('grace', $this->fresh($l)->forfeitureStage(), 'the last grace day is still grace');
        $this->at('2026-06-16', '00:01');
        $this->assertSame(['forfeit_notice' => 1], $this->keys());
        $this->assertSame('notice_due', $this->fresh($l)->forfeitureStage());
        $this->assertTrue($this->fresh($l)->forfeitureNoticeDue());
    }

    public function test_the_date_columns_are_plain_dates_so_the_sql_casts_cannot_shift_a_day(): void
    {
        $types = DB::table('information_schema.columns')->where('table_name', 'dhiran_loans')
            ->whereIn('column_name', ['loan_date', 'maturity_date', 'forfeiture_deadline', 'interest_accrued_through', 'forfeiture_notice_sent_at'])
            ->pluck('data_type', 'column_name')->all();
        foreach (['loan_date', 'maturity_date', 'forfeiture_deadline', 'interest_accrued_through'] as $c) {
            $this->assertSame('date', $types[$c], $c);
        }
        $this->assertSame('timestamp without time zone', $types['forfeiture_notice_sent_at'], 'stored as shop-local wall time, so ::date is the shop day');
    }

    // Defect: DhiranLoan::scopeDefaultRisk compares with the database CURRENT_DATE (DhiranLoan.php:365), every other rule
    // uses today() in Asia/Kolkata. Between 00:00 and 05:30 IST a loan already in notice_due is not "default risk".
    public function test_BUG_default_risk_scope_uses_the_shops_day_not_the_database_day(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $l = $this->mk(['maturity_date' => '2026-06-10', 'grace_period_days' => 5]);
        $this->at('2026-06-16', '01:00'); // 15 Jun 19:30 UTC
        $this->assertSame('notice_due', $this->fresh($l)->forfeitureStage());
        $ids = DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->defaultRisk()->pluck('id')->all();
        $this->assertContains($l->id, $ids, 'a loan past maturity + grace by the shop calendar is default risk');
    }

    // ── (3) boundary days with custom windows ─────────────────────────────────────────

    public static function windowSets(): array
    {
        return ['default 3/7/30, forfeit 30' => [[3, 7, 30], 30], 'tiny 1/2/3, forfeit 5' => [[1, 2, 3], 5],
            'wide 10/20/90, forfeit 90' => [[10, 20, 90], 90], 'forfeit equals first window' => [[10, 20, 90], 10], 'forfeit equals last window' => [[3, 7, 30], 30]];
    }

    #[DataProvider('windowSets')]
    public function test_every_window_edge_is_in_the_right_bucket(array $w, int $forfeit): void
    {
        DhiranSettings::getForShop($this->shop->id)->update(['notices_interest_days' => $w, 'notices_forfeit_days' => $forfeit]);
        $this->assertSame($w, Notices::options($this->shop->id)['days']);
        $loan = $this->mk();
        $today = today();
        $edges = array_values(array_unique([-1, 0, 1, $w[0], $w[0] + 1, $w[1], $w[1] + 1, $w[2], $w[2] + 1]));

        foreach ($edges as $left) { // installments: due "left" days from today
            $l = $this->upd($loan, ['advance_interest_months' => 1, 'interest_periods_paid' => 0, 'loan_date' => $today->copy()->addDays($left)->toDateString(), 'maturity_date' => '2040-01-01']);
            $want = $this->instKey($left, $w);
            $this->assertSame($want ? [$want] : [], $this->family('inst_'), "installment {$left} days away, windows " . implode('/', $w));
        }
        foreach ($edges as $left) { // maturity
            $this->upd($loan, ['advance_interest_months' => 0, 'maturity_date' => $today->copy()->addDays($left)->toDateString(), 'grace_period_days' => 90]);
            $want = $this->matKey($left, $w);
            $this->assertSame($want ? [$want] : [], $this->family('mat_'), "maturity {$left} days away, windows " . implode('/', $w));
        }
        foreach (array_unique([-1, 0, 1, $forfeit - 1, $forfeit, $forfeit + 1]) as $point) { // forfeiture point (maturity + 5 grace) is "point" days away
            $this->upd($loan, ['maturity_date' => $today->copy()->addDays($point - 5)->toDateString(), 'grace_period_days' => 5]);
            $want = $point < 0 ? ['forfeit_notice'] : ($point <= $forfeit ? ['forfeit_soon'] : []);
            $got = array_values(array_filter($this->family('forfeit_'), fn ($k) => $k !== 'forfeit_ready'));
            $this->assertSame($want, $got, "forfeiture point {$point} days away, soon window {$forfeit}");
        }
    }

    // ── (4) status cross product ──────────────────────────────────────────────────────

    public static function finishedStatuses(): array
    {
        return [['closed'], ['renewed'], ['forfeited'], ['cancelled']];
    }

    #[DataProvider('finishedStatuses')]
    public function test_a_finished_loan_is_in_no_row_and_no_filter_however_late_it_is(string $status): void
    {
        $late = ['advance_interest_months' => 1, 'interest_periods_paid' => 1, 'loan_date' => '2025-01-01', 'maturity_date' => '2026-06-17', 'grace_period_days' => 0];
        $l = $this->mk($late + ['status' => $status]);
        $this->assertSame([], $this->keys(), "{$status}, installment late and maturity near");
        $this->assertSame([], $this->filterHits());

        $this->upd($l, ['maturity_date' => '2025-06-01', 'forfeiture_notice_sent_at' => '2025-07-01 10:00:00', 'forfeiture_deadline' => '2025-07-31']);
        $this->assertSame([], $this->keys(), "{$status} with a notice long past its deadline");
        $this->assertSame([], $this->filterHits());
        $this->assertNull($this->fresh($l)->forfeitureStage());
    }

    public function test_a_pending_evidence_loan_is_only_ever_in_the_pending_row(): void
    {
        $this->mk(['status' => 'pending_evidence', 'advance_interest_months' => 1, 'interest_periods_paid' => 1, 'loan_date' => '2025-01-01',
            'maturity_date' => '2025-06-01', 'grace_period_days' => 0, 'forfeiture_notice_sent_at' => '2025-07-01 10:00:00', 'forfeiture_deadline' => '2025-07-31']);
        $this->assertSame(['pending' => 1], $this->keys());
        $this->assertSame([], $this->filterHits());
    }

    public function test_a_loan_with_a_late_installment_and_past_maturity_is_in_both_rows_and_both_lists(): void
    {
        $l = $this->mk(['loan_date' => '2026-04-01', 'advance_interest_months' => 1, 'interest_periods_paid' => 1, 'maturity_date' => '2026-05-20', 'grace_period_days' => 5]);
        $this->assertSame(['forfeit_notice' => 1, 'inst_late' => 1], $this->keys());
        foreach (['installment=late', 'forfeit=notice', 'overdue=1-30'] as $qs) {
            $html = $this->actingAs($this->owner)->get(self::HOST . "/dhiran/loans?{$qs}")->assertOk()->getContent();
            $this->assertSame([$l->loan_number], $this->numbers($html), $qs);
        }
        $this->assertSame([], $this->numbers($this->actingAs($this->owner)->get(self::HOST . '/dhiran/loans?maturity=w1')->getContent()), 'past maturity is not "reaching" maturity');
    }

    public function test_a_renewed_loan_leaves_every_row_and_the_new_loans_installment_starts_fresh(): void
    {
        $old = $this->mk([], ['loan_date' => '2026-04-01', 'advance_interest_months' => 1]); // first month ended 1 May: installment late
        $this->assertSame(['inst_late' => 1], $this->keys());
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc()->renewLoan($this->fresh($old)));

        $this->assertSame('renewed', $this->fresh($old)->status);
        $new = DhiranLoan::withoutGlobalScopes()->where('renewed_from_id', $old->id)->firstOrFail();
        $this->assertSame(1, (int) $new->interest_periods_paid);
        $this->assertSame('2026-07-15', $new->nextInstallmentDue()->toDateString(), 'a month from the renewal day');
        $this->assertSame(['inst_w3' => 1], $this->keys(), 'only the new loan, 30 days to its first installment');
        $this->assertSame(1, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $new->id)->where('status', 'pledged')->count());
    }

    // ── (5) forfeiture stages: model, bell, list filter and service guards agree ──────────

    public static function stageCases(): array
    {
        $sent = ['forfeiture_notice_sent_at' => '2026-06-10 10:00:00'];

        return [
            'current, far' => [['maturity_date' => '2026-08-30'], 'current', []],
            'current, point within 30 days' => [['maturity_date' => '2026-07-01'], 'current', ['soon']],
            'grace: today is maturity + grace' => [['maturity_date' => '2026-06-10'], 'grace', ['soon']],
            'notice due: the day after grace' => [['maturity_date' => '2026-06-09'], 'notice_due', ['notice']],
            'notice sent, deadline tomorrow' => [['maturity_date' => '2026-05-01', 'grace_period_days' => 0] + $sent + ['forfeiture_deadline' => '2026-06-16'], 'notice_sent', []],
            'ready on the deadline day' => [['maturity_date' => '2026-05-01', 'grace_period_days' => 0] + $sent + ['forfeiture_deadline' => '2026-06-15'], 'ready', ['ready']],
            'ready long after the deadline' => [['maturity_date' => '2026-05-01', 'grace_period_days' => 0] + $sent + ['forfeiture_deadline' => '2026-06-01'], 'ready', ['ready']],
        ];
    }

    #[DataProvider('stageCases')]
    public function test_stage_bell_filter_and_guards_agree(array $set, string $stage, array $hits): void
    {
        $l = $this->mk($set + ['grace_period_days' => 5]);
        $this->assertSame($stage, $l->forfeitureStage());
        $want = array_map(fn ($h) => "forfeit={$h}", $hits);
        $this->assertSame($want, array_values(array_filter($this->filterHits(), fn ($h) => str_starts_with($h, 'forfeit='))), 'list filters');
        $bell = array_values(array_filter(array_keys($this->keys()), fn ($k) => in_array($k, ['forfeit_soon', 'forfeit_notice', 'forfeit_ready'], true)));
        $this->assertSame(array_map(fn ($h) => "forfeit_{$h}", $hits), $bell, 'bell rows');
        $this->assertSame($stage === 'ready', $l->canExecuteForfeit());
        $this->assertSame($stage === 'notice_due', $l->forfeitureNoticeDue());

        $this->actingAs($this->owner);
        if ($stage !== 'ready') {
            $this->refused(fn () => $this->t(fn () => $this->svc()->executeForfeit($this->fresh($l))), "forfeit at stage {$stage}");
        }
        if ($stage !== 'notice_due') {
            $this->refused(fn () => $this->t(fn () => $this->svc()->sendForfeitureNotice($this->fresh($l))), "notice at stage {$stage}");
        }
        $this->assertSame('active', $this->fresh($l)->status);
    }

    // Defect: a notice with no stored deadline (sent before the column existed, or a shop without a settings row when the
    // backfill ran) is "ready" in SQL the day it is sent (Notices.php:113 uses sent_at::date), but DhiranLoan::forfeitureDeadline()
    // (DhiranLoan.php:317) adds the shop's notice days, so the model says notice_sent and the service refuses the forfeit.
    public function test_BUG_legacy_notice_without_deadline_is_not_ready_in_the_bell_while_the_model_says_waiting(): void
    {
        $l = $this->mk(['maturity_date' => '2026-05-01', 'grace_period_days' => 0, 'forfeiture_notice_sent_at' => '2026-06-10 10:00:00', 'forfeiture_deadline' => null]);
        $this->assertSame('notice_sent', $l->forfeitureStage(), 'model: 10 Jun + 30 notice days = 10 Jul');
        $this->refused(fn () => $this->t(fn () => $this->svc()->executeForfeit($this->fresh($l))), 'forfeit before the fallback deadline');
        $this->assertArrayNotHasKey('forfeit_ready', $this->keys(), 'the bell must not tell the shop to forfeit a loan the service refuses to forfeit');
        $this->assertNotContains('forfeit=ready', $this->filterHits());
    }

    // ── (6) repeated and out-of-order actions ─────────────────────────────────────────

    private function noticeLoan(): DhiranLoan
    {
        $l = $this->mk([], ['loan_date' => '2026-01-01', 'tenure_months' => 1, 'grace_period_days' => 5]);
        $this->at('2026-03-01');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc()->sendForfeitureNotice($this->fresh($l)));

        return $this->fresh($l);
    }

    public function test_the_notice_is_sent_once_the_forfeit_waits_for_the_printed_deadline_and_happens_once(): void
    {
        $l = $this->noticeLoan();
        $this->assertSame('2026-03-31', $l->forfeiture_deadline->toDateString());
        $text = $l->forfeiture_notice_text;

        $this->at('2026-03-10');
        $this->refused(fn () => $this->t(fn () => $this->svc()->sendForfeitureNotice($this->fresh($l))), 'second notice');
        $this->assertSame($text, $this->fresh($l)->forfeiture_notice_text);
        $this->at('2026-03-30');
        $this->refused(fn () => $this->t(fn () => $this->svc()->executeForfeit($this->fresh($l))), 'the day before the deadline');

        $this->at('2026-03-31');
        $this->refused(fn () => $this->t(fn () => $this->svc()->sendForfeitureNotice($this->fresh($l))), 'a new notice once the deadline is reached');
        $this->t(fn () => $this->svc()->executeForfeit($this->fresh($l)));
        $f = $this->fresh($l);
        $this->assertSame('forfeited', $f->status);
        $this->assertEquals(0, (float) $f->outstanding_principal + (float) $f->outstanding_interest + (float) $f->outstanding_penalty);
        $this->assertSame(0, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $l->id)->where('status', 'pledged')->count());

        $this->refused(fn () => $this->t(fn () => $this->svc()->executeForfeit($this->fresh($l))), 'forfeiting twice');
        $this->refused(fn () => $this->t(fn () => $this->svc()->sendForfeitureNotice($this->fresh($l))), 'a notice on a forfeited loan');
        $this->refused(fn () => $this->t(fn () => $this->svc()->recordPayment($this->fresh($l), 100)), 'a payment on a forfeited loan');
        $this->refused(fn () => $this->t(fn () => $this->svc()->renewLoan($this->fresh($l))), 'renewing a forfeited loan');
        $this->assertSame(1, $this->ledgers($l, 'forfeiture'));
        $this->assertSame([], $this->keys());
    }

    public function test_editing_the_notice_text_changes_nothing_about_when_the_forfeit_is_allowed(): void
    {
        $l = $this->noticeLoan();
        $this->at('2026-03-05');
        $this->actingAs($this->owner)->patch(self::HOST . "/dhiran/loans/{$l->id}/forfeiture-notice", ['body' => "Please visit the shop.\n\nWe hold the ornaments.", 'warning' => 'FINAL REMINDER'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Please visit the shop.', explode("\n", $this->fresh($l)->forfeiture_notice_edit['body'])[0]);
        $this->assertSame('2026-03-31', $this->fresh($l)->forfeiture_deadline->toDateString(), 'the deadline is the original one');
        $this->refused(fn () => $this->t(fn () => $this->svc()->executeForfeit($this->fresh($l))), 'forfeit before the deadline after an edit');

        $this->at('2026-03-31');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc()->executeForfeit($this->fresh($l)));
        $f = $this->fresh($l);
        $this->assertSame('forfeited', $f->status);
        $this->assertSame('FINAL REMINDER', $f->forfeiture_notice_edit['warning'], 'the edited letter is kept as it was printed');
        // a forfeited loan's letter can no longer be edited
        $this->actingAs($this->owner)->patch(self::HOST . "/dhiran/loans/{$l->id}/forfeiture-notice", ['body' => 'x', 'warning' => 'y'])->assertStatus(422);
    }

    // Defect: executeForfeit (DhiranService.php:1904) never accrues, so the written-off interest on the ledger stops at the
    // last accrual (here the day the notice was sent), 30 days short of the day the loan is forfeited.
    public function test_BUG_forfeit_writes_off_interest_up_to_the_day_of_forfeiture(): void
    {
        $l = $this->noticeLoan();   // accrued through 1 Mar
        $this->at('2026-03-31');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc()->executeForfeit($this->fresh($l)));
        $written = (float) DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $l->id)->where('entry_type', 'forfeiture')->value('amount');
        $this->assertEqualsWithDelta(50000 + 50000 * 0.02 / 30 * 89, $written, 0.05, '1 Jan to 31 Mar is 89 days of interest');
    }

    public function test_releasing_the_last_item_closes_the_loan_and_a_closed_loan_cannot_be_renewed(): void
    {
        $l = $this->mk();
        $this->actingAs($this->owner);
        $item = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->firstOrFail();
        $this->t(function () use ($l, $item) {
            $this->svc()->accrueInterest($this->fresh($l));
            $need = $this->svc()->releaseMinimum($this->fresh($l), $item);
            $this->svc()->releaseItem($this->fresh($l), $item, $need, 'cash', 'ok');
        });
        $f = $this->fresh($l);
        $this->assertSame('closed', $f->status);
        $this->assertEquals(0, $f->totalOutstanding());
        $this->assertSame([], $this->keys());
        $this->refused(fn () => $this->t(fn () => $this->svc()->renewLoan($this->fresh($l))), 'renewing a closed loan');
        $this->refused(fn () => $this->t(fn () => $this->svc()->recordPayment($this->fresh($l), 100)), 'paying a closed loan');
    }

    public function test_release_one_of_two_items_then_renew_moves_only_the_item_left(): void
    {
        $l = $this->mk([], [], [$this->chain('Chain A'), $this->chain('Chain B')]);
        $this->actingAs($this->owner);
        $a = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->orderBy('id')->firstOrFail();
        $this->t(function () use ($l, $a) {
            $this->svc()->accrueInterest($this->fresh($l));
            $this->svc()->releaseItem($this->fresh($l), $a, $this->svc()->releaseMinimum($this->fresh($l), $a), 'cash', 'ok');
        });
        $this->assertSame('active', $this->fresh($l)->status, 'one item is still pledged');
        $this->assertEquals(25000.0, (float) $this->fresh($l)->outstanding_principal);

        $new = $this->t(fn () => $this->svc()->renewLoan($this->fresh($l)));
        $this->assertSame('renewed', $this->fresh($l)->status);
        $this->assertEquals(25000.0, (float) $new->principal_amount);
        $this->assertSame(1, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $new->id)->where('status', 'pledged')->count());
        $this->assertSame(1, DB::table('dhiran_loan_items')->where('dhiran_loan_id', $l->id)->where('status', 'released')->count());
        $this->assertSame([], array_diff(array_keys($this->keys()), ['pending']));
    }

    public function test_settling_is_refused_in_the_lock_period_by_every_path_and_allowed_from_its_last_midnight(): void
    {
        $l = $this->mk([], ['min_lock_months' => 3]); // loan 1 Jun: locked until 1 Sep 00:00
        $this->actingAs($this->owner);
        $item = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->firstOrFail();
        $this->t(fn () => $this->svc()->accrueInterest($this->fresh($l)));
        $total = $this->fresh($l)->totalOutstanding(); // exactly what settles it (a bigger amount would be refused as an overpayment instead)
        $before = $this->payments($l);
        $this->refused(fn () => $this->t(fn () => $this->svc()->preCloseLoan($this->fresh($l))), 'pre-close in the lock period', 'lock period');
        $this->refused(fn () => $this->t(fn () => $this->svc()->recordPayment($this->fresh($l), $total)), 'a payment that settles in the lock period', 'lock period');
        $this->refused(fn () => $this->t(fn () => $this->svc()->releaseItem($this->fresh($l), $item, $total, 'cash', 'x')), 'releasing the last item in the lock period', 'lock period');
        $this->assertSame($before, $this->payments($l));
        $this->assertSame('active', $this->fresh($l)->status);

        $this->at('2026-08-31', '23:59');
        $this->refused(fn () => $this->t(fn () => $this->svc()->preCloseLoan($this->fresh($l))), 'one minute before the lock ends');
        $this->at('2026-09-01', '00:01');
        $this->assertNotNull($this->t(fn () => $this->svc()->preCloseLoan($this->fresh($l))));
        $this->assertSame('closed', $this->fresh($l)->status);
    }

    public function test_closing_early_keeps_the_advance_and_leaves_nothing_negative(): void
    {
        $l = $this->mk([], ['advance_interest_months' => 1]); // 1000.00 taken for 1 Jun - 1 Jul
        $this->at('2026-06-10');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc()->recordPayment($this->fresh($l), 50000));
        $f = $this->fresh($l);
        $this->assertSame('closed', $f->status);
        $this->assertGreaterThanOrEqual(0.0, (float) $f->interest_prepaid);
        $this->assertGreaterThanOrEqual(0.0, (float) $f->outstanding_interest);
        $this->assertSame(0, $this->payments($l, 'advance_refund') + $this->payments($l, 'interest_refund'), 'the shop keeps what was taken in advance');
        $this->assertSame([], $this->keys());
    }

    public function test_accrual_twice_the_same_day_and_by_the_scheduled_command_is_idempotent(): void
    {
        $l = $this->mk();
        $this->at('2026-06-20');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc()->accrueInterest($this->fresh($l)));
        $a = $this->fresh($l);
        $ledger = $this->ledgers($l);
        $this->t(fn () => $this->svc()->accrueInterest($this->fresh($l)));
        $this->assertSame(0, Artisan::call('dhiran:accrue-interest'));
        $this->assertSame(0, Artisan::call('dhiran:accrue-interest'));
        $b = $this->fresh($l);
        $this->assertEquals((float) $a->outstanding_interest, (float) $b->outstanding_interest);
        $this->assertSame($ledger, $this->ledgers($l), 'no second ledger line for the same day');
        $this->assertEqualsWithDelta(50000 * 0.02 / 30 * 19, (float) $b->outstanding_interest, 0.011);
        $this->assertSame('2026-06-20', $b->interest_accrued_through->toDateString());
    }

    public function test_accrual_moves_one_day_exactly_across_the_local_midnight(): void
    {
        $l = $this->mk();
        $this->actingAs($this->owner);
        $this->at('2026-06-10', '23:59');
        $this->t(fn () => $this->svc()->accrueInterest($this->fresh($l)));
        $this->assertEqualsWithDelta(300.00, (float) $this->fresh($l)->outstanding_interest, 0.011, '9 days');
        $this->at('2026-06-10', '23:59:59');
        $this->t(fn () => $this->svc()->accrueInterest($this->fresh($l)));
        $this->assertEqualsWithDelta(300.00, (float) $this->fresh($l)->outstanding_interest, 0.011);
        $this->at('2026-06-11', '00:01');
        $this->t(fn () => $this->svc()->accrueInterest($this->fresh($l)));
        $this->assertEqualsWithDelta(333.33, (float) $this->fresh($l)->outstanding_interest, 0.011, '10 days, not 9 and not 11');
        $this->assertSame('2026-06-11', $this->fresh($l)->interest_accrued_through->toDateString());
    }

    public function test_a_back_dated_loan_accrues_from_its_own_date_and_a_pending_one_does_not_accrue(): void
    {
        $l = $this->mk([], ['loan_date' => '2026-01-01']);
        $p = $this->mk(['status' => 'pending_evidence'], ['loan_date' => '2026-01-01']);
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc()->accrueInterest($this->fresh($l)));
        $this->t(fn () => $this->svc()->accrueInterest($this->fresh($p)));
        $this->assertEqualsWithDelta(50000 * 0.02 / 30 * 165, (float) $this->fresh($l)->outstanding_interest, 0.011, '1 Jan to 15 Jun is 165 days');
        $this->assertEquals(0.0, (float) $this->fresh($p)->outstanding_interest);
    }

    public function test_advance_balance_and_periods_stay_sane_through_part_payments_and_installments(): void
    {
        $l = $this->mk([], ['advance_interest_months' => 1]);
        $this->actingAs($this->owner);
        $steps = ['2026-06-10', '2026-06-20', '2026-07-01', '2026-07-10', '2026-08-02', '2026-08-30', '2026-09-15', '2026-12-01'];
        foreach ($steps as $i => $d) {
            $this->at($d);
            $this->t(fn () => $this->svc()->accrueInterest($this->fresh($l)));
            if ($i === 1) {
                $this->t(fn () => $this->svc()->recordPayment($this->fresh($l), 5000));
            }
            try {
                $this->t(fn () => $this->svc()->payInstallment($this->fresh($l)));
            } catch (LogicException $e) {
                $this->assertStringContainsString('not due yet', $e->getMessage());
            }
            $f = $this->fresh($l);
            $elapsed = 0;
            while ($f->loan_date->copy()->addMonthsNoOverflow($elapsed + 1)->lte(today())) {
                $elapsed++;
            }
            $this->assertGreaterThanOrEqual(0.0, (float) $f->interest_prepaid, "prepaid on {$d}");
            $this->assertGreaterThanOrEqual(0.0, (float) $f->outstanding_interest, "owed on {$d}");
            $this->assertLessThanOrEqual(max(1, $elapsed + 2), (int) $f->interest_periods_paid, "periods paid never run past the next due date (one week early is allowed) after today ({$d})");
            $this->assertLessThanOrEqual((float) $f->total_interest_collected + 0.01, (float) $f->interest_prepaid, 'the advance left is part of what was collected');
        }
    }

    // ── (7) loan numbers ──────────────────────────────────────────────────────────────

    private function setCounter(int $value, ?Shop $shop = null): void
    {
        $id = ($shop ?? $this->shop)->id;
        BusinessIdentifierService::nextCounter($id, BusinessIdentifierService::KEY_DHIRAN); // makes sure the row exists
        ShopCounter::query()->where('shop_id', $id)->where('counter_key', BusinessIdentifierService::KEY_DHIRAN)->update(['current_value' => $value]);
    }

    public function test_numbers_grow_past_the_digit_boundary_for_every_width(): void
    {
        foreach (range(1, 8) as $d) {
            DhiranSettings::getForShop($this->shop->id)->update(['loan_number_prefix' => 'DH-', 'loan_number_digits' => $d]);
            $this->setCounter(10 ** $d - 3);
            $got = [];
            foreach ([-2, -1, 0, 1] as $off) {
                $got[] = $this->svc()->generateLoanNumber($this->shop);
            }
            $want = array_map(fn ($off) => 'DH-' . str_pad((string) (10 ** $d + $off), $d, '0', STR_PAD_LEFT), [-2, -1, 0, 1]);
            $this->assertSame($want, $got, "{$d} digits");
            $this->assertSame(4, count(array_unique($got)));
        }
        DhiranSettings::getForShop($this->shop->id)->update(['loan_number_digits' => 1]);
        $this->setCounter(8);
        $this->assertSame(['DH-9', 'DH-10', 'DH-11'], [$this->svc()->generateLoanNumber($this->shop), $this->svc()->generateLoanNumber($this->shop), $this->svc()->generateLoanNumber($this->shop)]);
    }

    public function test_two_shops_count_on_their_own_and_changing_digits_midway_never_repeats_a_number(): void
    {
        $other = self::createDhiranShop(['name' => 'Other Co', 'shop_type' => 'dhiran', 'phone' => '9390025101', 'owner_first_name' => 'O', 'owner_last_name' => 'X',
            'owner_mobile' => '9390025101', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($other->id)->update(['is_enabled' => true, 'loan_number_prefix' => 'DH-', 'loan_number_digits' => 6]);
        DhiranSettings::getForShop($this->shop->id)->update(['loan_number_prefix' => 'DH-', 'loan_number_digits' => 6]);

        $a1 = $this->svc()->generateLoanNumber($this->shop);
        $b1 = $this->svc()->generateLoanNumber($other);
        $this->assertSame('DH-000001', $a1);
        $this->assertSame($a1, $b1, 'each shop has its own counter (unique per shop, not globally)');
        $this->assertSame('DH-000002', $this->svc()->generateLoanNumber($this->shop));
        $this->assertSame('DH-000002', $this->svc()->generateLoanNumber($other));

        $seen = [$a1, 'DH-000002'];
        foreach ([1, 3, 6, 2, 8] as $digits) { // narrowing and widening the pad
            DhiranSettings::getForShop($this->shop->id)->update(['loan_number_digits' => $digits]);
            $seen[] = $this->svc()->generateLoanNumber($this->shop);
        }
        $this->assertSame($seen, array_values(array_unique($seen)));
    }

    // Defect: the prefix may end in digits ("DH-1", allowed by the settings regex) and the number is prefix + unpadded
    // counter, so "DH-1"+"1" (counter 1) and "DH-"+"11" (counter 11, after the prefix was changed back) are the same
    // string; unique(shop_id, loan_number) then makes createLoan fail with a database error (DhiranService.php:2170-2174).
    public function test_BUG_changing_the_prefix_can_generate_a_number_that_already_exists(): void
    {
        DhiranSettings::getForShop($this->shop->id)->update(['loan_number_prefix' => 'DH-1', 'loan_number_digits' => 1]);
        $this->setCounter(0);
        $first = $this->mk();
        $this->assertSame('DH-11', $first->loan_number);

        DhiranSettings::getForShop($this->shop->id)->update(['loan_number_prefix' => 'DH-', 'loan_number_digits' => 1]);
        $this->setCounter(10);
        $second = $this->mk();
        $this->assertNotSame($first->loan_number, $second->loan_number, 'a new loan must never reuse a number');
    }

    // Defect: onboarding accepts a loan_number_prefix of up to 12 characters (DhiranOnboardingController.php:38,75) but the column is varchar(10).
    public function test_BUG_the_prefix_column_fits_what_onboarding_allows(): void
    {
        $len = (int) DB::table('information_schema.columns')->where('table_name', 'dhiran_settings')->where('column_name', 'loan_number_prefix')->value('character_maximum_length');
        $this->assertGreaterThanOrEqual(10, $len, 'the longest prefix the forms accept (10) must fit the column');
        $this->assertGreaterThanOrEqual(10 + 8, (int) DB::table('information_schema.columns')->where('table_name', 'dhiran_loans')->where('column_name', 'loan_number')->value('character_maximum_length'));
    }

    // Defect: createLoan takes up to 3 months of interest in advance whatever the tenure (DhiranService.php:292-306), while
    // renewLoan caps the months at the new tenure (DhiranService.php:1691). A 1-month loan paid 3 months ahead has a next installment after maturity.
    public function test_BUG_advance_months_cannot_exceed_the_tenure(): void
    {
        $this->expectException(LogicException::class);
        $this->mk([], ['tenure_months' => 1, 'advance_interest_months' => 3]);
    }

    // ── (8) idempotency, ended / suspended shops, scheduled commands ───────────────────

    public function test_pay_installment_twice_with_the_same_key_records_once_and_a_new_key_is_refused_until_the_next_due_date(): void
    {
        $l = $this->mk([], ['loan_date' => '2026-05-15', 'advance_interest_months' => 1]); // installment due today
        $url = self::HOST . "/dhiran/loans/{$l->id}/pay-installment";
        $form = ['payment_method' => 'cash', 'idempotency_key' => 'inst-key-0001'];
        $this->assertSame(1, $this->payments($l, 'advance_interest'));

        $this->actingAs($this->owner)->post($url, $form)->assertSessionHas('success', 'Installment recorded.');
        $this->assertSame(2, $this->payments($l, 'advance_interest'));
        $this->assertSame(2, (int) $this->fresh($l)->interest_periods_paid);
        $this->actingAs($this->owner)->post($url, $form)->assertSessionHas('success', \App\Http\Middleware\DhiranSubmitOnce::ALREADY);
        $this->assertSame(2, $this->payments($l, 'advance_interest'), 'the replay records nothing');

        $this->actingAs($this->owner)->post($url, ['idempotency_key' => 'inst-key-0002'] + $form)->assertSessionHas('error');
        $this->assertSame(2, $this->payments($l, 'advance_interest'), 'the next month is not due for 30 days');
        $this->assertSame(2, (int) $this->fresh($l)->interest_periods_paid);
        $this->assertSame(0, DB::table('idempotency_keys')->where('key', 'dhiran:inst-key-0002')->count(), 'a refused attempt releases its key');
    }

    public function test_the_service_refuses_a_second_installment_the_same_day(): void
    {
        $l = $this->mk([], ['loan_date' => '2026-05-15', 'advance_interest_months' => 1]);
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc()->payInstallment($this->fresh($l)));
        $this->refused(fn () => $this->t(fn () => $this->svc()->payInstallment($this->fresh($l))), 'the same installment twice');
        $this->assertSame(2, $this->payments($l, 'advance_interest'));
    }

    public static function blockedShops(): array
    {
        return ['term ended' => ['lapsed', 'dhiran.expired'], 'suspended' => ['suspended', 'dhiran.blocked'], 'awaiting approval' => ['pending', 'dhiran.pending']];
    }

    #[DataProvider('blockedShops')]
    public function test_a_blocked_shop_cannot_pay_an_installment_and_burns_no_key(string $state, string $route): void
    {
        $l = $this->mk([], ['loan_date' => '2026-05-15', 'advance_interest_months' => 1]);
        $this->blockShop($state);
        $this->actingAs($this->owner)->post(self::HOST . "/dhiran/loans/{$l->id}/pay-installment", ['payment_method' => 'cash', 'idempotency_key' => 'blocked-key-01'])
            ->assertRedirect(route($route));
        $this->assertSame(1, $this->payments($l, 'advance_interest'), 'only the loan-day advance');
        $this->assertSame(1, (int) $this->fresh($l)->interest_periods_paid);
        $this->assertSame(0, DB::table('idempotency_keys')->where('key', 'dhiran:blocked-key-01')->count());
    }

    private function blockShop(string $state): void
    {
        if ($state === 'lapsed') {
            $this->lapse();
        } else {
            Shop::withoutGlobalScopes()->whereKey($this->shop->id)->update(['status' => $state]);
        }
    }

    #[DataProvider('blockedShops')]
    public function test_the_nightly_jobs_still_accrue_for_a_blocked_shop_but_never_forfeit_for_it(string $state): void
    {
        $accruing = $this->mk(); // 1 Jun, nothing accrued yet
        $ready = $this->mk(['maturity_date' => '2026-05-01', 'grace_period_days' => 0, 'forfeiture_notice_sent_at' => '2026-05-10 10:00:00', 'forfeiture_deadline' => '2026-06-01']);
        $notice = $this->mk(['maturity_date' => '2026-05-01', 'grace_period_days' => 0]);
        $this->blockShop($state);

        $this->assertSame(0, Artisan::call('dhiran:accrue-interest'));
        $this->assertEqualsWithDelta(50000 * 0.02 / 30 * 14, (float) $this->fresh($accruing)->outstanding_interest, 0.02, 'interest runs on whatever the shop state: the loan is owed');
        $this->assertSame('2026-06-15', $this->fresh($accruing)->interest_accrued_through->toDateString());

        $this->assertSame(0, Artisan::call('dhiran:forfeiture-check'));
        $out = Artisan::output();
        $this->assertStringContainsString("READY FOR FORFEITURE: Loan #{$ready->loan_number}", $out);
        $this->assertStringContainsString("NEEDS FORFEITURE NOTICE: Loan #{$notice->loan_number}", $out);
        foreach ([$ready, $notice, $accruing] as $x) {
            $this->assertSame('active', $this->fresh($x)->status, 'a person decides forfeiture, the schedule only reports');
        }
        $this->assertNull($this->fresh($notice)->forfeiture_notice_sent_at, 'and never sends the legal notice by itself');
        $this->assertSame(0, $this->ledgers($ready, 'forfeiture'));
    }

    // ── (9) bell rows == the loans the linked list page shows ────────────────────────────

    public function test_every_bell_row_links_to_a_list_with_exactly_the_loans_counted(): void
    {
        $inst = fn (string $due) => ['advance_interest_months' => 1, 'interest_periods_paid' => 1, 'loan_date' => Carbon::parse($due)->subMonthNoOverflow()->toDateString()];
        $mat = fn (string $day) => ['maturity_date' => $day, 'grace_period_days' => 90];
        $sets = [
            ['status' => 'pending_evidence'], ['status' => 'pending_evidence'],
            $inst('2026-06-14'), $inst('2026-05-02'), $inst('2026-06-15'), $inst('2026-06-18'), $inst('2026-06-19'), $inst('2026-06-22'), $inst('2026-06-23'), $inst('2026-07-15'), $inst('2026-07-16'),
            $mat('2026-06-15'), $mat('2026-06-18'), $mat('2026-06-19'), $mat('2026-06-22'), $mat('2026-06-23'), $mat('2026-07-15'), $mat('2026-07-16'), $mat('2026-06-14'),
            ['maturity_date' => '2026-06-25', 'grace_period_days' => 5], ['maturity_date' => '2026-06-10', 'grace_period_days' => 5],
            ['maturity_date' => '2026-06-01', 'grace_period_days' => 5], ['maturity_date' => '2026-06-09', 'grace_period_days' => 5],
            ['maturity_date' => '2026-05-01', 'grace_period_days' => 0, 'forfeiture_notice_sent_at' => '2026-05-20 10:00:00', 'forfeiture_deadline' => '2026-06-15'],
            ['maturity_date' => '2026-05-01', 'grace_period_days' => 0, 'forfeiture_notice_sent_at' => '2026-05-20 10:00:00', 'forfeiture_deadline' => '2026-06-01'],
            ['maturity_date' => '2026-05-01', 'grace_period_days' => 0, 'forfeiture_notice_sent_at' => '2026-06-10 10:00:00', 'forfeiture_deadline' => '2026-07-10'],
        ];
        $expected = [];
        $byNumber = [];
        foreach ($sets as $set) {
            $l = $this->mk($set);
            $byNumber[$l->loan_number] = $l->id;
            foreach ($this->expectedKeys($l) as $key) {
                $expected[$key][] = $l->loan_number;
            }
        }
        Notices::forget();
        $rows = collect(Notices::forShop($this->shop->id))->where('key', '!=', 'plan_ending')->keyBy('key');

        $this->assertEqualsCanonicalizing(array_keys($expected), $rows->keys()->all(), 'the bell shows exactly the rows that have loans');
        foreach ($expected as $key => $numbers) {
            sort($numbers);
            $row = $rows[$key];
            $this->assertSame(count($numbers), $row['count'], "{$key} count");
            if (count($numbers) === 1) {
                $this->assertSame(route('dhiran.show', $byNumber[$numbers[0]]), $row['url'], "{$key} links to its only loan");

                continue;
            }
            $html = $this->actingAs($this->owner)->get($row['url'])->assertOk()->getContent();
            $this->assertSame($numbers, $this->numbers($html), "{$key}: the list behind {$row['url']}");
        }
    }
}
