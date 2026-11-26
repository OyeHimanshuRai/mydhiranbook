<?php
namespace Tests\Feature\Adversarial;

use App\Models\Customer;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\Dhiran\Notices;
use App\Support\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\AdminTestCase;

/**
 * App\Http\Middleware\RejectBadInput: control bytes (NUL, bell, escape, DEL ...) and broken UTF-8 anywhere in the input are refused
 * with a validation error on the key "input" (HTML: back with a session error; JSON: 422) before any controller or database sees them.
 * Tab, newline and carriage return stay allowed for multi-line notes; uploaded files are never inspected.
 */
class InputGuardTest extends AdminTestCase
{
    /** A NUL byte and a broken UTF-8 sequence. */
    private const BAD = ["A\0B", "\xC3\x28"];

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

    protected function tearDown(): void { Notices::forget(); parent::tearDown(); }

    /* ───────── helpers (never named get/post/put/patch/delete/call/json) ───────── */

    private function own(User $u): static { Auth::shouldUse('web'); Notices::forget(); return $this->actingAs($u->fresh()); }
    private function adm(): static { Auth::shouldUse('platform_admin'); return $this; }

    /** A shop with an active year, its owner (every permission) and one customer. @return array{0:Shop,1:User,2:Customer} */
    private function shopFor(string $mobile = '9390024200', string $name = 'Guard Co'): array
    {
        $shop = self::createDhiranShop(['name' => $name, 'shop_type' => 'dhiran', 'phone' => $mobile, 'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $mobile, 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $owner = self::userWithRole($shop, ['name' => 'Owner', 'mobile_number' => $mobile, 'email' => "o{$mobile}@guard.test", 'password' => 'a-long-test-password', 'is_active' => true, 'email_verified_at' => now(), 'realm' => 'dhiran']);
        $cust = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'Ram', 'last_name' => 'Lal', 'mobile' => '9815' . substr($mobile, -6)]));

        return [$shop, $owner, $cust];
    }

    /** The request was turned away by the guard: a redirect (never a 500) carrying a session error on "input". */
    private function refused(TestResponse $r, string $what): void
    {
        $this->assertLessThan(500, $r->getStatusCode(), $what);
        $this->assertSame(302, $r->getStatusCode(), $what . ' should go back with an error');
        $r->assertSessionHasErrors('input');
    }

    private function rows(string $table, array $where = []): int { return DB::table($table)->where($where)->count(); }

    private function settingsForm(array $over = []): array
    {
        return $over + [
            'default_interest_rate_monthly' => 2, 'default_interest_type' => 'flat', 'default_penalty_rate_monthly' => 0, 'default_min_interest_months' => 1,
            'default_ltv_ratio' => 75, 'high_value_threshold' => 200000, 'high_value_ltv_ratio' => 65, 'min_loan_amount' => 1000, 'max_loan_amount' => 900000,
            'processing_fee_type' => 'flat', 'processing_fee_value' => 0, 'default_tenure_months' => 6, 'default_min_lock_months' => 0,
            'grace_period_days' => 30, 'forfeiture_notice_days' => 15, 'loan_number_prefix' => 'DH-',
        ];
    }

    private function loanForm(Customer $c, array $over = []): array
    {
        return $over + ['customer_id' => $c->id, 'principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2,
            'interest_type' => 'flat', 'tenure_months' => 6, 'idempotency_key' => 'guard-' . uniqid(),
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 24, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000]]];
    }

    /* ───────────────────────────── owner forms ───────────────────────────── */

    public function test_the_customer_form_refuses_bad_text_in_every_field_and_saves_no_customer(): void
    {
        [, $owner] = $this->shopFor();
        $before = $this->rows('customers');
        foreach (self::BAD as $bad) {
            foreach (['first_name', 'last_name', 'address', 'notes'] as $field) {
                $r = $this->own($owner)->post('/dhiran/customers', [$field => $bad] + ['first_name' => 'Sita', 'mobile' => '9815630199']);
                $this->refused($r, $field . ' ' . bin2hex($bad));
            }
            // the guard also reads the names of the fields, not only their values
            $this->refused($this->own($owner)->post('/dhiran/customers', ['first_name' => 'Sita', 'mobile' => '9815630199', "k{$bad}ey" => 'x']), 'field name ' . bin2hex($bad));
        }
        $this->assertSame($before, $this->rows('customers'));

        // control: the same form with clean text goes through, so the refusals above came from the guard
        $notes = "Gold chain\n\tcollected at the counter\r\nwill return in March";
        $this->own($owner)->post('/dhiran/customers', ['first_name' => 'Sita', 'mobile' => '9815630199', 'notes' => $notes])->assertSessionHasNoErrors();
        $this->assertSame($before + 1, $this->rows('customers'));
        $this->assertSame(str_replace("\r\n", "\n", $notes), str_replace("\r\n", "\n", (string) DB::table('customers')->where('first_name', 'Sita')->value('notes')));
    }

    /** There is no POST /dhiran/borrowers route (a borrower is saved by /dhiran/customers), so a POST there is a 405 and saves nothing. */
    public function test_there_is_no_borrower_post_route_to_misuse(): void
    {
        [, $owner] = $this->shopFor();
        $before = $this->rows('customers');
        $r = $this->own($owner)->post('/dhiran/borrowers', ['first_name' => "A\0B", 'mobile' => '9815630199']);
        $this->assertLessThan(500, $r->getStatusCode());
        $this->assertSame($before, $this->rows('customers'));
    }

    public function test_the_new_loan_form_refuses_bad_text_and_creates_no_loan(): void
    {
        [, $owner, $cust] = $this->shopFor();
        foreach (self::BAD as $bad) {
            $this->refused($this->own($owner)->post('/dhiran', $this->loanForm($cust, ['notes' => $bad])), 'notes ' . bin2hex($bad));
            $form = $this->loanForm($cust);
            $form['items'][0]['description'] = $bad;   // deep inside the item rows
            $this->refused($this->own($owner)->post('/dhiran', $form), 'item description ' . bin2hex($bad));
        }
        $this->assertSame(0, $this->rows('dhiran_loans'));
        $this->assertSame(0, $this->rows('dhiran_payments'));
    }

    public function test_the_staff_forms_refuse_bad_names_and_change_nobody(): void
    {
        [$shop, $owner] = $this->shopFor();
        $staff = self::userWithRole($shop, ['name' => 'Staff One', 'mobile_number' => '9815630288', 'email' => 'staff@guard.test', 'password' => 'a-long-test-password',
            'is_active' => true, 'email_verified_at' => now(), 'realm' => 'dhiran'], 'staff');
        $roleId = Role::withoutTenant()->where('shop_id', $shop->id)->where('name', 'staff')->value('id');
        $users = $this->rows('users');
        foreach (self::BAD as $bad) {
            $r = $this->own($owner)->post('/staff', ['name' => $bad, 'mobile_number' => '9815630277', 'email' => 'new@guard.test', 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!', 'role_id' => $roleId]);
            $this->refused($r, 'create ' . bin2hex($bad));
            $r = $this->own($owner)->put('/staff/' . $staff->id, ['name' => $bad, 'mobile_number' => '9815630288', 'email' => 'staff@guard.test', 'role_id' => $roleId]);
            $this->refused($r, 'update ' . bin2hex($bad));
        }
        $this->assertSame($users, $this->rows('users'));
        $this->assertSame('Staff One', DB::table('users')->where('id', $staff->id)->value('name'));
    }

    public function test_the_settings_forms_refuse_bad_text_and_change_nothing(): void
    {
        [$shop, $owner] = $this->shopFor();
        $prefix = DB::table('dhiran_settings')->where('shop_id', $shop->id)->value('loan_number_prefix');
        $terms = DB::table('dhiran_settings')->where('shop_id', $shop->id)->value('receipt_terms_text');
        foreach (self::BAD as $bad) {
            $this->refused($this->own($owner)->patch('/dhiran/settings', $this->settingsForm(['loan_number_prefix' => $bad, 'default_interest_rate_monthly' => 4])), 'prefix ' . bin2hex($bad));
            $this->refused($this->own($owner)->patch('/dhiran/settings/receipt', ['show' => ['pledge.title'], 'receipt_terms_text' => 'Terms ' . $bad]), 'terms ' . bin2hex($bad));
        }
        $row = DB::table('dhiran_settings')->where('shop_id', $shop->id)->first();
        $this->assertSame($prefix, $row->loan_number_prefix);
        $this->assertSame($terms, $row->receipt_terms_text);
        $this->assertNotEquals(4, $row->default_interest_rate_monthly, 'the rest of the refused form was not saved either');

        // control: clean settings are saved
        $this->own($owner)->patch('/dhiran/settings', $this->settingsForm(['default_interest_rate_monthly' => 4]))->assertSessionHasNoErrors();
        $this->assertEquals(4, DB::table('dhiran_settings')->where('shop_id', $shop->id)->value('default_interest_rate_monthly'));
    }

    /* ───────────────────────────── admin forms ───────────────────────────── */

    public function test_the_admin_forms_refuse_bad_text_and_decide_nothing(): void
    {
        $this->signIn($this->admin());
        $waiting = Shop::create(['name' => 'Waiting Co', 'status' => 'pending']);
        [$live] = $this->shopFor('9390024210', 'Live Co');
        $ends = ShopSubscription::where('shop_id', $live->id)->value('ends_at');
        $audits = $this->rows('platform_audit_logs');

        foreach (self::BAD as $bad) {
            $this->refused($this->adm()->post('/admin/requests/' . $waiting->id . '/reject', ['reason' => $bad]), 'reject ' . bin2hex($bad));
            $this->refused($this->adm()->post('/admin/shops/' . $live->id . '/extend', ['junk' => $bad]), 'extend ' . bin2hex($bad));
            $this->refused($this->adm()->patch('/admin/shops/' . $live->id, ['status' => 'suspended', 'reason' => $bad]), 'suspend ' . bin2hex($bad));
        }
        $this->assertSame('pending', $waiting->fresh()->status);
        $this->assertNull($waiting->fresh()->review_note);
        $this->assertSame('active', $live->fresh()->status);
        $this->assertEquals($ends, ShopSubscription::where('shop_id', $live->id)->value('ends_at'), 'the term was not extended');
        $this->assertSame($audits, $this->rows('platform_audit_logs'), 'nothing was decided, so nothing is audited');
        Mail::assertNothingSent();

        // controls: the same endpoints work with clean input
        $this->adm()->post('/admin/shops/' . $live->id . '/extend')->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertNotEquals($ends, ShopSubscription::where('shop_id', $live->id)->value('ends_at'));
        $this->adm()->post('/admin/requests/' . $waiting->id . '/reject', ['reason' => 'Phone does not match'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $waiting->fresh()->status);
    }

    /* ───────────────────────────── guests ───────────────────────────── */

    public function test_register_and_login_refuse_bad_text_without_a_server_error(): void
    {
        foreach (self::BAD as $bad) {
            $this->refused($this->post('/register', ['name' => $bad, 'email' => 'guard@onboard.test', 'mobile' => '9812300055', 'password' => 'Local-pass-12345', 'password_confirmation' => 'Local-pass-12345', 'terms' => '1']), 'register name ' . bin2hex($bad));
            $this->refused($this->post('/login', ['email' => $bad, 'password' => 'whatever-pass-123']), 'login email ' . bin2hex($bad));
            $this->refused($this->post('/login', ['email' => 'guard@onboard.test', 'password' => $bad]), 'login password ' . bin2hex($bad));
        }
        $this->assertSame(0, $this->rows('users'));
        $this->assertGuest();

        // control: clean input still registers
        $this->post('/register', ['name' => 'Guard Owner', 'email' => 'guard@onboard.test', 'mobile' => '9812300055', 'password' => 'Local-pass-12345', 'password_confirmation' => 'Local-pass-12345', 'terms' => '1'])
            ->assertRedirect('/')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->rows('users'));
    }

    public function test_bad_bytes_in_a_query_string_are_refused_never_a_server_error(): void
    {
        [, $owner] = $this->shopFor();
        // A NUL alone at the edge of a value is trimmed away by Laravel's TrimStrings before the guard runs (the value becomes empty): harmless, never a 500.
        $r = $this->own($owner)->get('/dhiran/loans?search=%00');
        $this->assertLessThan(500, $r->getStatusCode());
        // Inside a value it reaches the guard and is refused.
        $this->refused($this->own($owner)->get('/dhiran/loans?search=A%00B'), 'search NUL');
        $this->refused($this->own($owner)->get('/dhiran/loans?search=%C3%28'), 'search broken UTF-8');
        $this->refused($this->own($owner)->get('/dhiran/loans?x[]=A%00B'), 'search nested NUL');
        $this->refused($this->get('/login?next=A%00B'), 'a guest page');
        $this->own($owner)->get('/dhiran/loans?search=Ram')->assertOk();   // control
    }

    public function test_json_clients_get_a_422_on_the_input_key(): void
    {
        [, $owner] = $this->shopFor();
        $before = $this->rows('customers');
        $r = $this->own($owner)->postJson('/dhiran/customers', ['first_name' => "A\0B", 'mobile' => '9815630199'])->assertStatus(422)->assertJsonValidationErrors('input');
        $this->assertStringNotContainsString('Illuminate', $r->getContent());
        $this->own($owner)->getJson('/dhiran/loans?search=A%00B')->assertStatus(422)->assertJsonValidationErrors('input');
        $this->own($owner)->getJson('/dhiran/borrowers/search?q=%C3%28')->assertStatus(422)->assertJsonValidationErrors('input');
        $this->assertSame($before, $this->rows('customers'));
    }

    /* ───────────────────────── what stays allowed ───────────────────────── */

    public function test_a_multi_line_note_with_newlines_and_tabs_is_accepted_and_kept(): void
    {
        [$shop, $owner] = $this->shopFor();
        $text = "Interest is charged per month.\n\tItems not redeemed by maturity may be sold.\r\nसाइन: राम ₹500\rLast line";
        $this->own($owner)->patch('/dhiran/settings/receipt', ['show' => ['pledge.title'], 'receipt_terms_text' => $text])
            ->assertSessionHasNoErrors()->assertRedirect(route('dhiran.settings.receipt.edit'));
        $stored = (string) DB::table('dhiran_settings')->where('shop_id', $shop->id)->value('receipt_terms_text');
        $norm = fn (string $s) => str_replace("\r\n", "\n", $s);
        $this->assertSame($norm($text), $norm($stored));
        $this->assertStringContainsString("\n\t", $stored);

        // every other control byte is refused (and the saved text stays)
        foreach (["\x01", "\x07", "\x08", "\x0B", "\x0C", "\x0E", "\x1B", "\x1F", "\x7F"] as $ctl) {
            $this->refused($this->own($owner)->patch('/dhiran/settings/receipt', ['show' => ['pledge.title'], 'receipt_terms_text' => "Terms {$ctl} here"]), 'control ' . bin2hex($ctl));
        }
        $this->assertSame($stored, (string) DB::table('dhiran_settings')->where('shop_id', $shop->id)->value('receipt_terms_text'));
    }

    public function test_uploaded_files_are_never_inspected(): void
    {
        [, $owner] = $this->shopFor();
        $before = $this->rows('customers');
        $file = UploadedFile::fake()->createWithContent('photo.png', "not an image \0 \xC3\x28 \x01\x1B");
        $r = $this->own($owner)->post('/dhiran/customers', ['first_name' => 'Photo', 'mobile' => '9815630144', 'customer_photo' => $file]);
        $this->assertLessThan(500, $r->getStatusCode());
        $r->assertSessionDoesntHaveErrors('input');   // the guard did not turn the file away (the photo service may; the customer is still saved)
        $this->assertSame($before + 1, $this->rows('customers'));
    }

    /* ───────────────────────── error shapes ───────────────────────── */

    public function test_an_unknown_loan_is_a_plain_json_404_that_names_no_class(): void
    {
        [, $owner] = $this->shopFor();
        $r = $this->own($owner)->getJson('/dhiran/loans/999999')->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
        $this->assertStringNotContainsString('App\\Models', $r->getContent());
        $this->assertStringNotContainsString('App\\\\Models', $r->getContent());
        $this->assertStringNotContainsString('No query results', $r->getContent());
    }

    public function test_advance_interest_longer_than_the_loan_is_a_form_error_not_a_server_error(): void
    {
        [$shop, $owner, $cust] = $this->shopFor();
        DhiranSettings::getForShop($shop->id)->update(['advance_interest_enabled' => true, 'advance_interest_default_months' => 1]);

        $r = $this->own($owner)->post('/dhiran', $this->loanForm($cust, ['tenure_months' => 1, 'advance_interest_months' => 2]));
        $this->assertSame(302, $r->getStatusCode());
        $r->assertSessionHasErrors('loan');
        $this->assertStringContainsString('cannot cover more months', (string) session('errors')->first('loan'));
        $this->assertSame(0, $this->rows('dhiran_loans'));
        $this->assertSame(0, $this->rows('dhiran_payments'));

        // control: the same advance on a loan that runs long enough is accepted
        $this->own($owner)->post('/dhiran', $this->loanForm($cust, ['tenure_months' => 6, 'advance_interest_months' => 2]))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->rows('dhiran_loans'));
    }
}
