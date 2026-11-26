<?php
namespace Tests\Feature;

use App\Http\Middleware\DhiranSubmitOnce;
use App\Mail\EmailOtpMail;
use App\Mail\ShopDecisionMail;
use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Services\Dhiran\Notices;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Admin\AdminTestCase;

/**
 * End to end, through the real routes only: a new shop's whole life (sign up, email code, business form, admin approval, first loan and payment),
 * the rejection loop, the end of the one-year term, and suspension. A fixture is used only where no screen can do it (activating a loan needs
 * uploaded photos, so the status is switched in the database, the way AdvanceInterestFormTest does).
 */
class JourneyTest extends AdminTestCase
{
    private const PASS = 'Local-pass-12345';
    private const LOAN_AGE_DAYS = 45;   // loans are back-dated so interest has already built up (2% flat on 100000 for 45 days = 3000)
    private bool $adminIn = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();   // rate-limiter counters live in the file cache and would leak between tests
        Storage::fake('local');
        Mail::fake();
        (new PlanSeeder)->run();
        config(['app.debug' => false]);
        Notices::forget();
    }

    protected function tearDown(): void
    {
        Notices::forget();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ───────── helpers (never named get/post/put/patch/delete/call/json) ───────── */

    /** The shop owner (or staff) for the next request. */
    private function own(User $u): static
    {
        Auth::shouldUse('web');
        Notices::forget();   // the bell is memoised per process: a test that changes loans between requests must not read a stale answer

        return $this->actingAs($u->fresh());
    }

    /** The platform admin for the next request. Signs in once per test (the TOTP code is single-use per 30 s window). */
    private function adm(): static
    {
        if (! $this->adminIn) {
            Auth::guard('web')->logout();
            PlatformAdmin::query()->update(['last_totp_window' => 0]);
            $this->signIn($this->admin());
            $this->adminIn = true;
        }
        Auth::shouldUse('platform_admin');

        return $this;
    }

    /** Register, verify the email with the mailed code, send the business form: the shop now waits. @return array{0:User,1:Shop} */
    private function signUp(string $email = 'journey@onboard.test', string $mobile = '9812300077', string $shopName = 'Journey Gold'): array
    {
        Auth::shouldUse('web'); Auth::guard('web')->logout();
        $this->post('/register', ['name' => 'Journey Owner', 'email' => $email, 'mobile' => $mobile, 'password' => self::PASS, 'password_confirmation' => self::PASS, 'terms' => '1'])
            ->assertRedirect('/')->assertSessionHasNoErrors();
        $user = User::where('email', $email)->sole();
        $this->assertNull($user->shop_id);
        $this->assertNull($user->email_verified_at);

        // an unverified owner is sent to the verify page by every way into the software
        $this->own($user)->get('/')->assertRedirect(route('dhiran.dashboard'));
        $this->own($user)->get('/dhiran')->assertRedirect(route('dhiran.verify-email'));
        $this->own($user)->get(route('dhiran.verify-email'))->assertOk();

        $this->own($user)->postJson(route('dhiran.verify-email.send'), ['email' => $email])->assertOk();
        $code = null;
        Mail::assertSent(EmailOtpMail::class, function ($m) use (&$code) { $code = $m->otp; return true; });   // the last code mailed
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $this->own($user)->postJson(route('dhiran.verify-email.verify'), ['otp' => $code])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->own($user)->get('/dhiran')->assertRedirect(route('dhiran.onboarding'));
        $this->own($user)->post(route('dhiran.onboarding.store'), ['name' => $shopName, 'phone' => '9876543210', 'address' => '12 Main Road, Indore', 'loan_number_prefix' => 'JG'])
            ->assertRedirect(route('dhiran.pending'));
        $shop = Shop::findOrFail($user->fresh()->shop_id);
        $this->assertSame('pending', $shop->status);
        $this->own($user)->get(route('dhiran.pending'))->assertOk()->assertSee('waiting for approval')->assertSee($shopName);
        $this->own($user)->get('/dhiran/loans')->assertRedirect(route('dhiran.pending'));

        return [$user->fresh(), $shop];
    }

    private function approve(Shop $shop): void
    {
        $this->adm()->post('/admin/requests/' . $shop->id . '/approve')->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame('active', $shop->fresh()->status);
        Mail::assertSent(ShopDecisionMail::class, fn ($m) => $m->approved === true);
    }

    private function settingsForm(array $over = []): array
    {
        return $over + [
            'default_interest_rate_monthly' => 2, 'default_interest_type' => 'flat', 'default_penalty_rate_monthly' => 0, 'default_min_interest_months' => 1,
            'default_ltv_ratio' => 75, 'high_value_threshold' => 200000, 'high_value_ltv_ratio' => 65, 'min_loan_amount' => 1000, 'max_loan_amount' => 900000,
            'processing_fee_type' => 'flat', 'processing_fee_value' => 0, 'default_tenure_months' => 6, 'default_min_lock_months' => 0,
            'grace_period_days' => 30, 'forfeiture_notice_days' => 15, 'loan_number_prefix' => 'JG-',
        ];
    }

    private function loanForm(Customer $c, string $key, array $over = []): array
    {
        return $over + ['customer_id' => $c->id, 'principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2,
            'interest_type' => 'flat', 'tenure_months' => 6, 'loan_date' => today()->subDays(self::LOAN_AGE_DAYS)->toDateString(),
            'aadhaar' => '1234 5678 9012', 'idempotency_key' => $key,
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 24, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000]]];
    }

    private function payForm(string $key, int $amount = 500): array
    {
        return ['amount' => $amount, 'payment_method' => 'cash', 'idempotency_key' => $key];
    }

    /** The customer through the real Add customer route. */
    private function makeCustomer(User $owner, string $first = 'Ram', string $mobile = '9815630100'): Customer
    {
        $r = $this->own($owner)->post('/dhiran/customers', ['first_name' => $first, 'last_name' => 'Lal', 'mobile' => $mobile, 'address' => 'Station Road, Indore']);
        $r->assertSessionHasNoErrors();
        $c = Customer::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->where('first_name', $first)->sole();
        $r->assertRedirect(route('dhiran.borrowers.show', $c));

        return $c;
    }

    /** The loan through the real New Loan form: it starts as pending_evidence. */
    private function openLoan(User $owner, Customer $c, string $key): DhiranLoan
    {
        $r = $this->own($owner)->post('/dhiran', $this->loanForm($c, $key));
        $r->assertSessionHasNoErrors();
        $loan = DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->orderByDesc('id')->firstOrFail();
        $r->assertRedirect(route('dhiran.show', $loan));
        $this->assertSame('pending_evidence', $loan->status);

        return $loan;
    }

    /** The one fixture: the evidence photos need file uploads, so the loan is switched to active in the database (as AdvanceInterestFormTest does). */
    private function activate(DhiranLoan $l): void
    {
        DhiranLoan::withoutGlobalScopes()->whereKey($l->id)->update(['status' => 'active']);
        Notices::forget();
    }

    /** The whole sign-up to first loan. @return array{0:Shop,1:User,2:?Customer,3:?DhiranLoan} */
    private function liveShop(bool $withLoan = true): array
    {
        [$owner, $shop] = $this->signUp();
        $this->adm()->get('/admin/requests')->assertOk()->assertSee('Journey Gold')->assertSee('9876543210')->assertSee($owner->email);
        $this->approve($shop);
        $owner = $owner->fresh();
        $this->own($owner)->get(route('dhiran.pending'))->assertRedirect('/');
        $this->own($owner)->get('/dhiran/loans')->assertOk();
        $this->own($owner)->patch('/dhiran/settings', $this->settingsForm())->assertSessionHasNoErrors()->assertRedirect(route('dhiran.settings'));
        if (! $withLoan) { return [$shop->fresh(), $owner, null, null]; }
        $cust = $this->makeCustomer($owner);
        $loan = $this->openLoan($owner, $cust, 'jny-loan-0001');

        return [$shop->fresh(), $owner, $cust, $loan];
    }

    private function counts(): array
    {
        return ['loans' => DB::table('dhiran_loans')->count(), 'payments' => DB::table('dhiran_payments')->count(), 'customers' => DB::table('customers')->count()];
    }

    private function paymentsOf(DhiranLoan $loan, string $type): int
    {
        return DB::table('dhiran_payments')->where('dhiran_loan_id', $loan->id)->where('type', $type)->count();
    }

    /* ───────────────────────────── J1: the whole new-shop life ───────────────────────────── */

    public function test_j1_a_new_shop_goes_from_sign_up_to_its_first_payment(): void
    {
        [$shop, $owner, $cust, $loan] = $this->liveShop();

        // the loan the form made: money out, one ledger line, masked Aadhaar
        $this->assertSame(1, $this->paymentsOf($loan, 'disbursement'));
        $this->assertSame(1, DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $loan->id)->where('entry_type', 'disbursement')->count());
        $this->assertSame(1, DB::table('dhiran_cash_entries')->where('dhiran_loan_id', $loan->id)->where('type', 'out')->count());
        $this->assertStringContainsString('9012', (string) $loan->kyc_aadhaar);
        $this->assertStringNotContainsString('5678', (string) $loan->kyc_aadhaar, 'the full Aadhaar number is never stored');

        // the bell shows the loan that waits for evidence, and the list page says so
        Notices::forget();
        $row = collect(Notices::forShop($shop->id))->firstWhere('key', 'pending');
        $this->assertNotNull($row);
        $this->assertSame(1, $row['count']);
        $this->assertSame(route('dhiran.show', $loan->id), $row['url'], 'one loan: the row links straight to it');
        $this->own($owner)->get('/dhiran/loans')->assertOk()->assertSee('1 loan waiting for evidence')->assertSee($loan->loan_number);

        // a payment cannot be taken on a loan that is not active yet
        $this->own($owner)->post(route('dhiran.pay-interest', $loan), $this->payForm('jny-pay-0000'))->assertSessionHas('error');
        $this->assertSame(0, $this->paymentsOf($loan, 'interest_payment'));

        // once active the waiting row goes and the interest payment goes through the real route
        $this->activate($loan);
        $this->assertNull(collect(Notices::forShop($shop->id))->firstWhere('key', 'pending'));
        $pay = $this->payForm('jny-pay-0001');
        $this->own($owner)->post(route('dhiran.pay-interest', $loan), $pay)->assertRedirect(route('dhiran.show', $loan))->assertSessionHas('success', 'Interest payment recorded.');

        $this->assertSame(1, $this->paymentsOf($loan, 'interest_payment'));
        $payment = DB::table('dhiran_payments')->where('dhiran_loan_id', $loan->id)->where('type', 'interest_payment')->first();
        $this->assertEqualsWithDelta(500, (float) $payment->amount, 0.001);
        $this->assertSame('in', $payment->direction);
        $this->assertGreaterThanOrEqual(1, DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $loan->id)->where('entry_type', 'interest_accrual')->count(), 'interest was booked before it was paid');
        $this->assertEqualsWithDelta(500, (float) DB::table('dhiran_cash_entries')->where('dhiran_loan_id', $loan->id)->where('type', 'in')->sum('amount'), 0.001);
        $this->assertEqualsWithDelta(2500, (float) $loan->fresh()->outstanding_interest, 0.011, '3000 of interest accrued, 500 paid');

        // the same form sent twice is recorded once
        $this->own($owner)->post(route('dhiran.pay-interest', $loan), $pay)->assertSessionHas('success', DhiranSubmitOnce::ALREADY);
        $this->assertSame(1, $this->paymentsOf($loan, 'interest_payment'));

        // and the pages that show it
        $this->own($owner)->get(route('dhiran.show', $loan))->assertOk()->assertSee($loan->loan_number);
        $this->own($owner)->get(route('dhiran.payment-receipt', [$loan, $payment->id]))->assertOk()->assertSee('Payment Receipt');
        $this->own($owner)->get('/dhiran/loans')->assertOk()->assertSee($loan->loan_number);
    }

    /* ───────────────────────────── J2: the rejection loop ───────────────────────────── */

    public function test_j2_a_turned_down_shop_fixes_its_phone_is_seen_again_and_gets_in(): void
    {
        [$owner, $shop] = $this->signUp('loop@onboard.test', '9812300066', 'Loop Gold');

        $this->adm()->post('/admin/requests/' . $shop->id . '/reject', ['reason' => 'Phone number is wrong'])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame('rejected', $shop->fresh()->status);
        Mail::assertSent(ShopDecisionMail::class, fn ($m) => $m->approved === false && $m->note === 'Phone number is wrong');

        // the owner sees the reason and the form (prefilled), and is still kept out
        $this->own($owner)->get(route('dhiran.pending'))->assertOk()->assertSee('could not approve')->assertSee('Phone number is wrong')
            ->assertSee('Fix and send again')->assertSee('Loop Gold')->assertSee('9876543210');
        $this->own($owner)->get('/dhiran/loans')->assertRedirect(route('dhiran.pending'));

        // a still-wrong phone is refused and the shop stays turned down
        $this->own($owner)->post(route('dhiran.resubmit'), ['name' => 'Loop Gold', 'phone' => '12345', 'address' => '12 Main Road, Indore'])->assertSessionHasErrors('phone');
        $this->assertSame('rejected', $shop->fresh()->status);

        // the corrected phone sends it again
        $this->own($owner)->post(route('dhiran.resubmit'), ['name' => 'Loop Gold', 'phone' => '9876500099', 'address' => '12 Main Road, Indore'])
            ->assertRedirect(route('dhiran.pending'))->assertSessionHas('success', 'Sent again. We will check it and email you.');
        $shop = $shop->fresh();
        $this->assertSame('pending', $shop->status);
        $this->assertSame('9876500099', $shop->phone);
        $this->assertSame(2, (int) $shop->submissions);
        $this->assertSame('Phone number is wrong', $shop->previous_review_note);
        $this->own($owner)->get(route('dhiran.pending'))->assertOk()->assertSee('waiting for approval')->assertSee('9876500099');

        // the admin sees it as sent again, with the earlier reason
        $this->adm()->get('/admin/requests')->assertOk()->assertSee('Sent again (2)')->assertSee('Turned down before')->assertSee('Phone number is wrong')->assertSee('9876500099');
        $this->approve($shop);

        // and the owner is in
        $this->own($owner)->get(route('dhiran.pending'))->assertRedirect('/');
        $this->own($owner)->get('/dhiran/loans')->assertOk();
        $this->assertSame(1, ShopSubscription::where('shop_id', $shop->id)->count());
        Mail::assertSent(ShopDecisionMail::class, 2);
    }

    /* ───────────────────────────── J3: the year ends ───────────────────────────── */

    public function test_j3_when_the_year_ends_every_write_is_blocked_interest_still_runs_and_an_extension_reopens_it(): void
    {
        [$shop, $owner, $cust, $loan] = $this->liveShop();
        $this->activate($loan);
        $loanDate = today()->subDays(self::LOAN_AGE_DAYS)->toDateString();
        $this->assertSame(0, DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $loan->id)->where('entry_type', 'interest_accrual')->count());

        // the term ended yesterday (no time travel: the admin sign-in code is tied to the real clock)
        ShopSubscription::where('shop_id', $shop->id)->update(['ends_at' => today()->subDay(), 'grace_ends_at' => today()->subDay()]);
        $this->assertFalse($shop->fresh()->hasEdition('dhiran'));
        $before = $this->counts();
        $ended = route('dhiran.expired');
        $pay = $this->payForm('jny-pay-0003');

        $this->own($owner)->post('/dhiran', $this->loanForm($cust, 'jny-loan-blocked'))->assertRedirect($ended);
        $this->own($owner)->post(route('dhiran.pay-interest', $loan), $pay)->assertRedirect($ended);
        $this->own($owner)->post(route('dhiran.repay', $loan), $this->payForm('jny-repay-0001'))->assertRedirect($ended);
        $this->own($owner)->post('/dhiran/customers', ['first_name' => 'Sita', 'mobile' => '9815630111'])->assertRedirect($ended);
        $this->own($owner)->patch('/dhiran/settings', $this->settingsForm(['default_interest_rate_monthly' => 3]))->assertRedirect($ended);
        $this->own($owner)->get('/dhiran/loans')->assertRedirect($ended);   // reading is blocked too

        $this->assertSame($before, $this->counts(), 'a blocked shop writes nothing');
        $this->assertEquals(2, DB::table('dhiran_settings')->where('shop_id', $shop->id)->value('default_interest_rate_monthly'));
        $this->own($owner)->get($ended)->assertOk()->assertSee('Your access has ended')->assertSee('Journey Gold');

        // interest follows the loan: the daily job still accrues for the blocked shop
        $this->assertSame($loanDate, Carbon::parse(DB::table('dhiran_loans')->where('id', $loan->id)->value('interest_accrued_through'))->toDateString());
        $this->artisan('dhiran:accrue-interest')->assertExitCode(0);
        $row = DB::table('dhiran_loans')->where('id', $loan->id)->first();
        $this->assertSame(today()->toDateString(), Carbon::parse($row->interest_accrued_through)->toDateString());
        $this->assertEqualsWithDelta(3000, (float) $row->outstanding_interest, 0.011);
        $this->assertSame(1, DB::table('dhiran_ledger_entries')->where('dhiran_loan_id', $loan->id)->where('entry_type', 'interest_accrual')->count());
        $this->assertSame($before['payments'], $this->counts()['payments'], 'accrual books interest owed, it is not a payment');

        // the admin extends: the year runs from today, and the SAME payment form now goes through
        $this->adm()->post('/admin/shops/' . $shop->id . '/extend')->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertTrue($shop->fresh()->hasEdition('dhiran'));
        $this->assertSame(today()->addYear()->toDateString(), $shop->fresh()->currentSubscription()->ends_at->toDateString());
        $this->own($owner)->get($ended)->assertRedirect('/');
        $this->own($owner)->post(route('dhiran.pay-interest', $loan), $pay)->assertRedirect(route('dhiran.show', $loan))->assertSessionHas('success', 'Interest payment recorded.');
        $this->assertSame(1, $this->paymentsOf($loan, 'interest_payment'));
        $this->assertSame($before['payments'] + 1, $this->counts()['payments']);
        $this->own($owner)->get('/dhiran/loans')->assertOk();
    }

    /* ───────────────────────────── J4: suspension ───────────────────────────── */

    public function test_j4_suspending_blocks_owner_and_staff_and_only_an_active_shop_can_be_suspended(): void
    {
        [$shop, $owner] = $this->liveShop(false);
        $staff = self::userWithRole($shop, ['name' => 'Staff One', 'mobile_number' => '9815630222', 'email' => 'staff@journey.test', 'password' => 'a-long-test-password',
            'is_active' => true, 'email_verified_at' => now(), 'realm' => 'dhiran'], 'staff');
        $this->own($owner)->get('/dhiran/loans')->assertOk();
        $this->own($staff)->get('/dhiran/loans')->assertOk();

        // a reason is needed, then the whole shop is shut
        $this->adm()->patch('/admin/shops/' . $shop->id, ['status' => 'suspended'])->assertSessionHasErrors('reason');
        $this->assertSame('active', $shop->fresh()->status);
        $this->adm()->patch('/admin/shops/' . $shop->id, ['status' => 'suspended', 'reason' => 'Non-payment'])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $shop->fresh()->status);
        $customers = DB::table('customers')->count();
        foreach ([$owner, $staff] as $person) {
            $this->own($person)->get('/dhiran/loans')->assertRedirect(route('dhiran.blocked'));
            $this->own($person)->post('/dhiran/customers', ['first_name' => 'Nobody', 'mobile' => '9815630333'])->assertRedirect(route('dhiran.blocked'));
        }
        $this->assertSame($customers, DB::table('customers')->count());
        $this->own($owner)->get(route('dhiran.blocked'))->assertOk()->assertSee('suspended');

        // unsuspending restores both
        $this->adm()->patch('/admin/shops/' . $shop->id, ['status' => 'active', 'reason' => 'Paid'])->assertSessionHasNoErrors();
        $this->assertSame('active', $shop->fresh()->status);
        $this->own($owner)->get('/dhiran/loans')->assertOk();
        $this->own($staff)->get('/dhiran/loans')->assertOk();
        $this->assertSame(2, DB::table('platform_audit_logs')->where('action', 'shop.updated')->where('target_id', $shop->id)->count());

        // a waiting shop is opened only by Approve: neither switch works on it, and it stays pending
        [$waiting, $waitingShop] = $this->signUp('second@onboard.test', '9812300078', 'Second Gold');
        $audits = DB::table('platform_audit_logs')->count();
        $this->adm()->patch('/admin/shops/' . $waitingShop->id, ['status' => 'suspended', 'reason' => 'Trying'])->assertSessionHasErrors('status');
        $this->adm()->patch('/admin/shops/' . $waitingShop->id, ['status' => 'active', 'reason' => 'Trying to skip approval'])->assertSessionHasErrors('status');
        $this->assertSame('pending', $waitingShop->fresh()->status);
        $this->assertSame(0, ShopSubscription::where('shop_id', $waitingShop->id)->count(), 'no access granted without Approve');
        $this->assertSame($audits, DB::table('platform_audit_logs')->count());
        $this->own($waiting)->get('/dhiran/loans')->assertRedirect(route('dhiran.pending'));
    }
}
