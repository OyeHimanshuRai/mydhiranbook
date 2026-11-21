<?php

namespace Tests\Feature\Ruthless;

use App\Mail\EmailOtpMail;
use App\Models\Customer;
use App\Models\Dhiran\DhiranAttachment;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\Dhiran\DhiranAttachmentService;
use App\Services\DhiranService;
use App\Services\TotpService;
use App\Support\Csv;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

/**
 * Adversarial input tests: signup/OTP/reset, customers + KYC, attachments/photos, old-loan import, CSV exports,
 * platform admin and staff/settings. Every test states its rule in a comment; a test that fails because the code
 * breaks the rule carries Group('ruthless-bug').
 */
class InputsTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures;

    private const HOST = 'http://127.0.0.1:8787';
    private const HEADER = ['ticket_number', 'loan_date', 'customer_name', 'mobile', 'address', 'pan', 'id_number', 'amount_lent',
        'principal_owed', 'interest_rate_monthly', 'interest_type', 'tenure_months', 'interest_paid_up_to', 'unpaid_interest',
        'item_description', 'metal', 'purity', 'gross_weight_g', 'stone_weight_g', 'rate_per_gram', 'appraised_value'];

    private Shop $shopA;
    private Shop $shopB;
    private User $ownerA;
    private User $ownerB;
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->shopA, $this->ownerA] = $this->makeShop('A', '9400000001');
        [$this->shopB, $this->ownerB] = $this->makeShop('B', '9400000002');
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    private function makeShop(string $n, string $mobile): array
    {
        $shop = self::createDhiranShop(['name' => "Shop $n", 'shop_type' => 'dhiran', 'phone' => $mobile, 'owner_first_name' => 'O',
            'owner_last_name' => 'W', 'owner_mobile' => $mobile, 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0]);
        $owner = self::userWithRole($shop, ['name' => "Owner $n", 'mobile_number' => $mobile, 'email' => 'owner' . strtolower($n) . '@ruth.test',
            'password' => 'a-long-test-password', 'is_active' => true, 'email_verified_at' => now(), 'realm' => 'dhiran']);

        return [$shop, $owner];
    }

    private function as(User $u, callable $fn): mixed
    {
        return TenantContext::runFor((int) $u->shop_id, function () use ($u, $fn) {
            $this->actingAs($u);
            $res = $fn();
            if ($res instanceof \Illuminate\Testing\TestResponse && $res->baseResponse instanceof StreamedResponse) { $res->streamedContent(); } // stream while the tenant is set

            return $res;
        });
    }

    private function cust(Shop $shop, array $a = []): Customer
    {
        return TenantContext::runFor($shop->id, fn () => Customer::create($a + ['shop_id' => $shop->id, 'first_name' => 'Cust', 'last_name' => 'Z',
            'mobile' => '9' . str_pad((string) (++self::$seq + 100000), 9, '0', STR_PAD_LEFT)]));
    }

    private function loanFor(Shop $shop, Customer $c, array $p = []): DhiranLoan
    {
        return TenantContext::runFor($shop->id, fn () => app(DhiranService::class)->createLoan($shop, $c, [[
            'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
        ]], $p + ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => null]));
    }

    private function key(): string { return Str::uuid()->toString(); }

    private function png(int $w = 1, int $h = 1): string
    {
        $i = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($i);

        return (string) ob_get_clean();
    }

    /** Valid signature + IHDR (+IEND) only: enough for getimagesize, not decodable. */
    private function headerOnlyPng(int $w, int $h): string
    {
        $chunk = fn (string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $chunk('IEND', '');
    }

    /** A real upload (the fake File helper derives its MIME from the name, which would defeat every disguise test). */
    private function file(string $name, string $bytes): UploadedFile
    {
        $p = tempnam(sys_get_temp_dir(), 'rt');
        file_put_contents($p, $bytes);

        return new UploadedFile($p, $name, null, null, true);
    }

    private function csvCells(string $content): array
    {
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        $h = fopen('php://memory', 'w+');
        fwrite($h, $content);
        rewind($h);
        $rows = [];
        while (($r = Csv::get($h)) !== false) {
            $rows[] = $r;
        }

        return $rows;
    }


    /* ═══════════════ 1. SIGNUP / OTP / PASSWORD RESET ═══════════════ */

    private function fresh_users() { return User::whereNotIn('id', [$this->ownerA->id, $this->ownerB->id]); }

    private function reg(array $over = []): array
    {
        return $over + ['name' => 'New Owner', 'email' => 'new@onboard.test', 'mobile' => '98123 00099', 'password' => 'Abcdefghij12', 'password_confirmation' => 'Abcdefghij12', 'terms' => '1'];
    }

    public function test_register_lowercases_and_trims_the_email(): void
    {
        // Rule: email is trimmed and lowercased before validation and storage.
        $this->post('/register', $this->reg(['email' => '  Foo@X.Test  ']))->assertRedirect('/');
        $this->assertSame('foo@x.test', $this->fresh_users()->sole()->email);
    }

    public function test_register_refuses_the_same_email_in_another_case(): void
    {
        // Rule: Foo@X.test and foo@x.test are one account.
        $this->post('/register', $this->reg(['email' => 'foo@x.test']))->assertRedirect('/');
        auth()->logout();
        $this->post('/register', $this->reg(['email' => 'FOO@x.TEST', 'mobile' => '9812300088']))->assertSessionHasErrors('email');
        $this->assertSame(1, $this->fresh_users()->count());
    }

    public static function goodMobiles(): array
    {
        return [['+91 98123 00099'], ['+91-98123-00099'], ['098123 00099'], ['9812300099'], ['0091 9812300099']];
    }

    #[DataProvider('goodMobiles')]
    public function test_register_stores_every_accepted_mobile_spelling_as_ten_digits(string $mobile): void
    {
        // Rule: accepted spellings collapse to the canonical ten-digit number.
        $this->post('/register', $this->reg(['mobile' => $mobile]))->assertRedirect('/');
        $this->assertSame('9812300099', $this->fresh_users()->sole()->mobile_number);
    }

    public static function badMobiles(): array
    {
        return [['98123000999'], ['981230009'], ['5812300099'], ['ABCDEFGHIJ'], ['98123 0009x'], ["9812300099'; --"], ['']];
    }

    #[DataProvider('badMobiles')]
    public function test_register_rejects_bad_mobiles(string $mobile): void
    {
        // Rule: eleven digits (a typo), nine digits, a 5-leading number, letters and SQL are all refused; nothing created.
        $this->post('/register', $this->reg(['mobile' => $mobile]))->assertSessionHasErrors('mobile');
        $this->assertSame(0, $this->fresh_users()->count());
    }

    public function test_register_same_mobile_in_another_spelling_is_a_duplicate(): void
    {
        // Rule: +91 98123 00099 and 9812300099 are the same number.
        $this->post('/register', $this->reg(['mobile' => '9812300099']))->assertRedirect('/');
        auth()->logout();
        $this->post('/register', $this->reg(['email' => 'two@x.test', 'mobile' => '+91 98123 00099']))->assertSessionHasErrors('mobile');
    }

    public function test_register_password_boundary_is_twelve_characters(): void
    {
        // Rule: 11 characters refused, exactly 12 accepted.
        $this->post('/register', $this->reg(['password' => 'Abcdefghi12', 'password_confirmation' => 'Abcdefghi12']))->assertSessionHasErrors('password');
        $this->assertSame(0, $this->fresh_users()->count());
        $this->post('/register', $this->reg(['password' => 'Abcdefghij12', 'password_confirmation' => 'Abcdefghij12']))->assertRedirect('/');
        $this->assertSame(1, $this->fresh_users()->count());
    }

    public function test_register_password_confirmation_mismatch_is_refused(): void
    {
        // Rule: password must equal its confirmation.
        $this->post('/register', $this->reg(['password_confirmation' => 'Different-12345']))->assertSessionHasErrors('password');
    }

    public function test_register_name_keeps_unicode_and_emoji_and_caps_at_120(): void
    {
        // Rule: any Unicode name up to 120 characters is stored exactly; 121 refused.
        $name = 'राम शर्मा 🙂 José';
        $this->post('/register', $this->reg(['name' => $name]))->assertRedirect('/');
        $this->assertSame($name, $this->fresh_users()->sole()->name);
        auth()->logout();
        $this->post('/register', $this->reg(['email' => 'l@x.test', 'mobile' => '9812300088', 'name' => str_repeat('अ', 121)]))->assertSessionHasErrors('name');
        $this->post('/register', $this->reg(['email' => 'l@x.test', 'mobile' => '9812300088', 'name' => str_repeat('अ', 120)]))->assertRedirect('/');
    }

    public function test_register_requires_array_free_scalar_fields(): void
    {
        // Rule: array-shaped fields are a validation error, never a 500.
        $r = $this->post('/register', $this->reg(['name' => ['x'], 'email' => ['a@b.test'], 'mobile' => ['9812300099']]));
        $this->assertNotSame(500, $r->getStatusCode());
        $r->assertSessionHasErrors();
        $this->assertSame(0, $this->fresh_users()->count());
    }

    public function test_html_in_a_name_is_escaped_wherever_it_is_rendered_back(): void
    {
        // Rule: stored text is HTML-escaped on every page (staff list shows the name).
        $evil = '<script>alert(1)</script>';
        $this->post('/register', $this->reg(['name' => $evil]))->assertRedirect('/');
        $this->assertSame($evil, $this->fresh_users()->sole()->name);
        $staff = self::userWithRole($this->shopA, ['name' => $evil, 'mobile_number' => '9400000077', 'email' => 'evil@ruth.test', 'password' => 'a-long-test-password', 'is_active' => true], 'staff');
        $this->as($this->ownerA, fn () => $this->get('/settings?tab=staff'))->assertOk()->assertDontSee($evil, false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->assertNotNull($staff->id);
    }

    public function test_login_is_case_insensitive_for_email_and_accepts_formatted_mobile(): void
    {
        // Rule: login identity is the lowercased email or the canonical mobile.
        $this->post('/register', $this->reg(['email' => 'mix@x.test']))->assertRedirect('/');
        auth()->logout();
        $this->post('/login', ['email' => '  MIX@X.Test ', 'password' => 'Abcdefghij12'])->assertRedirect('/');
        $this->assertAuthenticated();
        auth()->logout();
        $this->post('/login', ['email' => '+91 98123 00099', 'password' => 'Abcdefghij12'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_login_with_array_identity_is_a_validation_error_not_a_500(): void
    {
        // Rule: malformed identity types are rejected with 422/redirect.
        $r = $this->post('/login', ['email' => ['a'], 'password' => ['b']]);
        $this->assertNotSame(500, $r->getStatusCode());
        $r->assertSessionHasErrors();
    }

    public function test_login_is_blocked_after_five_wrong_passwords_even_with_the_right_one(): void
    {
        // Rule: 5 failures lock sign-in for that account+IP; the correct password then still cannot get in.
        $this->post('/register', $this->reg(['email' => 'lock@x.test']))->assertRedirect('/');
        auth()->logout();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'lock@x.test', 'password' => 'wrong-password-1']);
        }
        $this->post('/login', ['email' => 'lock@x.test', 'password' => 'Abcdefghij12']);
        $this->assertGuest();
    }

    private function sendCode(User $u): string
    {
        Mail::fake();
        $this->flushSession();
        auth()->forgetGuards();
        $this->actingAs($u)->postJson(route('dhiran.verify-email.send'), ['email' => $u->email])->assertOk();
        $code = null;
        Mail::assertSent(EmailOtpMail::class, function ($m) use (&$code) { $code = $m->otp; return true; });

        return $code;
    }

    private function verify(User $u, mixed $otp)
    {
        return $this->actingAs($u)->postJson(route('dhiran.verify-email.verify'), ['otp' => $otp]);
    }

    public function test_otp_right_code_on_the_fifth_try_still_verifies(): void
    {
        // Rule: four wrong tries then the right code verifies (the lock is at the 5th WRONG try).
        $u = $this->newOwner(['email_verified_at' => null]);
        $code = $this->sendCode($u);
        $bad = $code === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 4; $i++) { $this->verify($u, $bad)->assertStatus(422); }
        $this->verify($u, $code)->assertOk();
        $this->assertNotNull($u->fresh()->email_verified_at);
    }

    public function test_otp_burned_after_five_wrong_tries_needs_a_fresh_code(): void
    {
        // Rule: after the lock the old code is dead; a new send issues a new working code.
        $u = $this->newOwner(['email_verified_at' => null]);
        $old = $this->sendCode($u);
        $bad = $old === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 5; $i++) { $this->verify($u, $bad)->assertStatus(422); }
        $this->verify($u, $old)->assertStatus(422);
        $this->travel(61)->seconds();
        $new = $this->sendCode($u);
        $this->verify($u, $new)->assertOk();
    }

    public function test_otp_is_valid_until_ten_minutes_and_dead_after(): void
    {
        // Rule: valid at 9m59s, rejected at 10m01s.
        $u = $this->newOwner(['email_verified_at' => null]);
        $code = $this->sendCode($u);
        $this->travel(9)->minutes();
        $this->travel(59)->seconds();
        $this->verify($u, $code)->assertOk();

        $v = $this->newOwner(['email_verified_at' => null]);
        $code = $this->sendCode($v);
        $this->travel(10)->minutes();
        $this->travel(1)->seconds();
        $this->verify($v, $code)->assertStatus(422);
    }

    public static function badOtps(): array
    {
        return [['12345'], ['1234567'], ['12 345'], ['abcdef'], [' 123456'], ['１２３４５６']];
    }

    #[DataProvider('badOtps')]
    public function test_otp_shape_is_exactly_six_ascii_digits(string $otp): void
    {
        // Rule: anything but six ASCII digits is a 422 and does not count as the right code.
        $u = $this->newOwner(['email_verified_at' => null]);
        $this->sendCode($u);
        $this->verify($u, $otp)->assertStatus(422);
        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_otp_array_value_is_422_not_500(): void
    {
        // Rule: array otp is a validation error.
        $u = $this->newOwner(['email_verified_at' => null]);
        $this->sendCode($u);
        $this->verify($u, ['123456'])->assertStatus(422);
    }

    public function test_sending_a_new_code_invalidates_the_previous_one(): void
    {
        // Rule: only the latest code works.
        $u = $this->newOwner(['email_verified_at' => null]);
        $first = $this->sendCode($u);
        $this->travel(61)->seconds();
        $second = $this->sendCode($u);
        if ($first === $second) { $this->markTestSkipped('codes collided'); }
        $this->verify($u, $first)->assertStatus(422);
        $this->verify($u, $second)->assertOk();
    }

    public function test_otp_resend_gap_and_hourly_cap(): void
    {
        // Rule: 60 s between sends and at most 5 sends an hour; allowed again after the hour.
        $u = $this->newOwner(['email_verified_at' => null]);
        for ($i = 0; $i < 5; $i++) {
            $this->sendCode($u);
            $this->actingAs($u)->postJson(route('dhiran.verify-email.resend'))->assertStatus(422); // inside the gap
            $this->travel(61)->seconds();
        }
        $this->actingAs($u)->postJson(route('dhiran.verify-email.resend'))->assertStatus(422); // 6th in the hour
        $this->travel(1)->hours();
        $this->actingAs($u)->postJson(route('dhiran.verify-email.resend'))->assertOk();
    }

    public function test_otp_send_normalises_email_and_refuses_another_accounts_address_in_any_case(): void
    {
        // Rule: the address is trimmed/lowercased; an address owned by someone else (any case) is refused.
        $this->newOwner(['email' => 'taken@x.test']);
        $u = $this->newOwner(['email_verified_at' => null]);
        Mail::fake();
        $this->actingAs($u)->postJson(route('dhiran.verify-email.send'), ['email' => '  TAKEN@x.TEST '])->assertStatus(422);
        $this->actingAs($u)->postJson(route('dhiran.verify-email.send'), ['email' => '  Mine@X.Test '])->assertOk();
        $this->assertSame('mine@x.test', $u->fresh()->email);
    }

    public function test_otp_send_refuses_an_already_verified_account(): void
    {
        // Rule: a verified email cannot be re-sent a code.
        $u = $this->newOwner();
        $this->actingAs($u)->postJson(route('dhiran.verify-email.send'), ['email' => $u->email])->assertStatus(422);
    }

    public function test_otp_send_rejects_header_injection_and_oversize_emails(): void
    {
        // Rule: strict email only; CRLF and >190 chars refused; no mail queued.
        $u = $this->newOwner(['email_verified_at' => null]);
        Mail::fake();
        $this->actingAs($u)->postJson(route('dhiran.verify-email.send'), ['email' => "a@x.test\r\nBcc: evil@x.test"])->assertStatus(422);
        $this->actingAs($u)->postJson(route('dhiran.verify-email.send'), ['email' => str_repeat('a', 185) . '@x.test'])->assertStatus(422);
        Mail::assertNothingSent();
    }

    private function resetToken(User $u): string
    {
        return Password::broker('users')->createToken($u);
    }

    public function test_forgot_password_gives_the_same_answer_for_known_and_unknown_and_matches_case_insensitively(): void
    {
        // Rule: no account enumeration; a differently-cased, padded address still reaches the account.
        Notification::fake();
        $u = $this->newOwner(['email' => 'known@x.test']);
        $a = $this->post('/forgot-password', ['email' => '  KNOWN@X.test '])->assertSessionHas('success');
        Notification::assertSentTo($u, ResetPassword::class);
        $b = $this->post('/forgot-password', ['email' => 'nobody@x.test'])->assertSessionHas('success');
        $this->assertSame(session('success'), $a->baseResponse->getSession()->get('success'));
        $this->assertNotNull($b);
    }

    public function test_reset_password_boundary_expiry_and_reuse(): void
    {
        // Rule: 11-char password refused, 12 accepted once; a used token is dead; a token older than 60 min is dead.
        $u = $this->newOwner(['email' => 'reset@x.test']);
        $t = $this->resetToken($u);
        $this->post('/reset-password', ['token' => $t, 'email' => 'reset@x.test', 'password' => 'Abcdefghi12', 'password_confirmation' => 'Abcdefghi12'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $t, 'email' => ' RESET@x.test ', 'password' => 'Abcdefghij12', 'password_confirmation' => 'Abcdefghij12'])->assertRedirect('/login');
        $this->post('/reset-password', ['token' => $t, 'email' => 'reset@x.test', 'password' => 'Zyxwvutsrq12', 'password_confirmation' => 'Zyxwvutsrq12'])->assertSessionHasErrors('email');

        $t2 = $this->resetToken($u);
        $this->travel(61)->minutes();
        $this->post('/reset-password', ['token' => $t2, 'email' => 'reset@x.test', 'password' => 'Zyxwvutsrq12', 'password_confirmation' => 'Zyxwvutsrq12'])->assertSessionHasErrors('email');
    }

    public function test_reset_token_is_bound_to_its_own_email(): void
    {
        // Rule: a token for account A cannot reset account B.
        $a = $this->newOwner(['email' => 'a1@x.test']);
        $b = $this->newOwner(['email' => 'b1@x.test']);
        $t = $this->resetToken($a);
        $this->post('/reset-password', ['token' => $t, 'email' => 'b1@x.test', 'password' => 'Abcdefghij12', 'password_confirmation' => 'Abcdefghij12'])->assertSessionHasErrors('email');
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('Abcdefghij12', $b->fresh()->password));
    }

    public function test_reset_with_array_fields_is_not_a_500(): void
    {
        // Rule: malformed types are validation errors.
        $r = $this->post('/reset-password', ['token' => ['x'], 'email' => ['a@b.test'], 'password' => ['p'], 'password_confirmation' => ['p']]);
        $this->assertNotSame(500, $r->getStatusCode());
        $r->assertSessionHasErrors();
    }

    /* ═══════════════ 2. CUSTOMERS + KYC ═══════════════ */

    private function postCustomer(User $u, array $data)
    {
        return $this->as($u, fn () => $this->postJson(self::HOST . '/dhiran/customers', $data + ['first_name' => 'Asha', 'mobile' => '9810011111']));
    }

    public static function badPans(): array
    {
        return [['ABCDE1234'], ['ABCDE1234FG'], ['12345ABCDE'], ['ABCDE12E4F'], ['ABCD E1234F'], ['ÀBCDE1234F']];
    }

    #[DataProvider('badPans')]
    public function test_customer_pan_format_is_enforced(string $pan): void
    {
        // Rule: PAN is 5 letters + 4 digits + 1 letter.
        $this->postCustomer($this->ownerA, ['pan' => $pan])->assertStatus(422)->assertJsonValidationErrors('pan');
        $this->assertSame(0, Customer::withoutGlobalScopes()->count());
    }

    public function test_customer_pan_as_an_array_is_a_validation_error_not_a_500(): void
    {
        // Rule: malformed types never 500. PanFormatRule casts the value with (string).
        $r = $this->postCustomer($this->ownerA, ['pan' => ['ABCDE1234F']]);
        $this->assertSame(422, $r->getStatusCode());
    }

    public function test_customer_mobile_as_an_array_is_a_validation_error_not_a_500(): void
    {
        // Rule: malformed types never 500.
        $r = $this->postCustomer($this->ownerA, ['mobile' => ['9810011111']]);
        $this->assertSame(422, $r->getStatusCode());
    }

    public function test_pan_and_id_number_are_stored_encrypted_and_round_trip(): void
    {
        // Rule: PAN is uppercased, stored encrypted (ciphertext in the column), decrypts back; the profile shows it.
        $this->postCustomer($this->ownerA, ['pan' => '  abcde1234f ', 'id_number' => 'ID-<b>99</b>'])->assertCreated();
        $c = Customer::withoutGlobalScopes()->sole();
        $raw = DB::table('customers')->where('id', $c->id)->first();
        $this->assertNotSame('ABCDE1234F', $raw->pan);
        $this->assertStringNotContainsString('ABCDE1234F', (string) $raw->pan);
        $this->assertStringNotContainsString('ID-', (string) $raw->id_number);
        $this->assertSame('ABCDE1234F', $c->pan);
        $this->assertSame('ID-<b>99</b>', $c->id_number);
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers/' . $c->id))->assertOk()->assertSee('ABCDE1234F');
    }

    public function test_loan_kyc_pan_is_encrypted_at_rest_and_shown_on_loan_page_and_receipt_without_full_aadhaar(): void
    {
        // Rule: loan kyc_pan ciphertext at rest; PAN visible on loan page and receipt; Aadhaar only ever last 4.
        $c = $this->cust($this->shopA);
        $this->as($this->ownerA, fn () => $this->post(self::HOST . '/dhiran', ['idempotency_key' => $this->key(), 'customer_id' => $c->id,
            'principal_amount' => 10000, 'gold_rate_on_date' => 6000, 'pan' => 'abcde1234f', 'aadhaar' => '1234 5678 9012',
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]]]))->assertSessionHasNoErrors();
        $loan = DhiranLoan::withoutGlobalScopes()->sole();
        $raw = DB::table('dhiran_loans')->where('id', $loan->id)->value('kyc_pan');
        $this->assertStringNotContainsString('ABCDE1234F', (string) $raw);
        foreach (['/dhiran/loans/' . $loan->id, '/dhiran/loans/' . $loan->id . '/receipt'] as $path) {
            $this->as($this->ownerA, fn () => $this->get(self::HOST . $path))->assertOk()->assertSee('ABCDE1234F')->assertDontSee('123456789012')->assertDontSee('1234 5678 9012');
        }
        $this->assertSame('XXXX-XXXX-9012', $loan->kyc_aadhaar);
    }

    public function test_customer_export_masks_the_pan(): void
    {
        // Rule: exports never carry a full PAN.
        $this->cust($this->shopA, ['pan' => 'ABCDE1234F']);
        $csv = $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers?export=csv'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('ABCDE1234F', $csv);
        $this->assertStringContainsString('ABCXXXXX4F', $csv);
    }

    public function test_same_mobile_in_two_shops_is_two_customers_but_one_per_shop_in_any_spelling(): void
    {
        // Rule: mobile is unique per shop (any spelling), free across shops.
        $this->postCustomer($this->ownerA, ['mobile' => '9810011111'])->assertCreated();
        $this->postCustomer($this->ownerB, ['mobile' => '9810011111'])->assertCreated();
        $dup = $this->postCustomer($this->ownerA, ['mobile' => '+91 98100 11111', 'first_name' => 'Other'])->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('shop_id', $this->shopA->id)->count());
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('shop_id', $this->shopB->id)->count());
        $this->assertSame('Asha', $dup->json('customer.name'));
    }

    public function test_customer_text_fields_are_length_capped_and_unicode_safe(): void
    {
        // Rule: first_name <=255, address <=500, notes <=1000, id_number <=50; Unicode/emoji stored exactly.
        $this->postCustomer($this->ownerA, ['first_name' => str_repeat('a', 256)])->assertJsonValidationErrors('first_name');
        $this->postCustomer($this->ownerA, ['address' => str_repeat('a', 501)])->assertJsonValidationErrors('address');
        $this->postCustomer($this->ownerA, ['notes' => str_repeat('a', 1001)])->assertJsonValidationErrors('notes');
        $this->postCustomer($this->ownerA, ['id_number' => str_repeat('1', 51)])->assertJsonValidationErrors('id_number');
        $this->postCustomer($this->ownerA, ['first_name' => 'राम 🙂', 'address' => "O'Brien \"Road\" ; DROP TABLE customers;--"])->assertCreated();
        $c = Customer::withoutGlobalScopes()->sole();
        $this->assertSame('राम 🙂', $c->first_name);
        $this->assertSame("O'Brien \"Road\" ; DROP TABLE customers;--", $c->address);
    }

    public function test_posted_shop_id_is_ignored(): void
    {
        // Rule: tenant comes from the session, never from the body.
        $this->postCustomer($this->ownerA, ['shop_id' => $this->shopB->id])->assertCreated();
        $this->assertSame($this->shopA->id, (int) Customer::withoutGlobalScopes()->sole()->shop_id);
    }

    public function test_html_and_script_in_every_customer_and_loan_text_is_escaped_on_every_page(): void
    {
        // Rule: stored text never reaches a page unescaped.
        $evil = '<script>alert(1)</script>';
        $img = '<img src=x onerror=alert(2)>';
        $c = $this->cust($this->shopA, ['first_name' => $evil, 'last_name' => $img, 'address' => $img, 'notes' => $img]);
        $loan = $this->loanFor($this->shopA, $c, ['notes' => $img]);
        $pages = ['/dhiran/borrowers', '/dhiran/borrowers/' . $c->id, '/dhiran/loans', '/dhiran/loans/' . $loan->id,
            '/dhiran/loans/' . $loan->id . '/receipt', '/dhiran/reports/active', '/dhiran/old-loans', '/dhiran/create'];
        foreach ($pages as $p) {
            $this->as($this->ownerA, fn () => $this->get(self::HOST . $p))->assertOk()->assertDontSee($evil, false)->assertDontSee($img, false);
        }
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers/' . $c->id))->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_script_closing_tag_in_a_customer_name_cannot_break_out_of_the_new_loan_page_json(): void
    {
        // Rule: customer data embedded in page script is encoded so it cannot close the script block.
        $this->cust($this->shopA, ['first_name' => '</script><script>alert(7)</script>']);
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/create'))->assertOk()->assertDontSee('</script><script>alert(7)', false);
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        // Rule: a search box is a literal text match: "%" matches only names containing a percent sign.
        $this->cust($this->shopA, ['first_name' => 'Asha', 'last_name' => 'Rao']);
        $this->cust($this->shopA, ['first_name' => 'Bina', 'last_name' => 'Das']);
        $this->cust($this->shopA, ['first_name' => '50%', 'last_name' => 'Off']);
        $names = fn (string $q) => array_column(array_slice($this->csvCells($this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers?export=csv&search=' . urlencode($q)))->streamedContent()), 1), 2);
        $this->assertSame(['50% Off'], $names('%'));
        $this->assertSame([], $names('_'));
        $this->assertSame(['Asha Rao'], $names('asha'));
    }

    public function test_search_longer_than_100_characters_is_refused(): void
    {
        // Rule: search is capped at 100 characters.
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers?search=' . str_repeat('a', 101)))->assertSessionHasErrors('search');
    }

    public function test_archived_customer_cannot_take_a_new_loan_but_stays_readable_and_reactivation_restores(): void
    {
        // Rule: an archived customer gets no NEW loans (New Loan form) but its profile still opens; unarchive restores.
        $c = $this->cust($this->shopA);
        $c->forceFill(['is_active' => false])->save();
        $payload = fn () => ['idempotency_key' => $this->key(), 'customer_id' => $c->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]]];
        $this->as($this->ownerA, fn () => $this->post(self::HOST . '/dhiran', $payload()))->assertSessionHasErrors('customer_id');
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->count());
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers/' . $c->id))->assertOk();
        $c->forceFill(['is_active' => true])->save();
        $this->as($this->ownerA, fn () => $this->post(self::HOST . '/dhiran', $payload()))->assertSessionHasNoErrors();
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->count());
    }

    public function test_adding_a_customer_whose_mobile_belongs_to_an_archived_customer_returns_that_customer_not_a_duplicate(): void
    {
        // Rule: an archived customer's mobile still counts as taken (no second record).
        $c = $this->cust($this->shopA, ['mobile' => '9810022222']);
        $c->forceFill(['is_active' => false])->save();
        $this->postCustomer($this->ownerA, ['mobile' => '9810022222', 'first_name' => 'New'])->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('shop_id', $this->shopA->id)->count());
    }

    /* ═══════════════ 3. ATTACHMENTS / PHOTOS ═══════════════ */

    private function attach(User $u, Customer $c, UploadedFile $f, string $type = 'id_proof_front', array $over = [])
    {
        return $this->as($u, fn () => $this->post(self::HOST . '/dhiran/attachments', $over + ['owner_type' => 'customer', 'owner_id' => $c->id, 'document_type' => $type, 'file' => $f]));
    }

    public function test_a_real_png_is_stored_privately_with_a_random_path(): void
    {
        // Rule: private disk, shop-scoped random path, real mime recorded.
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, $this->file('id.png', $this->png(40, 40)))->assertSessionHasNoErrors();
        $a = DhiranAttachment::withoutGlobalScopes()->sole();
        $this->assertSame('image/png', $a->mime_type);
        $this->assertMatchesRegularExpression('~^dhiran/' . $this->shopA->id . '/[A-Za-z0-9]{2}/[A-Za-z0-9]{40}\.png$~', $a->file_path);
        Storage::disk('local')->assertExists($a->file_path);
    }

    public static function disguisedFiles(): array
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        return [
            'php as png'      => ['a.png', "<?php system(\$_GET['c']); ?>"],
            'svg as svg'      => ['a.svg', $svg],
            'svg as png'      => ['a.png', $svg],
            'html as jpg'     => ['a.jpg', '<html><script>alert(1)</script></html>'],
            'zero bytes'      => ['z.png', ''],
            'exe as pdf'      => ['a.pdf', "MZ\x90\x00\x03\x00\x00\x00"],
            'double ext php'  => ['a.php.png', "<?php echo 1;"],
            'php ext'         => ['a.php', "<?php echo 1;"],
            'gif as png'      => ['a.png', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"],
        ];
    }

    #[DataProvider('disguisedFiles')]
    public function test_disguised_or_empty_files_are_refused_and_nothing_is_stored(string $name, string $bytes): void
    {
        // Rule: only real JPG/PNG/PDF content with a matching extension is accepted.
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, $this->file($name, $bytes))->assertSessionHasErrors('file');
        $this->assertSame(0, DhiranAttachment::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('dhiran'));
    }

    public function test_png_content_under_a_pdf_name_is_refused(): void
    {
        // Rule: the real MIME must match the claimed extension.
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, $this->file('a.pdf', $this->png()))->assertSessionHasErrors('file');
        $this->assertSame(0, DhiranAttachment::withoutGlobalScopes()->count());
    }

    public function test_oversize_file_over_8mb_is_refused(): void
    {
        // Rule: 8 MB cap.
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, UploadedFile::fake()->image('big.png', 10, 10)->size(8193))->assertSessionHasErrors('file');
        $this->assertSame(0, DhiranAttachment::withoutGlobalScopes()->count());
    }

    public function test_polyglot_png_with_php_appended_is_stored_without_the_php(): void
    {
        // Rule: images are re-encoded, so appended code cannot survive.
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, $this->file('p.png', $this->png(8, 8) . "<?php system(\$_GET['c']); ?>"))->assertSessionHasNoErrors();
        $a = DhiranAttachment::withoutGlobalScopes()->sole();
        $this->assertStringNotContainsString('<?php', Storage::disk('local')->get($a->file_path));
    }

    public function test_png_signature_followed_by_garbage_and_php_is_not_stored_verbatim(): void
    {
        // Rule: an "image" GD cannot decode must not be kept byte-for-byte (the service fails open and stores the original).
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, $this->file('p.png', "\x89PNG\r\n\x1a\n" . str_repeat('x', 64) . "<?php system(\$_GET['c']); ?>"));
        $stored = DhiranAttachment::withoutGlobalScopes()->first();
        $this->assertTrue($stored === null || ! str_contains(Storage::disk('local')->get($stored->file_path), '<?php'));
    }

    public function test_path_traversal_in_the_uploaded_name_cannot_escape_the_shop_directory(): void
    {
        // Rule: the stored path is random under dhiran/{shop}/; the client name is only a label without separators.
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, $this->file('../../../../evil.png', $this->png()))->assertSessionHasNoErrors();
        $a = DhiranAttachment::withoutGlobalScopes()->sole();
        $this->assertStringStartsWith('dhiran/' . $this->shopA->id . '/', $a->file_path);
        $this->assertStringNotContainsString('..', $a->file_path);
        $this->assertStringNotContainsString('/', $a->original_name);
        $this->assertStringNotContainsString('..', $a->original_name);
    }

    public function test_an_attachment_whose_filename_has_a_control_character_can_still_be_served(): void
    {
        // Rule: any accepted upload must be viewable; the Content-Disposition name must be sanitised, not crash.
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, $this->file("scan\tof id.png", $this->png()));
        $a = DhiranAttachment::withoutGlobalScopes()->first();
        $this->assertNotNull($a, 'upload accepted');
        $r = $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/attachments/' . $a->id));
        $this->assertSame(200, $r->getStatusCode());
    }

    public function test_unicode_and_quoted_filenames_are_served_with_a_safe_disposition(): void
    {
        // Rule: Unicode / quote characters in a filename never break the response or inject a header.
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, $this->file('आधार "card" 100%.png', $this->png()))->assertSessionHasNoErrors();
        $a = DhiranAttachment::withoutGlobalScopes()->sole();
        $r = $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/attachments/' . $a->id))->assertOk();
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertNull($r->headers->get('X-Evil'));
    }

    public function test_attachment_owner_and_type_inputs_are_validated(): void
    {
        // Rule: bad owner type/id/doc type are refused; another shop's customer is a 404; nothing stored.
        $mine = $this->cust($this->shopA);
        $theirs = $this->cust($this->shopB);
        $f = fn () => $this->file('a.png', $this->png());
        $this->attach($this->ownerA, $mine, $f(), 'passport')->assertSessionHasErrors('document_type');
        $this->attach($this->ownerA, $mine, $f(), 'id_proof_front', ['owner_type' => 'user'])->assertSessionHasErrors('owner_type');
        $this->attach($this->ownerA, $mine, $f(), 'id_proof_front', ['owner_id' => 'abc'])->assertSessionHasErrors('owner_id');
        $this->attach($this->ownerA, $mine, $f(), 'id_proof_front', ['owner_id' => '99999999999999999999'])->assertSessionHasErrors('owner_id');
        $this->attach($this->ownerA, $mine, $f(), 'id_proof_front', ['owner_id' => 0])->assertNotFound();
        $this->attach($this->ownerA, $mine, $f(), 'id_proof_front', ['owner_id' => -5])->assertNotFound();
        $this->attach($this->ownerA, $theirs, $f())->assertNotFound();
        $this->assertSame(0, DhiranAttachment::withoutGlobalScopes()->count());
    }

    public function test_attachment_is_private_to_its_shop_and_still_served_after_the_loan_is_closed(): void
    {
        // Rule: other shops get 404; closing a loan does not remove or hide its evidence.
        $c = $this->cust($this->shopA);
        $loan = $this->loanFor($this->shopA, $c);
        $att = TenantContext::runFor($this->shopA->id, fn () => app(DhiranAttachmentService::class)->store(
            UploadedFile::fake()->image('i.jpg', 50, 50), $this->shopA->id, DhiranAttachment::OWNER_LOAN, $loan->id, 'item_photo', $this->ownerA->id));
        DhiranLoan::withoutGlobalScopes()->whereKey($loan->id)->update(['status' => 'closed']);
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/attachments/' . $att->id))->assertOk();
        $this->as($this->ownerB, fn () => $this->get(self::HOST . '/dhiran/attachments/' . $att->id))->assertNotFound();
        Storage::disk('local')->assertExists($att->file_path);
    }

    public function test_thumbnails_of_tiny_and_large_images_fit_320px_and_are_jpeg(): void
    {
        // Rule: ?thumb=1 gives a JPEG no larger than 320px on the long side; a 1x1 image works too.
        $c = $this->cust($this->shopA);
        foreach ([[1, 1], [2400, 1200]] as [$w, $h]) {
            $att = TenantContext::runFor($this->shopA->id, fn () => app(DhiranAttachmentService::class)->store(
                $this->file('t.png', $this->png($w, $h)), $this->shopA->id, DhiranAttachment::OWNER_CUSTOMER, $c->id, 'id_proof_front', null));
            $r = $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/attachments/' . $att->id . '?thumb=1'))->assertOk();
            $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
            [$tw, $th] = getimagesizefromstring($r->streamedContent() ?: $r->getContent());
            $this->assertLessThanOrEqual(320, max($tw, $th));
        }
    }

    public function test_a_png_decompression_bomb_is_refused_not_decoded(): void
    {
        // Rule: absurd pixel counts are refused before GD allocates the bitmap (the profile-photo encoder caps at 20 MP; attachments do not).
        $w = 30000; $h = 30000;
        $chunk = fn (string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $idat = '';
        $row = str_repeat("\0", $w + 1);
        for ($i = 0; $i < $h; $i += 1000) { $idat .= deflate_add($z, str_repeat($row, 1000), ZLIB_NO_FLUSH); }
        $idat .= deflate_add($z, '', ZLIB_FINISH);
        $bomb = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $chunk('IDAT', $idat) . $chunk('IEND', '');
        $this->assertLessThan(8 * 1024 * 1024, strlen($bomb));
        $c = $this->cust($this->shopA);
        $this->attach($this->ownerA, $c, $this->file('bomb.png', $bomb))->assertSessionHasErrors('file');
    }

    private function photo(User $u, Customer $c, ?UploadedFile $f, string $version = '', string $method = 'post')
    {
        $data = ['customer_photo_version' => $version] + ($f ? ['customer_photo' => $f] : []);

        return $this->as($u, fn () => $this->{$method . 'Json'}(self::HOST . '/dhiran/borrowers/' . $c->id . '/photo', $data));
    }

    public function test_profile_photo_round_trip_versioning_and_removal(): void
    {
        // Rule: a good photo becomes a WebP; a stale version is a 409; removal needs the current version and then 404s.
        $c = $this->cust($this->shopA);
        $v = $this->photo($this->ownerA, $c, $this->file('me.png', $this->png(64, 48)))->assertOk()->json('photo.version');
        $this->assertNotEmpty($v);
        $r = $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers/' . $c->id . '/photo'))->assertOk();
        $this->assertSame('image/webp', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->photo($this->ownerA, $c, $this->file('me.png', $this->png()), Str::uuid()->toString())->assertStatus(409);
        $this->photo($this->ownerA, $c, null, Str::uuid()->toString(), 'delete')->assertStatus(409);
        $this->photo($this->ownerA, $c, null, $v, 'delete')->assertOk();
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers/' . $c->id . '/photo'))->assertNotFound();
    }

    public static function badPhotos(): array
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>';
        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

        return ['svg' => ['a.png', $svg], 'gif' => ['a.gif', $gif], 'zero bytes' => ['a.png', ''], 'php' => ['a.jpg', '<?php echo 1;'], 'pdf' => ['a.png', "%PDF-1.4\n"]];
    }

    #[DataProvider('badPhotos')]
    public function test_profile_photo_rejects_non_images(string $name, string $bytes): void
    {
        // Rule: only JPEG/PNG/WebP photos; no row and no file is created otherwise.
        $c = $this->cust($this->shopA);
        $this->photo($this->ownerA, $c, $this->file($name, $bytes))->assertStatus(422);
        $this->assertSame(0, DB::table('customer_profile_photos')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('customer-profile-photos'));
    }

    public function test_profile_photo_rejects_over_20_megapixels_and_truncated_jpegs(): void
    {
        // Rule: >20 MP and cut-off JPEGs are refused; the previous photo is left alone.
        $c = $this->cust($this->shopA);
        $this->photo($this->ownerA, $c, $this->file('big.png', $this->headerOnlyPng(5001, 5000)))->assertStatus(422);
        $i = imagecreatetruecolor(300, 300);
        imagefilledellipse($i, 150, 150, 200, 200, 0xFF0000);
        ob_start(); imagejpeg($i); $jpg = (string) ob_get_clean();
        $this->photo($this->ownerA, $c, $this->file('cut.jpg', substr($jpg, 0, (int) (strlen($jpg) * 0.6))))->assertStatus(422);
        $this->assertSame(0, DB::table('customer_profile_photos')->count());
    }

    public function test_profile_photo_polyglot_is_reencoded_without_the_php(): void
    {
        // Rule: trailing code is either refused or dropped by re-encoding; it is never stored.
        $c = $this->cust($this->shopA);
        $r = $this->photo($this->ownerA, $c, $this->file('p.png', $this->png(8, 8) . '<?php echo 1; ?>'));
        $this->assertContains($r->getStatusCode(), [200, 422]);
        foreach (Storage::disk('local')->allFiles('customer-profile-photos') as $p) { $this->assertStringNotContainsString('<?php', Storage::disk('local')->get($p)); }
    }

    public function test_profile_photo_version_and_other_shop_guards(): void
    {
        // Rule: version must be a uuid or empty; another shop's customer is a 404.
        $c = $this->cust($this->shopA);
        $this->photo($this->ownerA, $c, $this->file('p.png', $this->png()), 'not-a-uuid')->assertStatus(422);
        $this->photo($this->ownerB, $c, $this->file('p.png', $this->png()))->assertNotFound();
        $this->photo($this->ownerB, $c, null, '', 'delete')->assertNotFound();
        $this->as($this->ownerB, fn () => $this->get(self::HOST . '/dhiran/borrowers/' . $c->id . '/photo'))->assertNotFound();
    }

    /* ═══════════════ 4. OLD-LOAN IMPORT ═══════════════ */

    private function row(array $over = []): array
    {
        $base = ['ticket_number' => 'T-1', 'loan_date' => CarbonImmutable::today()->subDays(200)->toDateString(), 'customer_name' => 'Asha Rao',
            'mobile' => '9810030001', 'address' => 'Market Road', 'pan' => '', 'id_number' => '', 'amount_lent' => '20000', 'principal_owed' => '20000',
            'interest_rate_monthly' => '2', 'interest_type' => 'flat', 'tenure_months' => '12',
            'interest_paid_up_to' => CarbonImmutable::today()->subDays(20)->toDateString(), 'unpaid_interest' => '', 'item_description' => 'Chain',
            'metal' => 'gold', 'purity' => '22', 'gross_weight_g' => '20', 'stone_weight_g' => '', 'rate_per_gram' => '6000', 'appraised_value' => ''];
        $r = $over + $base;

        return array_map(fn ($h) => (string) $r[$h], self::HEADER);
    }

    private function csvLine(array $r, string $d = ','): string
    {
        return implode($d, array_map(fn ($v) => '"' . str_replace('"', '""', $v) . '"', $r));
    }

    private function csvFile(array $rows, string $d = ',', bool $bom = false, ?array $header = null, string $name = 'book.csv'): UploadedFile
    {
        $lines = array_map(fn ($r) => $this->csvLine($r, $d), [$header ?? self::HEADER, ...$rows]);

        return $this->file($name, ($bom ? "\xEF\xBB\xBF" : '') . implode("\n", $lines) . "\n");
    }

    private function upload(UploadedFile $f)
    {
        return $this->as($this->ownerA, fn () => $this->post(self::HOST . '/dhiran/old-loans/upload', ['file' => $f]));
    }

    private function previewOf(UploadedFile $f)
    {
        $loc = $this->upload($f)->assertRedirect()->headers->get('Location');

        return [$loc, $this->as($this->ownerA, fn () => $this->get($loc))];
    }

    private function confirm(string $previewUrl)
    {
        return $this->as($this->ownerA, fn () => $this->post($previewUrl, ['idempotency_key' => $this->key()]));
    }

    private function loanCount(?Shop $s = null): int { return DhiranLoan::withoutGlobalScopes()->where('shop_id', ($s ?? $this->shopA)->id)->count(); }

    public function test_a_valid_single_row_imports(): void
    {
        // Rule: baseline: one good row becomes one active brought-forward loan with one borrower.
        [$loc] = $this->previewOf($this->csvFile([$this->row()]));
        $this->confirm($loc)->assertRedirect(route('dhiran.old-loans.index'))->assertSessionHas('success', fn ($m) => str_contains($m, '1 old loan brought in'));
        $this->assertSame(1, $this->loanCount());
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('shop_id', $this->shopA->id)->count());
    }

    public function test_utf8_bom_does_not_hide_the_first_column(): void
    {
        // Rule: a BOM-prefixed file reads like a plain one.
        [, $page] = $this->previewOf($this->csvFile([$this->row()], bom: true));
        $page->assertOk()->assertSee('1 loan ready');
    }

    public function test_semicolon_delimited_file_is_refused_with_a_message(): void
    {
        // Rule: a file that is not the template (wrong delimiter) is explained, never half-read or a 500.
        [, $page] = $this->previewOf($this->csvFile([$this->row()], ';'));
        $page->assertRedirect(route('dhiran.old-loans.upload'))->assertSessionHas('error');
    }

    public function test_missing_column_names_the_column_and_saves_nothing(): void
    {
        // Rule: a missing template column is named in the error.
        $header = array_values(array_diff(self::HEADER, ['pan']));
        $rows = [array_values(array_diff_key($this->row(), [5 => 1]))];
        [, $page] = $this->previewOf($this->csvFile($rows, header: $header));
        $page->assertRedirect(route('dhiran.old-loans.upload'))->assertSessionHas('error', fn ($m) => str_contains($m, 'pan'));
        $this->assertSame(0, $this->loanCount());
    }

    public function test_header_only_file_has_no_loans_and_saves_nothing(): void
    {
        // Rule: an empty template imports zero loans without an error page.
        [$loc, $page] = $this->previewOf($this->csvFile([]));
        $page->assertOk();
        $this->confirm($loc)->assertRedirect(route('dhiran.old-loans.index'));
        $this->assertSame(0, $this->loanCount());
    }

    public static function garbageSheets(): array
    {
        return ['text named xlsx' => ['this is not a zip'], 'truncated zip' => ["PK\x03\x04\x14\x00\x00\x00\x08\x00garbage"], 'binary' => [random_bytes(300)]];
    }

    #[DataProvider('garbageSheets')]
    public function test_a_corrupt_xlsx_is_explained_not_a_500(string $bytes): void
    {
        // Rule: any unreadable workbook gets the "upload the template" error on the upload page.
        $r = $this->upload($this->file('book.xlsx', $bytes));
        if ($r->isRedirect() && str_contains((string) $r->headers->get('Location'), '/upload/')) {
            $r = $this->as($this->ownerA, fn () => $this->get($r->headers->get('Location')));
        }
        $this->assertContains($r->getStatusCode(), [302, 422], 'got ' . $r->getStatusCode());
        $r->assertSessionHasErrors() ?: null;
    }

    public function test_formula_and_html_cells_import_as_text_are_escaped_in_the_preview_and_neutralised_in_exports(): void
    {
        // Rule: =cmd and <b> in a name/address are data: preview escapes them, the customer export prefixes an apostrophe.
        $evil = "=cmd|' /C calc'!A0";
        [$loc, $page] = $this->previewOf($this->csvFile([$this->row(['customer_name' => $evil . ' <b>x</b>', 'address' => '@SUM(1)'])]));
        $page->assertOk()->assertDontSee('<b>x</b>', false);
        $this->confirm($loc);
        $cells = $this->csvCells($this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers?export=csv'))->streamedContent());
        $flat = array_merge(...array_slice($cells, 1));
        $this->assertContains("'" . $evil . ' <b>x</b>', $flat);
        $this->assertContains("'@SUM(1)", $flat);
    }

    public static function badBookRows(): array
    {
        $today = CarbonImmutable::today();

        return [
            'future loan date'        => [['loan_date' => $today->addDay()->toDateString()]],
            'paid-up before loan'     => [['loan_date' => $today->subDays(10)->toDateString(), 'interest_paid_up_to' => $today->subDays(20)->toDateString()]],
            'paid-up in the future'   => [['interest_paid_up_to' => $today->addDays(5)->toDateString()]],
            'negative amount'         => [['amount_lent' => '-5', 'principal_owed' => '-5']],
            'zero amount'             => [['amount_lent' => '0', 'principal_owed' => '0']],
            'text amount'             => [['amount_lent' => 'abc']],
            'owes more than lent'     => [['principal_owed' => '30000']],
            'rate above 10'           => [['interest_rate_monthly' => '10.5']],
            'negative rate'           => [['interest_rate_monthly' => '-1']],
            'tenure zero'             => [['tenure_months' => '0']],
            'tenure 61'               => [['tenure_months' => '61']],
            'unknown interest type'   => [['interest_type' => 'weird']],
            'unknown metal'           => [['metal' => 'platinum']],
            'unsupported purity'      => [['purity' => '23']],
            'no weight'               => [['gross_weight_g' => '0']],
            'no rate'                 => [['rate_per_gram' => '']],
            'bad mobile'              => [['mobile' => '12345']],
            'missing mobile'          => [['mobile' => '']],
            'bad pan'                 => [['pan' => 'BADPAN']],
            'ticket too long'         => [['ticket_number' => str_repeat('T', 31)]],
            'impossible date'         => [['loan_date' => '31-02-2026']],
            'missing name'            => [['customer_name' => '']],
            'no item'                 => [['item_description' => '']],
            'huge weight'             => [['gross_weight_g' => '99999999']],
        ];
    }

    #[DataProvider('badBookRows')]
    public function test_a_bad_row_is_flagged_in_the_preview_and_nothing_is_saved_for_it(array $over): void
    {
        // Rule: a bad loan is shown with a problem, skipped on confirm, and leaves no loan and no customer behind.
        [$loc, $page] = $this->previewOf($this->csvFile([$this->row($over)]));
        $page->assertOk();
        $this->assertMatchesRegularExpression('/data-ok="0"/', $page->getContent());
        $this->confirm($loc)->assertRedirect(route('dhiran.old-loans.index'))->assertSessionHas('success', fn ($m) => str_contains($m, '0 old loans brought in'));
        $this->assertSame(0, $this->loanCount());
        $this->assertSame(0, Customer::withoutGlobalScopes()->where('shop_id', $this->shopA->id)->count());
    }

    public function test_a_ticket_already_in_the_books_is_a_problem_and_a_duplicate_ticket_in_the_file_is_one_loan_with_two_items(): void
    {
        // Rule: tickets are unique per shop; rows sharing a ticket in one file are items of one loan.
        $this->postOldLoan(['paper_loan_number' => 'EXIST-1'])->assertSessionHasNoErrors();
        [, $page] = $this->previewOf($this->csvFile([$this->row(['ticket_number' => 'EXIST-1'])]));
        $this->assertMatchesRegularExpression('/data-ok="0"/', $page->getContent());

        $second = $this->row(['item_description' => 'Ring', 'ticket_number' => 'D-1']);
        [$loc] = $this->previewOf($this->csvFile([$this->row(['ticket_number' => 'D-1']), $this->row(['ticket_number' => 'D-2', 'mobile' => '9810030002']), $second]));
        $this->confirm($loc);
        $loan = DhiranLoan::withoutGlobalScopes()->where('loan_number', 'D-1')->sole();
        $this->assertSame(2, $loan->items()->withoutGlobalScopes()->count());
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('loan_number', 'D-2')->count());
    }

    public function test_one_bad_loan_in_a_file_does_not_block_or_half_save_the_others(): void
    {
        // Rule: good loans come in whole; the bad one leaves nothing (no loan, no borrower).
        $rows = [$this->row(['ticket_number' => 'G-1', 'mobile' => '9810031001']),
            $this->row(['ticket_number' => 'B-1', 'mobile' => '9810031002', 'customer_name' => 'Bad Person', 'principal_owed' => '99999']),
            $this->row(['ticket_number' => 'G-2', 'mobile' => '9810031003'])];
        [$loc] = $this->previewOf($this->csvFile($rows));
        $this->confirm($loc)->assertSessionHas('success', fn ($m) => str_contains($m, '2 old loans brought in') && str_contains($m, '1 skipped'));
        $this->assertSame(['G-1', 'G-2'], DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shopA->id)->orderBy('loan_number')->pluck('loan_number')->all());
        $this->assertSame(0, Customer::withoutGlobalScopes()->where('first_name', 'Bad')->count());
        foreach (DhiranLoan::withoutGlobalScopes()->get() as $l) {
            $this->assertGreaterThan(0, $l->items()->withoutGlobalScopes()->count());
        }
    }

    public function test_a_spreadsheet_item_heavier_in_stone_than_in_gross_is_flagged(): void
    {
        // Rule: stone weight cannot exceed gross weight (net metal would be negative).
        [, $page] = $this->previewOf($this->csvFile([$this->row(['stone_weight_g' => '50'])]));
        $this->assertMatchesRegularExpression('/data-ok="0"/', $page->getContent());
    }

    public function test_a_spreadsheet_amount_beyond_the_money_column_is_flagged_not_a_500(): void
    {
        // Rule: absurd amounts (1e20) are a row problem, never a database error on confirm.
        [$loc, $page] = $this->previewOf($this->csvFile([$this->row(['amount_lent' => '1e20', 'principal_owed' => '1e20'])]));
        $this->assertMatchesRegularExpression('/data-ok="0"/', $page->getContent());
        $this->assertNotSame(500, $this->confirm($loc)->getStatusCode());
    }

    public function test_a_row_for_an_archived_borrower_is_skipped_not_fatal_to_the_whole_import(): void
    {
        // Rule (controller doc): "each on its own: one failing does not stop the rest". The service throws a
        // ValidationException for an archived borrower and confirmUpload only catches LogicException.
        $arch = $this->cust($this->shopA, ['mobile' => '9810039999']);
        $arch->forceFill(['is_active' => false])->save();
        $rows = [$this->row(['ticket_number' => 'G-1', 'mobile' => '9810031001']),
            $this->row(['ticket_number' => 'ARCH', 'mobile' => '9810039999']),
            $this->row(['ticket_number' => 'G-2', 'mobile' => '9810031003'])];
        [$loc] = $this->previewOf($this->csvFile($rows));
        $this->confirm($loc)->assertSessionHas('success', fn ($m) => str_contains($m, '2 old loans brought in'));
        $this->assertSame(['G-1', 'G-2'], DhiranLoan::withoutGlobalScopes()->orderBy('loan_number')->pluck('loan_number')->all());
    }

    public function test_same_borrower_in_different_mobile_spellings_is_one_customer(): void
    {
        // Rule: +91 98100 30001 and 9810030001 are one borrower across tickets.
        $rows = [$this->row(['ticket_number' => 'S-1', 'mobile' => '9810030001']), $this->row(['ticket_number' => 'S-2', 'mobile' => '+91 98100 30001'])];
        [$loc] = $this->previewOf($this->csvFile($rows));
        $this->confirm($loc);
        $this->assertSame(2, $this->loanCount());
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('shop_id', $this->shopA->id)->count());
    }

    public function test_more_than_2000_rows_are_refused_quickly_and_nothing_is_saved(): void
    {
        // Rule: 10,000 rows hit the 2000-row cap within seconds; no loan is created.
        $rows = [];
        for ($i = 0; $i < 10000; $i++) { $rows[] = $this->row(['ticket_number' => "N-$i", 'mobile' => '98' . str_pad((string) $i, 8, '0', STR_PAD_LEFT)]); }
        $file = $this->csvFile($rows);
        $this->assertLessThan(5 * 1024 * 1024, $file->getSize(), 'fixture under the 5 MB upload cap');
        $t = microtime(true);
        [, $page] = $this->previewOf($file);
        $this->assertLessThan(15, microtime(true) - $t);
        $page->assertRedirect(route('dhiran.old-loans.upload'))->assertSessionHas('error', fn ($m) => str_contains($m, '2000'));
        $this->assertSame(0, $this->loanCount());
    }

    public function test_exactly_2000_rows_preview_within_a_time_bound(): void
    {
        // Rule: the cap itself is accepted and previewed in under 60 seconds.
        $rows = [];
        for ($i = 0; $i < 2000; $i++) { $rows[] = $this->row(['ticket_number' => "N-$i", 'mobile' => '98' . str_pad((string) $i, 8, '0', STR_PAD_LEFT)]); }
        $t = microtime(true);
        [, $page] = $this->previewOf($this->csvFile($rows));
        $this->assertLessThan(60, microtime(true) - $t);
        $page->assertOk()->assertSee('2000 loans ready');
    }

    public function test_upload_guards_missing_oversize_and_executable_files(): void
    {
        // Rule: a file is required, <=5 MB, and csv/txt/xlsx only.
        $this->as($this->ownerA, fn () => $this->post(self::HOST . '/dhiran/old-loans/upload', []))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->create('big.csv', 5121, 'text/csv'))->assertSessionHasErrors('file');
        $this->upload($this->file('shell.php', '<?php echo 1;'))->assertSessionHasErrors('file');
        $this->upload($this->file('empty.csv', ''))->assertSessionHasErrors('file');
        $this->assertSame([], Storage::disk('local')->allFiles('dhiran-old-loans'));
    }

    public function test_preview_tokens_are_uuid_only_shop_private_and_single_use(): void
    {
        // Rule: path-like tokens 404, another shop's token 404, and a confirmed file cannot be confirmed twice.
        [$loc] = $this->previewOf($this->csvFile([$this->row()]));
        $token = basename($loc);
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/old-loans/upload/..%2F..%2F.env'))->assertNotFound();
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/old-loans/upload/not-a-uuid'))->assertNotFound();
        $this->as($this->ownerB, fn () => $this->get(self::HOST . '/dhiran/old-loans/upload/' . $token))->assertNotFound();
        $this->as($this->ownerB, fn () => $this->post(self::HOST . '/dhiran/old-loans/upload/' . $token, ['idempotency_key' => $this->key()]))->assertNotFound();
        $this->confirm($loc)->assertRedirect(route('dhiran.old-loans.index'));
        $this->confirm($loc)->assertNotFound();
        $this->assertSame(1, $this->loanCount());
        $this->assertSame(0, $this->loanCount($this->shopB));
    }

    private function oldLoanForm(array $over = []): array
    {
        $today = CarbonImmutable::today();

        return $over + ['idempotency_key' => $this->key(), 'paper_loan_number' => 'F-' . (++self::$seq), 'loan_date' => $today->subDays(100)->toDateString(),
            'principal_amount' => 10000, 'principal_owed' => 10000, 'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 12,
            'interest_paid_until' => $today->subDays(10)->toDateString(), 'gold_rate_on_date' => 6000,
            'borrower' => ['first_name' => 'Form', 'last_name' => 'Person', 'mobile' => '9810040001'],
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]]];
    }

    private function postOldLoan(array $over = [], ?User $u = null)
    {
        return $this->as($u ?? $this->ownerA, fn () => $this->post(self::HOST . '/dhiran/old-loans', $this->oldLoanForm($over)));
    }

    public function test_old_loan_form_baseline_creates_one_loan(): void
    {
        // Rule: baseline for the form attacks below.
        $this->postOldLoan()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->loanCount());
    }

    public function test_old_loan_form_refuses_an_amount_that_overflows_the_money_column(): void
    {
        // Rule: money inputs have a sane ceiling; 1e20 must be a validation error, not a database 500.
        $r = $this->postOldLoan(['principal_amount' => '100000000000000000000', 'principal_owed' => '100000000000000000000']);
        $this->assertNotSame(500, $r->getStatusCode());
        $r->assertSessionHasErrors();
        $this->assertSame(0, $this->loanCount());
    }

    public function test_new_loan_form_refuses_an_amount_that_overflows_the_money_column(): void
    {
        // Rule: same ceiling on New Loan.
        $c = $this->cust($this->shopA);
        $r = $this->as($this->ownerA, fn () => $this->post(self::HOST . '/dhiran', ['idempotency_key' => $this->key(), 'customer_id' => $c->id,
            'principal_amount' => '100000000000000000000', 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]]]));
        $this->assertNotSame(500, $r->getStatusCode());
        $this->assertSame(0, $this->loanCount());
    }

    public function test_old_loan_form_for_an_archived_borrower_by_mobile_or_id_creates_nothing(): void
    {
        // Rule: archived borrowers get no new loan, whether picked by id or matched by mobile.
        $c = $this->cust($this->shopA, ['mobile' => '9810040001']);
        $c->forceFill(['is_active' => false])->save();
        $this->postOldLoan(['customer_id' => $c->id, 'borrower' => null])->assertSessionHasErrors('customer_id');
        $r = $this->postOldLoan();
        $this->assertNotSame(500, $r->getStatusCode());
        $this->assertSame(0, $this->loanCount());
    }

    public function test_old_loan_form_cannot_use_another_shops_customer(): void
    {
        // Rule: a customer id from another shop is "not found".
        $theirs = $this->cust($this->shopB);
        $this->postOldLoan(['customer_id' => $theirs->id, 'borrower' => null])->assertSessionHasErrors('customer_id');
        $this->assertSame(0, $this->loanCount());
    }

    public function test_old_loan_form_field_boundaries(): void
    {
        // Rule: ticket <=30, rate <=10, tenure 1..60, owed <= lent, dates not in the future; nothing is saved for any.
        $this->postOldLoan(['paper_loan_number' => str_repeat('T', 31)])->assertSessionHasErrors('paper_loan_number');
        $this->postOldLoan(['interest_rate_monthly' => 10.01])->assertSessionHasErrors('interest_rate_monthly');
        $this->postOldLoan(['tenure_months' => 61])->assertSessionHasErrors('tenure_months');
        $this->postOldLoan(['principal_owed' => 10001])->assertSessionHasErrors('principal_owed');
        $this->postOldLoan(['loan_date' => CarbonImmutable::today()->addDay()->toDateString()])->assertSessionHasErrors('loan_date');
        $this->postOldLoan(['borrower' => ['first_name' => 'X', 'mobile' => '9810040001', 'pan' => 'BAD']])->assertSessionHasErrors('borrower.pan');
        $this->postOldLoan(['items' => []])->assertSessionHasErrors('items');
        $this->assertSame(0, $this->loanCount());
    }

    /* ═══════════════ 5. CSV EXPORTS ═══════════════ */

    public function test_customer_export_neutralises_every_formula_leader_and_keeps_unicode(): void
    {
        // Rule: any cell starting = + - @ TAB CR is apostrophe-prefixed; Unicode survives; a BOM leads the file.
        $leaders = ['=1+1', '+cmd|x', '-2+3+cmd|x', '@SUM(1)', "\tTAB", "\rCR"];
        foreach ($leaders as $i => $v) {
            $this->cust($this->shopA, ['first_name' => $v, 'last_name' => 'Z', 'address' => $v, 'notes' => $v, 'mobile' => '98100500' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }
        $this->cust($this->shopA, ['first_name' => 'राम', 'last_name' => '🙂', 'mobile' => '9810050099']);
        $csv = $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers?export=csv'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $cells = array_merge(...array_slice($this->csvCells($csv), 1));
        foreach ($leaders as $v) {
            $this->assertContains("'" . $v, $cells, 'address/notes cell neutralised');
            if (ltrim($v) === $v) { $this->assertContains("'" . $v . ' Z', $cells, 'name cell neutralised'); } // the name is trimmed, so a TAB/CR lead disappears
            $this->assertNotContains($v, $cells, 'raw formula never appears alone');
        }
        $this->assertContains('राम 🙂', $cells);
        foreach ($cells as $c) {
            $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $c === '' ? 'x' : (is_numeric($c) ? 'x' : $c));
        }
    }

    public function test_loan_export_neutralises_loan_notes_and_borrower_names(): void
    {
        // Rule: the loans export applies the same neutralising to notes and customer names.
        $c = $this->cust($this->shopA, ['first_name' => '=HYPERLINK("http://evil")', 'last_name' => 'Q']);
        $this->loanFor($this->shopA, $c, ['notes' => '@cmd']);
        $csv = $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/loans?export=csv'))->assertOk()->streamedContent();
        $cells = array_merge(...array_slice($this->csvCells($csv), 1));
        $this->assertContains("'@cmd", $cells);
        $this->assertContains("'=HYPERLINK(\"http://evil\") Q", $cells);
    }

    public function test_export_contains_only_the_signed_in_shops_rows(): void
    {
        // Rule: tenant isolation in every export.
        $this->cust($this->shopA, ['first_name' => 'MineA']);
        $b = $this->cust($this->shopB, ['first_name' => 'SecretB']);
        $this->loanFor($this->shopB, $b, ['loan_number' => 'BLOAN-1']);
        foreach (['/dhiran/borrowers?export=csv', '/dhiran/loans?export=csv'] as $p) {
            $csv = $this->as($this->ownerA, fn () => $this->get(self::HOST . $p))->assertOk()->streamedContent();
            $this->assertStringNotContainsString('SecretB', $csv);
            $this->assertStringNotContainsString('BLOAN-1', $csv);
        }
        $this->assertStringContainsString('MineA', $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers?export=csv'))->streamedContent());
    }

    public function test_a_big_export_is_streamed_complete_and_ignores_pagination(): void
    {
        // Rule: 1200 customers (more than two read chunks) all appear in a streamed response regardless of ?page/per_page.
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 1200; $i++) {
            $rows[] = ['shop_id' => $this->shopA->id, 'first_name' => "Bulk$i", 'last_name' => 'C', 'mobile' => '97' . str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'customer_code' => "BLK$i", 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 300) as $chunk) { DB::table('customers')->insert($chunk); }
        $r = $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/borrowers?export=csv&page=2&per_page=10'))->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $r->baseResponse);
        $this->assertStringContainsString('text/csv', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertCount(1201, $this->csvCells($r->streamedContent()));
    }

    /* ═══════════════ 6. PLATFORM ADMIN ═══════════════ */

    private function admin(array $attrs = []): PlatformAdmin
    {
        return PlatformAdmin::create(array_merge(['name' => 'Synthetic Platform Admin', 'email' => 'admin@example.test', 'password' => 'Admin-pass-12345', 'role' => 'super_admin', 'is_active' => true,
            'two_factor_secret' => app(TotpService::class)->generateSecret(), 'two_factor_enabled' => true], $attrs))->fresh();
    }

    private function signIn(?PlatformAdmin $admin = null): PlatformAdmin
    {
        $admin ??= $this->admin();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-pass-12345'])->assertRedirect('/admin/mfa');
        $this->post('/admin/mfa', ['code' => app(TotpService::class)->computeCode($admin->two_factor_secret, (int) floor(time() / 30))])->assertRedirect('/admin');

        return $admin;
    }

    private function plan(string $code = 'dhiran_monthly'): Plan
    {
        (new \Database\Seeders\PlanSeeder)->run();

        return Plan::where('code', $code)->firstOrFail();
    }

    private function planBody(array $o = []): array
    {
        return $o + ['price_monthly' => 1852, 'grace_days' => 7, 'staff_limit' => 3, 'max_items' => 1000, 'is_active' => 1];
    }

    public static function badPlanInputs(): array
    {
        return [
            'negative price'      => ['price_monthly', -1],
            'huge price'          => ['price_monthly', 10000001],
            'fractional price'    => ['price_monthly', '18.52'],
            'text price'          => ['price_monthly', 'free'],
            'negative grace'      => ['grace_days', -1],
            'grace over 90'       => ['grace_days', 91],
            'staff limit zero'    => ['staff_limit', 0],
            'staff limit huge'    => ['staff_limit', 1001],
            'max items zero'      => ['max_items', 0],
            'max items negative'  => ['max_items', -3],
            'max items huge'      => ['max_items', 1000001],
            'active not boolean'  => ['is_active', 'maybe'],
            'reason too long'     => ['reason', str_repeat('r', 501)],
            'missing price'       => ['price_monthly', null],
        ];
    }

    #[DataProvider('badPlanInputs')]
    public function test_plan_update_rejects_bad_numbers_and_changes_nothing(string $field, mixed $value): void
    {
        // Rule: price 0..10,000,000 integer, grace 0..90, staff 1..1000, items 1..1,000,000, is_active boolean, reason <=500.
        $this->signIn();
        $plan = $this->plan();
        $before = $plan->only('price_monthly', 'grace_days', 'features');
        $this->patch('/admin/plans/' . $plan->id, $this->planBody([$field => $value]))->assertSessionHasErrors($field);
        $this->assertEquals($before, $plan->fresh()->only('price_monthly', 'grace_days', 'features'));
    }

    public function test_an_active_plan_cannot_be_priced_at_zero(): void
    {
        // Rule: no free plan (no free trial): a ₹0 monthly price on an active plan is refused (min is 0 today).
        $this->signIn();
        $plan = $this->plan();
        $this->patch('/admin/plans/' . $plan->id, $this->planBody(['price_monthly' => 0]))->assertSessionHasErrors('price_monthly');
        $this->assertSame(1852, (int) $plan->fresh()->price_monthly);
    }

    public function test_the_last_active_plan_cannot_be_deactivated_but_one_of_two_can(): void
    {
        // Rule: at least one plan stays active.
        $this->signIn();
        $monthly = $this->plan('dhiran_monthly');
        $yearly = $this->plan('dhiran_yearly');
        $this->patch('/admin/plans/' . $monthly->id, $this->planBody(['is_active' => 0]))->assertSessionHasNoErrors();
        $this->assertFalse((bool) $monthly->fresh()->is_active);
        $this->patch('/admin/plans/' . $yearly->id, $this->planBody(['price_monthly' => 0 + $yearly->price_monthly, 'is_active' => 0, 'grace_days' => 14, 'staff_limit' => 7, 'max_items' => 3000]))->assertSessionHasErrors('is_active');
        $this->assertTrue((bool) $yearly->fresh()->is_active);
    }

    public function test_a_monthly_only_plan_never_gains_a_yearly_price(): void
    {
        // Rule: price_yearly is only editable on a plan that already sells yearly.
        $this->signIn();
        $plan = $this->plan('dhiran_monthly');
        $this->assertNull($plan->price_yearly);
        $this->patch('/admin/plans/' . $plan->id, $this->planBody(['price_yearly' => 19999]))->assertSessionHasNoErrors();
        $this->assertNull($plan->fresh()->price_yearly);
    }

    public function test_plan_update_for_an_unknown_id_is_404_and_audit_reason_html_is_escaped(): void
    {
        // Rule: unknown plan 404; an HTML reason is stored verbatim but escaped on the audit page.
        $this->signIn();
        $plan = $this->plan();
        $this->patch('/admin/plans/999999', $this->planBody())->assertNotFound();
        $this->patch('/admin/plans/' . $plan->id, $this->planBody(['reason' => '<script>alert(9)</script>']))->assertSessionHasNoErrors();
        $this->get('/admin/audit')->assertOk()->assertDontSee('<script>alert(9)</script>', false)->assertSee('&lt;script&gt;alert(9)&lt;/script&gt;', false);
    }

    public function test_admin_shop_search_treats_wildcards_quotes_and_backslashes_literally(): void
    {
        // Rule: % _ ' " \ are literal characters in the shop search; nothing errors.
        $this->signIn();
        foreach (['Plain Shop', 'Percent%Shop', 'Under_score', 'UnderXscore', "O'Brien \"Gold\"", 'Back\\slash'] as $n) { Shop::create(['name' => $n]); }
        $names = fn (string $q) => $this->get('/admin/shops?q=' . urlencode($q))->assertOk()->viewData('shops')->pluck('name')->sort()->values()->all();
        $this->assertSame(['Percent%Shop'], $names('%'));
        $this->assertSame(['Under_score'], $names('_'));
        $this->assertSame(["O'Brien \"Gold\""], $names("'"));
        $this->assertSame(["O'Brien \"Gold\""], $names('"'));
        $this->assertSame(['Back\\slash'], $names('\\'));
        $this->assertSame([], $names("'; DROP TABLE shops;--"));
        $this->assertSame(['Plain Shop'], $names('plain'));
    }

    public function test_admin_shop_pagination_bounds_never_error(): void
    {
        // Rule: any page value gives a normal page, never an error.
        $this->signIn();
        foreach (['0', '-1', '99999', 'abc', '1.5', '9999999999999999999'] as $p) {
            $this->get('/admin/shops?page=' . $p)->assertOk();
        }
        $this->get('/admin/shops?page[]=2')->assertStatus(200);
    }

    public function test_admin_shop_search_with_an_array_query_is_not_a_500(): void
    {
        // Rule: a malformed q (array) behaves like an empty search.
        $this->signIn();
        $r = $this->get('/admin/shops?q[]=x');
        $this->assertNotSame(500, $r->getStatusCode());
    }

    public function test_admin_shop_update_validation_and_unknown_shop(): void
    {
        // Rule: status active|suspended with a reason <=500; unknown shop 404; letters in the id never match a route.
        $this->signIn();
        $shop = Shop::create(['name' => 'Target']);
        $this->patch('/admin/shops/' . $shop->id, ['status' => 'deleted', 'reason' => 'x'])->assertSessionHasErrors('status');
        $this->patch('/admin/shops/' . $shop->id, ['status' => 'suspended'])->assertSessionHasErrors('reason');
        $this->patch('/admin/shops/' . $shop->id, ['status' => 'suspended', 'reason' => str_repeat('r', 501)])->assertSessionHasErrors('reason');
        $this->patch('/admin/shops/99999', ['status' => 'suspended', 'reason' => 'x'])->assertNotFound();
        $this->get('/admin/shops/abc')->assertNotFound();
        $this->assertSame('active', $shop->fresh()->status);
        $this->patch('/admin/shops/' . $shop->id, ['status' => 'suspended', 'reason' => 'Chargeback'])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $shop->fresh()->status);
    }

    public static function badGrants(): array
    {
        $in = fn (int $d) => CarbonImmutable::today()->addDays($d)->toDateString();

        return [
            'unknown plan'        => [['plan' => 'nope'], 'plan'],
            'weekly cycle'        => [['billing_cycle' => 'weekly'], 'billing_cycle'],
            'US date format'      => [['ends_at' => '12/31/2030'], 'ends_at'],
            'impossible date'     => [['ends_at' => '2030-02-31'], 'ends_at'],
            'unpadded date'       => [['ends_at' => '2030-2-3'], 'ends_at'],
            'past end date'       => [['ends_at' => '2020-01-01'], 'ends_at'],
            'missing reason'      => [['reason' => ''], 'reason'],
            'reason too long'     => [['reason' => str_repeat('r', 501)], 'reason'],
        ];
    }

    #[DataProvider('badGrants')]
    public function test_admin_subscription_grant_rejects_bad_input_and_creates_nothing(array $over, string $field): void
    {
        // Rule: plan must exist, cycle monthly|yearly, ends_at strict Y-m-d and not in the past, reason required <=500.
        $this->signIn();
        $shop = Shop::create(['name' => 'Grantee']);
        $this->plan();
        $this->post('/admin/shops/' . $shop->id . '/subscription', $over + ['plan' => 'dhiran_monthly', 'billing_cycle' => 'monthly',
            'ends_at' => CarbonImmutable::today()->addMonth()->toDateString(), 'reason' => 'Gift'])->assertSessionHasErrors($field);
        $this->assertSame(0, \App\Models\Platform\ShopSubscription::where('shop_id', $shop->id)->count());
    }

    public function test_admin_terminate_without_a_live_subscription_is_refused_and_a_far_future_grant_is_accepted(): void
    {
        // Rule: nothing to terminate -> error; a 9999-12-31 end date does not overflow.
        $this->signIn();
        $shop = Shop::create(['name' => 'Grantee']);
        $this->plan();
        $this->post('/admin/shops/' . $shop->id . '/subscription/terminate', ['reason' => 'x'])->assertSessionHasErrors('reason');
        $r = $this->post('/admin/shops/' . $shop->id . '/subscription', ['plan' => 'dhiran_monthly', 'billing_cycle' => 'monthly', 'ends_at' => '9999-12-31', 'reason' => 'Lifetime']);
        $this->assertNotSame(500, $r->getStatusCode());
    }

    public function test_unresolved_payment_resolve_is_idempotent_validated_and_404_for_unknown(): void
    {
        // Rule: reason required; unknown id 404; resolving twice writes one audit row.
        $admin = $this->signIn();
        app(\App\Services\Billing\SubscriptionPaymentService::class)->recordUnresolvedPayment('order_ABC', 'pay_XYZ', 'amount mismatch');
        $ev = \App\Models\Platform\SubscriptionEvent::where('event_type', 'payment.unresolved')->sole();
        $this->post('/admin/unresolved-payments/' . $ev->id . '/resolve', [])->assertSessionHasErrors('reason');
        $this->post('/admin/unresolved-payments/' . $ev->id . '/resolve', ['reason' => str_repeat('r', 501)])->assertSessionHasErrors('reason');
        $this->post('/admin/unresolved-payments/999999/resolve', ['reason' => 'x'])->assertNotFound();
        $this->post('/admin/unresolved-payments/' . $ev->id . '/resolve', ['reason' => 'First <b>note</b>'])->assertRedirect();
        $this->post('/admin/unresolved-payments/' . $ev->id . '/resolve', ['reason' => 'Second note'])->assertRedirect();
        $this->assertSame(1, DB::table('platform_audit_logs')->where('action', 'payment.unresolved_resolved')->count());
        $this->assertSame('First <b>note</b>', $ev->fresh()->after['resolution_note']);
        $this->get('/admin/unresolved-payments')->assertOk()->assertDontSee('<b>note</b>', false);
        $this->assertNotNull($admin);
    }

    public function test_admin_account_creation_validates_name_email_password_and_permissions(): void
    {
        // Rule: lowercase unique email, password >=12, name <=120, permissions only from the known list, HTML escaped on the list.
        $this->signIn();
        $ok = ['name' => 'Support One', 'email' => 'support1@example.test', 'password' => 'Long-pass-12345', 'permissions' => ['can_manage_shops']];
        $this->post('/admin/accounts', ['email' => 'not-an-email'] + $ok)->assertSessionHasErrors('email');
        $this->post('/admin/accounts', ['email' => 'Support1@Example.test'] + $ok)->assertSessionHasErrors('email');
        $this->post('/admin/accounts', ['email' => 'ADMIN@example.test'] + $ok)->assertSessionHasErrors('email');
        $this->post('/admin/accounts', ['email' => 'admin@example.test'] + $ok)->assertSessionHasErrors('email');
        $this->post('/admin/accounts', ['password' => 'Short-pass1'] + $ok)->assertSessionHasErrors('password');
        $this->post('/admin/accounts', ['name' => str_repeat('n', 121)] + $ok)->assertSessionHasErrors('name');
        $this->post('/admin/accounts', ['permissions' => ['can_do_anything']] + $ok)->assertSessionHasErrors('permissions.0');
        $this->post('/admin/accounts', ['permissions' => 'can_manage_shops'] + $ok)->assertSessionHasErrors('permissions');
        $this->assertSame(1, PlatformAdmin::count());
        $this->post('/admin/accounts', ['name' => '<script>alert(5)</script>'] + $ok)->assertSessionHasNoErrors();
        $this->assertSame('support', PlatformAdmin::where('email', 'support1@example.test')->value('role'));
        $this->get('/admin/accounts')->assertOk()->assertDontSee('<script>alert(5)</script>', false);
    }

    public function test_admin_access_update_cannot_touch_super_admins_or_unknown_ids_and_validates_flags(): void
    {
        // Rule: super admins are 403; unknown 404; is_active boolean; permissions from the list.
        $super = $this->signIn();
        $support = $this->admin(['email' => 'sup@example.test', 'role' => 'support', 'permissions' => ['can_manage_shops']]);
        $this->patch('/admin/accounts/' . $super->id, ['is_active' => 0])->assertForbidden();
        $this->patch('/admin/accounts/99999', ['is_active' => 0])->assertNotFound();
        $this->patch('/admin/accounts/' . $support->id, ['is_active' => 'maybe'])->assertSessionHasErrors('is_active');
        $this->patch('/admin/accounts/' . $support->id, ['is_active' => 1, 'permissions' => ['root']])->assertSessionHasErrors('permissions.0');
        $this->patch('/admin/accounts/' . $support->id, ['is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $support->fresh()->is_active);
        $this->assertSame([], $support->fresh()->permissions);
        $this->assertTrue((bool) $super->fresh()->is_active);
    }

    public function test_create_admin_command_rejects_bad_and_existing_emails_in_any_case(): void
    {
        // Rule: invalid address -> failure, no row; an existing address (any case/padding) -> failure; a padded new one is normalised.
        $this->artisan('dhiran:create-admin', ['email' => 'not-an-email'])->assertExitCode(1);
        $this->artisan('dhiran:create-admin', ['email' => 'a@b@c.test'])->assertExitCode(1);
        $this->artisan('dhiran:create-admin', ['email' => "x@example.test\nBcc: y@example.test"])->assertExitCode(1);
        $this->assertSame(0, PlatformAdmin::count());
        $this->artisan('dhiran:create-admin', ['email' => '  First@Example.TEST '])->assertExitCode(0);
        $this->assertSame('first@example.test', PlatformAdmin::sole()->email);
        $this->artisan('dhiran:create-admin', ['email' => 'FIRST@example.test'])->assertExitCode(1);
        $this->artisan('dhiran:create-admin', ['email' => ' first@example.test'])->assertExitCode(1);
        $this->assertSame(1, PlatformAdmin::count());
        $this->assertTrue((bool) PlatformAdmin::sole()->isSuperAdmin());
    }

    /* ═══════════════ 7. SETTINGS + STAFF ═══════════════ */

    private function settingsBody(array $o = []): array
    {
        return $o + ['default_interest_rate_monthly' => 2, 'default_interest_type' => 'flat', 'default_penalty_rate_monthly' => 1, 'default_min_interest_months' => 1,
            'default_ltv_ratio' => 75, 'high_value_threshold' => 500000, 'high_value_ltv_ratio' => 70, 'min_loan_amount' => 1000, 'max_loan_amount' => 1000000,
            'processing_fee_type' => 'flat', 'processing_fee_value' => 0, 'default_tenure_months' => 12, 'default_min_lock_months' => 0, 'grace_period_days' => 7,
            'forfeiture_notice_days' => 30, 'loan_number_prefix' => 'DH'];
    }

    private function patchSettings(array $o = [])
    {
        return $this->as($this->ownerA, fn () => $this->patch(self::HOST . '/dhiran/settings', $this->settingsBody($o)));
    }

    public function test_settings_baseline_saves(): void
    {
        // Rule: baseline for the attacks below.
        $this->patchSettings()->assertSessionHasNoErrors()->assertRedirect(route('dhiran.settings'));
    }

    public static function badSettings(): array
    {
        return [
            'rate over 10'          => [['default_interest_rate_monthly' => 11], 'default_interest_rate_monthly'],
            'negative rate'         => [['default_interest_rate_monthly' => -1], 'default_interest_rate_monthly'],
            'ltv zero'              => [['default_ltv_ratio' => 0], 'default_ltv_ratio'],
            'ltv 101'               => [['default_ltv_ratio' => 101], 'default_ltv_ratio'],
            'max below min'         => [['min_loan_amount' => 5000, 'max_loan_amount' => 4000], 'max_loan_amount'],
            'tenure zero'           => [['default_tenure_months' => 0], 'default_tenure_months'],
            'grace 91'              => [['grace_period_days' => 91], 'grace_period_days'],
            'prefix with space'     => [['loan_number_prefix' => 'AB CD'], 'loan_number_prefix'],
            'prefix with html'      => [['loan_number_prefix' => '<b>'], 'loan_number_prefix'],
            'prefix 11 chars'       => [['loan_number_prefix' => 'ABCDEFGHIJK'], 'loan_number_prefix'],
            'terms 5001 chars'      => [['receipt_terms_text' => str_repeat('a', 5001)], 'receipt_terms_text'],
            'unknown fee type'      => [['processing_fee_type' => 'free'], 'processing_fee_type'],
            'reminder days 0'       => [['reminder_days_before_due' => 0], 'reminder_days_before_due'],
        ];
    }

    #[DataProvider('badSettings')]
    public function test_settings_boundaries_are_enforced(array $over, string $field): void
    {
        // Rule: rate 0..10, LTV 1..100, max>=min, tenure>=1, grace<=90, prefix [A-Za-z0-9-_]{1,10}, texts <=5000.
        $before = DhiranSettings::getForShop($this->shopA->id)->only('default_ltv_ratio', 'loan_number_prefix');
        $this->patchSettings($over)->assertSessionHasErrors($field);
        $this->assertEquals($before, DhiranSettings::getForShop($this->shopA->id)->fresh()->only('default_ltv_ratio', 'loan_number_prefix'));
    }

    public function test_a_percent_processing_fee_over_one_hundred_is_refused(): void
    {
        // Rule: a percentage fee cannot exceed 100% (no ceiling today: 150% is accepted).
        $this->patchSettings(['processing_fee_type' => 'percent', 'processing_fee_value' => 150])->assertSessionHasErrors('processing_fee_value');
    }

    public function test_settings_amount_that_overflows_the_column_is_a_validation_error_not_a_500(): void
    {
        // Rule: money settings have a ceiling; 1e20 must not reach the database.
        $r = $this->patchSettings(['min_loan_amount' => '100000000000000000000', 'max_loan_amount' => '100000000000000000001']);
        $this->assertNotSame(500, $r->getStatusCode());
    }

    public function test_settings_terms_text_cannot_break_out_of_its_textarea_or_inject_script(): void
    {
        // Rule: stored terms text is escaped on the settings page and on a printed receipt.
        $evil = '</textarea><script>alert(3)</script>';
        $this->patchSettings(['receipt_terms_text' => $evil, 'closure_certificate_text' => $evil])->assertSessionHasNoErrors();
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/settings'))->assertOk()->assertDontSee($evil, false);
        $loan = $this->loanFor($this->shopA, $this->cust($this->shopA));
        $this->as($this->ownerA, fn () => $this->get(self::HOST . '/dhiran/loans/' . $loan->id . '/receipt'))->assertOk()->assertDontSee('<script>alert(3)</script>', false);
    }

    private function staffBody(array $o = []): array
    {
        return $o + ['name' => 'Clerk One', 'mobile_number' => '9810060001', 'email' => 'clerk1@ruth.test', 'password' => 'Abcdefghij12', 'password_confirmation' => 'Abcdefghij12',
            'role_id' => Role::withoutTenant()->where('shop_id', $this->shopA->id)->where('name', 'staff')->value('id')];
    }

    private function postStaff(array $o = [], ?User $u = null)
    {
        return $this->as($u ?? $this->ownerA, fn () => $this->post('/staff', $this->staffBody($o)));
    }

    public function test_staff_baseline_and_password_boundary_is_twelve_with_mixed_case_and_digit(): void
    {
        // Rule: >=12 chars with upper, lower and a digit.
        $this->postStaff(['password' => 'Abcdefghi12', 'password_confirmation' => 'Abcdefghi12'])->assertSessionHasErrors('password');
        $this->postStaff(['password' => 'abcdefghij12', 'password_confirmation' => 'abcdefghij12'])->assertSessionHasErrors('password');
        $this->postStaff(['password' => 'ABCDEFGHIJ12', 'password_confirmation' => 'ABCDEFGHIJ12'])->assertSessionHasErrors('password');
        $this->postStaff(['password' => 'Abcdefghijkl', 'password_confirmation' => 'Abcdefghijkl'])->assertSessionHasErrors('password');
        $this->assertSame(0, User::where('shop_id', $this->shopA->id)->where('mobile_number', '9810060001')->count());
        $this->postStaff()->assertSessionHasNoErrors();
        $this->assertSame(1, User::where('mobile_number', '9810060001')->count());
    }

    public function test_staff_email_is_unique_across_shops_ignoring_case(): void
    {
        // Rule: Owner@Ruth.test and owner@ruth.test are one identity (login lowercases the email).
        $this->postStaff(['email' => 'OWNERB@Ruth.TEST'])->assertSessionHasErrors('email');
        $this->assertSame(0, User::where('mobile_number', '9810060001')->count());
    }

    public function test_staff_mobile_is_stored_canonical_and_can_log_in_with_it(): void
    {
        // Rule: a staff mobile typed as "+91 98100 60001" is stored as 9810060001 (login looks up the canonical form).
        $this->postStaff(['mobile_number' => '+91 98100 60001'])->assertSessionHasNoErrors();
        $this->assertSame('9810060001', User::where('email', 'clerk1@ruth.test')->value('mobile_number'));
        auth()->logout();
        $this->post('/login', ['email' => '9810060001', 'password' => 'Abcdefghij12']);
        $this->assertAuthenticated();
    }

    public function test_staff_mobile_duplicate_in_another_spelling_is_a_validation_error_not_a_500(): void
    {
        // Rule: the login mobile is globally unique in every spelling.
        $this->postStaff(['mobile_number' => '9810060001', 'email' => 'c1@ruth.test'])->assertSessionHasNoErrors();
        $r = $this->postStaff(['mobile_number' => '+91 98100 60001', 'email' => 'c2@ruth.test'], $this->ownerB);
        $this->assertNotSame(500, $r->getStatusCode());
        $this->assertSame(1, User::whereIn('mobile_number', ['9810060001', '+91 98100 60001'])->count());
    }

    public function test_staff_cannot_be_given_the_owner_role_or_another_shops_role(): void
    {
        // Rule: role must belong to this shop and not be the owner role.
        $ownerRole = Role::withoutTenant()->where('shop_id', $this->shopA->id)->where('name', 'owner')->value('id');
        $foreign = Role::withoutTenant()->where('shop_id', $this->shopB->id)->where('name', 'staff')->value('id');
        $this->postStaff(['role_id' => $ownerRole])->assertSessionHasErrors('role_id');
        $this->postStaff(['role_id' => $foreign])->assertSessionHasErrors('role_id');
        $this->assertSame(0, User::where('mobile_number', '9810060001')->count());
    }

    public function test_staff_role_id_that_is_not_a_number_is_a_validation_error_not_a_500(): void
    {
        // Rule: junk role ids are 422s (the exists rule sends "abc" to a bigint column).
        $r = $this->postStaff(['role_id' => 'abc']);
        $this->assertNotSame(500, $r->getStatusCode());
        $r->assertSessionHasErrors('role_id');
    }

    public function test_staff_limit_is_enforced_on_direct_post(): void
    {
        // Rule: when the plan allows 1 staff, the 2nd POST is refused and creates no user.
        $plan = Plan::where('code', 'dhiran_yearly')->firstOrFail();
        $plan->update(['features' => [...($plan->features ?? []), 'staff_limit' => 1]]);
        $this->postStaff()->assertSessionHasNoErrors();
        $this->postStaff(['mobile_number' => '9810060002', 'email' => 'clerk2@ruth.test'])->assertSessionHas('error');
        $this->assertSame(0, User::where('mobile_number', '9810060002')->count());
        $this->as($this->ownerA, fn () => $this->get('/staff/create'))->assertRedirect(route('settings.edit', ['tab' => 'staff']));
    }

    public function test_staff_text_fields_are_length_capped_and_array_values_are_not_a_500(): void
    {
        // Rule: name <=255, email <=255 and valid; array values are validation errors.
        $this->postStaff(['name' => str_repeat('n', 256)])->assertSessionHasErrors('name');
        $this->postStaff(['email' => 'not an email'])->assertSessionHasErrors('email');
        $r = $this->postStaff(['name' => ['x'], 'mobile_number' => ['9810060001'], 'email' => ['a@b.test'], 'role_id' => ['1']]);
        $this->assertNotSame(500, $r->getStatusCode());
        $this->assertSame(0, User::where('mobile_number', '9810060001')->count());
    }

    public function test_role_display_name_with_html_is_escaped_on_the_roles_tab(): void
    {
        // Rule: role names render escaped.
        $evil = '<img src=x onerror=alert(4)>';
        TenantContext::runFor($this->shopA->id, fn () => Role::where('name', 'staff')->update(['display_name' => $evil]));
        $this->as($this->ownerA, fn () => $this->get('/settings?tab=roles'))->assertOk()->assertDontSee($evil, false);
        $this->as($this->ownerA, fn () => $this->get('/settings?tab=staff'))->assertOk()->assertDontSee($evil, false);
    }

    public function test_role_permission_update_rejects_unknown_ids_and_foreign_roles(): void
    {
        // Rule: only existing permission ids; a role of another shop is a 404; scalar permissions are 422.
        $role = Role::withoutTenant()->where('shop_id', $this->shopA->id)->where('name', 'staff')->first();
        $foreign = Role::withoutTenant()->where('shop_id', $this->shopB->id)->where('name', 'staff')->first();
        $before = $role->permissions()->pluck('permissions.id')->sort()->values()->all();
        $this->as($this->ownerA, fn () => $this->patch('/settings/roles/' . $role->id, ['permissions' => [999999]]))->assertSessionHasErrors('permissions.0');
        $this->as($this->ownerA, fn () => $this->patch('/settings/roles/' . $role->id, ['permissions' => 'x']))->assertSessionHasErrors('permissions');
        $this->as($this->ownerA, fn () => $this->patch('/settings/roles/' . $foreign->id, ['permissions' => [Permission::first()->id]]))->assertNotFound();
        $this->assertSame($before, $role->permissions()->pluck('permissions.id')->sort()->values()->all());
    }

    public static function junkPermissionIds(): array
    {
        return [['abc'], ['99999999999999999999'], ['-1'], ['1.5'], ['1; DROP TABLE roles']];
    }

    #[DataProvider('junkPermissionIds')]
    public function test_role_permission_update_with_a_non_numeric_id_is_a_validation_error_not_a_500(string $junk): void
    {
        // Rule: junk permission ids are 422s (the `exists` rule alone sends them to an integer column).
        $role = Role::withoutTenant()->where('shop_id', $this->shopA->id)->where('name', 'staff')->first();
        $r = $this->as($this->ownerA, fn () => $this->patch('/settings/roles/' . $role->id, ['permissions' => [$junk]]));
        $this->assertNotSame(500, $r->getStatusCode());
        $r->assertSessionHasErrors('permissions.0');
    }

    public function test_staff_cannot_be_edited_or_removed_across_shops_and_the_owner_is_untouchable(): void
    {
        // Rule: another shop's staff 403; the owner is never manageable; removing yourself is refused.
        $theirs = self::userWithRole($this->shopB, ['name' => 'B Staff', 'mobile_number' => '9810060088', 'email' => 'bs@ruth.test', 'password' => 'a-long-test-password', 'is_active' => true], 'staff');
        $this->as($this->ownerA, fn () => $this->delete('/staff/' . $theirs->id))->assertForbidden();
        $this->as($this->ownerA, fn () => $this->delete('/staff/' . $this->ownerA->id))->assertForbidden();
        $this->assertTrue((bool) $theirs->fresh()->is_active);
        $this->assertTrue((bool) $this->ownerA->fresh()->is_active);
    }
}
