<?php
namespace Tests\Feature\Adversarial;

use App\Mail\ShopDecisionMail;
use App\Models\Customer;
use App\Models\Dhiran\{DhiranAttachment, DhiranLoan, DhiranSettings};
use App\Models\Platform\{Plan, ShopSubscription, SubscriptionEvent};
use App\Models\{Role, Shop, User};
use App\Services\Dhiran\Notices;
use App\Services\DhiranService;
use App\Services\PlatformAuditService;
use App\Support\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, DB, Mail, Route, Schema, Storage};
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\OnboardingFixtures;

/**
 * Adversarial QA of the new features: admin shop queue, owner onboarding/resubmit, notices bell + settings, error pages, image compression.
 * Tests named test_BUG_* assert the SAFE behaviour and are expected to FAIL until the app is fixed (reason in each comment).
 */
class NewFeaturesBreakTest extends AdminTestCase
{
    use OnboardingFixtures;

    private const SCRIPT = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        \Illuminate\Support\Facades\Cache::flush(); // rate-limiter counters live in the file cache and would leak between tests
        (new PlanSeeder)->run();
        config(['app.debug' => false]);
        Notices::forget();
    }

    protected function tearDown(): void { Notices::forget(); \Illuminate\Support\Carbon::setTestNow(); parent::tearDown(); }

    /* ───────────── helpers (never named get/post/put/patch/delete/call/json) ───────────── */

    private function adm(): static { Auth::shouldUse('platform_admin'); return $this; }
    private function own(User $u): static { Auth::shouldUse('web'); return $this->actingAs($u->fresh()); }
    private function signedAdmin(): void { \App\Models\Platform\PlatformAdmin::query()->update(['last_totp_window' => 0]); $this->signIn($this->admin()); }
    private function enforceCsrf(): void
    {
        $guard = new class($this->app, $this->app->make('encrypter')) extends ValidateCsrfToken { protected function runningUnitTests() { return false; } };
        $this->app->instance(ValidateCsrfToken::class, $guard);
    }

    /** A real new owner whose business form was sent: the shop waits. @return array{0:Shop,1:User} */
    private function waiting(string $name = 'Sharma Gold', string $address = 'Main Road'): array
    {
        $owner = $this->newOwner(); Auth::shouldUse('web');
        $this->actingAs($owner)->post(route('dhiran.onboarding.store'), ['name' => $name, 'phone' => '9876543210', 'address' => $address])->assertRedirect(route('dhiran.pending'));
        Auth::guard('web')->logout();
        $owner = $owner->fresh();

        return [Shop::findOrFail($owner->shop_id), $owner];
    }

    private function rejected(string $reason = 'Phone does not match'): array
    {
        [$shop, $owner] = $this->waiting();
        $this->adm()->post('/admin/requests/' . $shop->id . '/reject', ['reason' => $reason])->assertSessionHasNoErrors();

        return [$shop->fresh(), $owner->fresh()];
    }

    private function noPlan(): void { Plan::where('code', 'dhiran_yearly')->delete(); }

    /* ───────────────────────────── ADMIN QUEUE ───────────────────────────── */

    public function test_decisions_without_a_csrf_token_are_419_and_change_nothing(): void
    {
        $this->signedAdmin(); [$shop, $owner] = $this->waiting();
        $this->enforceCsrf();
        foreach (['approve', 'reject'] as $do) {
            $this->adm()->post('/admin/requests/' . $shop->id . '/' . $do, ['reason' => 'x'])->assertStatus(419);
        }
        $this->adm()->post('/admin/shops/' . $shop->id . '/extend')->assertStatus(419);
        $this->own($owner)->post(route('dhiran.resubmit'), ['name' => 'x'])->assertStatus(419);
        $this->assertSame('pending', $shop->fresh()->status);
        $this->assertSame(0, ShopSubscription::count());
        Mail::assertNothingSent();
    }

    public function test_guests_owners_and_wrong_verbs_cannot_decide(): void
    {
        $admin = $this->admin(); $this->signIn($admin); [$shop, $owner] = $this->waiting();
        Auth::guard('platform_admin')->logout();
        $this->flushSession();
        foreach (['approve', 'reject'] as $do) { $this->post('/admin/requests/' . $shop->id . '/' . $do, ['reason' => 'x'])->assertRedirect('/admin/login'); }
        $this->own($owner)->post('/admin/shops/' . $shop->id . '/extend')->assertRedirect('/admin/login');
        $this->assertSame('pending', $shop->fresh()->status);
        \App\Models\Platform\PlatformAdmin::query()->update(['last_totp_window' => 0]);   // the sign-in code is single-use per 30 s window
        $this->signIn($admin);
        $this->adm()->get('/admin/requests/' . $shop->id . '/approve')->assertStatus(405);
        $this->adm()->patch('/admin/requests/' . $shop->id . '/reject', ['reason' => 'x'])->assertStatus(405);
        $this->assertSame('pending', $shop->fresh()->status);
    }

    public function test_every_decision_needs_its_own_permission(): void
    {
        $this->limited(['can_manage_subscriptions']); [$shop] = $this->waiting();
        $this->adm()->post('/admin/requests/' . $shop->id . '/approve')->assertForbidden();   // approve also needs can_manage_shops
        $this->adm()->post('/admin/requests/' . $shop->id . '/reject', ['reason' => 'x'])->assertForbidden();
        $this->assertSame('pending', $shop->fresh()->status);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'admin.permission_denied']);
    }

    public function test_extend_needs_the_subscriptions_permission(): void
    {
        $this->limited(['can_manage_shops']); [$shop] = $this->waiting();
        $this->adm()->post('/admin/shops/' . $shop->id . '/extend')->assertForbidden();
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_extend_only_works_on_an_approved_shop(): void
    {
        $this->signedAdmin(); [$shop] = $this->waiting();
        foreach (['pending', 'rejected', 'suspended'] as $status) {
            $shop->forceFill(['status' => $status])->save();
            $this->adm()->post('/admin/shops/' . $shop->id . '/extend')->assertSessionHas('error');
        }
        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_odd_shop_ids_are_404_never_a_decision(): void
    {
        $this->signedAdmin(); [$shop] = $this->waiting();
        $gone = Shop::create(['name' => 'Gone', 'status' => 'pending']); $goneId = $gone->id; $gone->delete();
        foreach (['abc', '0', '-1', '1.5', '1e3', '0x1A', "1'", (string) $goneId, '9223372036854775807'] as $id) {
            foreach (['approve', 'reject'] as $do) { $this->adm()->post('/admin/requests/' . rawurlencode($id) . '/' . $do, ['reason' => 'x'])->assertNotFound(); }
            $this->adm()->post('/admin/shops/' . rawurlencode($id) . '/extend')->assertNotFound();
        }
        $this->assertSame('pending', $shop->fresh()->status);
    }

    /** Route::whereNumber lets 20 digits through to an `int $shop` parameter: PHP throws TypeError, so the admin gets a 500. */
    public function test_BUG_huge_numeric_shop_id_is_404_not_500(): void
    {
        $this->signedAdmin();
        foreach (['99999999999999999999', '9223372036854775808'] as $id) {
            $this->adm()->post('/admin/requests/' . $id . '/approve')->assertNotFound();
            $this->adm()->post('/admin/requests/' . $id . '/reject', ['reason' => 'x'])->assertNotFound();
            $this->adm()->post('/admin/shops/' . $id . '/extend')->assertNotFound();
            $this->adm()->get('/admin/shops/' . $id)->assertNotFound();
        }
    }

    /** Same overflow through implicit route binding: Postgres "value out of range for type bigint" becomes a 500. */
    public function test_BUG_huge_numeric_ids_in_shop_routes_are_404_not_500(): void
    {
        [$shop, $owner] = $this->noticeShop('9390024110', 'Big Id Co');
        foreach (['/dhiran/attachments/99999999999999999999', '/dhiran/loans/99999999999999999999'] as $url) {
            $this->own($owner)->get($url)->assertNotFound();
        }
    }

    public function test_posted_plan_dates_status_and_grace_are_ignored_on_approval(): void
    {
        $this->signedAdmin(); [$shop] = $this->waiting();
        $this->adm()->post('/admin/requests/' . $shop->id . '/approve', ['plan' => 'dhiran_monthly', 'plan_id' => 999, 'ends_at' => today()->addYears(40)->toDateString(), 'grace_ends_at' => today()->addYears(40)->toDateString(),
            'status' => 'suspended', 'billing_cycle' => 'monthly', 'review_note' => 'hacked', 'id' => 1, 'submissions' => 77])->assertSessionHasNoErrors();
        $shop->refresh(); $sub = $shop->currentSubscription();
        $this->assertSame('active', $shop->status); $this->assertNull($shop->review_note);
        $this->assertSame('dhiran_yearly', $sub->plan->code); $this->assertSame('yearly', $sub->billing_cycle);
        $this->assertSame(today()->addYear()->toDateString(), $sub->ends_at->toDateString());
        $this->assertSame(today()->addYear()->toDateString(), $sub->grace_ends_at->toDateString());
    }

    public function test_bad_reasons_are_refused_and_the_shop_keeps_waiting(): void
    {
        $this->signedAdmin(); [$shop] = $this->waiting();
        foreach ([null, '', '   ', "\t\n ", str_repeat('a', 501), str_repeat('😀', 501), str_repeat('अ', 10000), ['x'], ['a' => ['b']]] as $reason) {
            $this->adm()->post('/admin/requests/' . $shop->id . '/reject', ['reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->adm()->post('/admin/requests/' . $shop->id . '/reject')->assertSessionHasErrors('reason');
        $this->assertSame('pending', $shop->fresh()->status);
        Mail::assertNothingSent();
        $this->adm()->post('/admin/requests/' . $shop->id . '/reject', ['reason' => str_repeat('😀', 500)])->assertSessionHasNoErrors();   // exactly 500 characters (many bytes) fits the text column
        $this->assertSame(500, mb_strlen($shop->fresh()->review_note));
    }

    public function test_a_reason_is_stored_and_shown_escaped_on_the_admin_page_the_owner_page_and_in_the_email(): void
    {
        $this->signedAdmin(); [$shop, $owner] = $this->waiting();
        $this->adm()->post('/admin/requests/' . $shop->id . '/reject', ['reason' => self::SCRIPT])->assertSessionHasNoErrors();
        $this->assertSame(self::SCRIPT, $shop->fresh()->review_note);
        $baseline = substr_count($this->adm()->get('/admin/requests')->getContent(), '<script');
        $page = $this->adm()->get('/admin/requests')->assertOk();
        $page->assertDontSee('<script>alert(1)', false)->assertDontSee('<img src=x', false)->assertSee(e(self::SCRIPT), false);
        $this->assertSame($baseline, substr_count($page->getContent(), '<script'));
        $this->own($owner)->get(route('dhiran.pending'))->assertOk()->assertDontSee('<script>alert(1)', false)->assertDontSee('<img src=x', false)->assertSee(e(self::SCRIPT), false);
        Mail::assertSent(ShopDecisionMail::class, function (ShopDecisionMail $m) {
            $html = $m->render();
            $this->assertStringNotContainsString('<script', $html); $this->assertStringNotContainsString('<img src=x', $html); $this->assertStringContainsString(e(self::SCRIPT), $html);

            return true;
        });
        // sent again: the earlier reason is shown on the admin page as "Turned down before", still escaped
        $this->own($owner)->post(route('dhiran.resubmit'), ['name' => 'Sharma Gold', 'phone' => '9876543210', 'address' => 'Main Road'])->assertRedirect(route('dhiran.pending'));
        $this->adm()->get('/admin/requests')->assertOk()->assertSee('Turned down before')->assertDontSee('<script>alert(1)', false)->assertSee(e(self::SCRIPT), false);
    }

    public function test_newlines_in_a_reason_cannot_change_the_mail_headers(): void
    {
        $this->signedAdmin(); [$shop, $owner] = $this->waiting();
        $this->adm()->post('/admin/requests/' . $shop->id . '/reject', ['reason' => "Bad\r\nBcc: evil@example.test\r\nSubject: hacked\r\n\r\n<b>x</b>"])->assertSessionHasNoErrors();
        Mail::assertSent(ShopDecisionMail::class, function (ShopDecisionMail $m) use ($owner) {
            $this->assertSame('About your MyDhiranBook shop request', $m->envelope()->subject);
            $this->assertTrue($m->hasTo($owner->email)); $this->assertFalse($m->hasBcc('evil@example.test')); $this->assertFalse($m->hasTo('evil@example.test'));
            $this->assertStringNotContainsString('<b>x</b>', $m->render());

            return true;
        });
    }

    public function test_hostile_shop_names_cannot_break_out_of_the_requests_page(): void
    {
        $this->signedAdmin(); $this->waiting('Benign Gold');
        $baseline = substr_count($this->adm()->get('/admin/requests')->getContent(), '<script');
        $this->assertGreaterThan(0, $baseline);
        $names = ["');alert(1);//", '</script><script>alert(1)</script>', '`${alert(1)}`', '" onmouseover="alert(1)', "@js('x')", '{{ 7*7 }}', '{!! "<i>" !!}', '<svg onload=alert(1)>', "Ram's \"Gold\" & Sons"];
        foreach ($names as $n) { $this->waiting($n); }
        $page = $this->adm()->get('/admin/requests')->assertOk();
        $html = $page->getContent();
        $this->assertSame($baseline, substr_count($html, '<script'), 'a shop name must never add a script tag');
        foreach (["');alert(1);//", '</script><script>', '" onmouseover="', '<svg onload', '{!! "<i>"'] as $raw) { $this->assertStringNotContainsString($raw, $html, "raw payload $raw in page"); }
        foreach ($names as $n) {
            $this->assertStringContainsString('data-rq-confirm="Approve ' . e($n) . ' for one year?"', $html);
            $this->assertStringContainsString('data-rq-confirm="Turn down ' . e($n) . '? The owner is told the reason."', $html);
        }
        $this->assertStringContainsString('{{ 7*7 }}', $html);   // template syntax is data, never evaluated
        $this->assertStringNotContainsString('>49<', $html);
    }

    public function test_a_failing_mail_server_never_blocks_or_leaks_into_the_decision(): void
    {
        $this->signedAdmin(); [$shop] = $this->waiting();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down user=mailer password=hunter2secret'));
        $r = $this->adm()->post('/admin/requests/' . $shop->id . '/approve')->assertRedirect()->assertSessionHas('success')->assertSessionMissing('error');
        $this->assertStringNotContainsString('hunter2secret', $r->getContent());
        $this->assertStringNotContainsString('hunter2secret', json_encode(session()->all()));
        $this->assertSame('active', $shop->fresh()->status);
        $this->assertTrue($shop->fresh()->hasEdition('dhiran'));
    }

    public function test_approving_without_the_yearly_plan_row_leaves_no_half_state(): void
    {
        $this->signedAdmin(); [$shop] = $this->waiting();
        $this->noPlan();
        $r = $this->adm()->post('/admin/requests/' . $shop->id . '/approve');
        $this->assertContains($r->getStatusCode(), [302, 404, 422, 500]);
        $this->assertSame('pending', $shop->fresh()->status, 'shop must not be activated without its subscription');
        $this->assertSame(0, ShopSubscription::count()); $this->assertSame(0, SubscriptionEvent::count());
        Mail::assertNothingSent();
    }

    public function test_approval_is_all_or_nothing_when_a_step_inside_it_fails(): void
    {
        $this->signedAdmin(); [$shop] = $this->waiting();
        $this->partialMock(PlatformAuditService::class, fn ($m) => $m->shouldReceive('log')->andThrow(new \RuntimeException('audit store down')));
        $this->adm()->post('/admin/requests/' . $shop->id . '/approve')->assertStatus(500);
        $this->assertSame('pending', $shop->fresh()->status);
        $this->assertSame(0, ShopSubscription::count()); $this->assertSame(0, SubscriptionEvent::count());
        Mail::assertNothingSent();
        $this->adm()->post('/admin/requests/' . $shop->id . '/reject', ['reason' => 'x'])->assertStatus(500);
        $this->assertSame('pending', $shop->fresh()->status); $this->assertNull($shop->fresh()->review_note);
    }

    public function test_double_approval_makes_one_subscription_one_event_one_mail(): void
    {
        $this->signedAdmin(); [$shop] = $this->waiting();
        $this->adm()->post('/admin/requests/' . $shop->id . '/approve')->assertSessionHas('success');
        $this->adm()->post('/admin/requests/' . $shop->id . '/approve')->assertSessionHas('error');
        $this->adm()->post('/admin/requests/' . $shop->id . '/reject', ['reason' => 'late'])->assertSessionHas('error');
        $this->assertSame(1, ShopSubscription::where('shop_id', $shop->id)->count()); $this->assertSame(1, SubscriptionEvent::where('shop_id', $shop->id)->count());
        Mail::assertSentCount(1); $this->assertSame('active', $shop->fresh()->status);
    }

    /* ───────────────────────────── OWNER FLOWS ───────────────────────────── */

    private function payload(array $o = []): array { return $o + ['name' => 'Adv Gold', 'phone' => '9876543210', 'address' => 'Main Road']; }

    public function test_the_business_form_refuses_hostile_input_and_creates_no_shop(): void
    {
        $owner = $this->newOwner();
        $cases = [
            ['name' => str_repeat('A', 100000)], ['name' => str_repeat('😀', 151)], ['name' => ['x']], ['name' => ''], ['name' => '   '], ['address' => str_repeat('x', 501)], ['address' => ['x']],
            ['phone' => '98765'], ['phone' => 'abcdefghij'], ['phone' => '9876543210; DROP TABLE shops;--'], ['phone' => '1234567890'], ['phone' => '0000000000'], ['phone' => str_repeat('9', 100000)], ['phone' => '+1 9876543210'],
            ['loan_number_prefix' => '../'], ['loan_number_prefix' => 'a b'], ['loan_number_prefix' => 'ABCDEFGHIJKLM'], ['loan_number_prefix' => '<b>'], ['loan_number_prefix' => ['x']], ['loan_number_prefix' => "a'; DROP"],
        ];
        foreach ($cases as $i => $over) {
            Auth::shouldUse('web'); \Illuminate\Support\Facades\Cache::flush();   // the form is limited to 10 tries a minute: each case starts fresh
            $r = $this->actingAs($owner)->post(route('dhiran.onboarding.store'), $this->payload($over));
            $this->assertLessThan(500, $r->getStatusCode(), 'case ' . $i . ' ' . json_encode(array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 30) : $v, $over)));
            $this->assertTrue(session()->has('errors'), 'case ' . $i . ' passed validation: ' . json_encode(array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 40) : $v, $over)));
        }
        $this->assertSame(0, Shop::count()); $this->assertNull($owner->fresh()->shop_id);
    }

    /** Mobile::normalize(?string) is called with the raw input BEFORE validation: phone[]=x is a TypeError (500) instead of a validation message. */
    public function test_BUG_an_array_phone_is_a_validation_error_not_a_500(): void
    {
        $owner = $this->newOwner(); Auth::shouldUse('web');
        $this->actingAs($owner)->post(route('dhiran.onboarding.store'), $this->payload(['phone' => ['9876543210']]))->assertSessionHasErrors('phone');
        [, $rejOwner] = $this->rejected();
        $this->own($rejOwner)->post(route('dhiran.resubmit'), $this->payload(['phone' => ['x']]))->assertSessionHasErrors('phone');
    }

    /** Postgres text columns cannot hold NUL or invalid UTF-8; the rules only say string|max, so these may reach the DB as a 500 (unverified without a DB). */
    public function test_BUG_nul_bytes_and_invalid_utf8_are_validation_errors_not_a_500(): void
    {
        foreach (["Gold\0Shop", "Gold\xC3\x28Shop"] as $name) {
            $owner = $this->newOwner(); Auth::shouldUse('web');
            $r = $this->actingAs($owner)->post(route('dhiran.onboarding.store'), $this->payload(['name' => $name]));
            $this->assertLessThan(500, $r->getStatusCode(), 'name ' . bin2hex($name));
        }
    }

    public function test_unicode_names_and_every_phone_spelling_are_stored_clean(): void
    {
        $owner = $this->newOwner(); Auth::shouldUse('web');
        $this->actingAs($owner)->post(route('dhiran.onboarding.store'), $this->payload(['name' => '🙏 श्री गोल्ड ज्वैलर्स', 'address' => 'पता, मुख्य मार्ग', 'loan_number_prefix' => 'AB-C_1']))->assertRedirect(route('dhiran.pending'));
        $shop = Shop::findOrFail($owner->fresh()->shop_id);
        $this->assertSame('🙏 श्री गोल्ड ज्वैलर्स', $shop->name); $this->assertSame('AB-C_1', DhiranSettings::getForShop($shop->id)->loan_number_prefix);
        foreach (['+91 98765 43210', '09876543210', '98765-43210', '919876543210', '+91-98765-43210', '0091 9876543210', ' 9876543210 '] as $phone) {
            $o = $this->newOwner(); Auth::shouldUse('web');
            $this->actingAs($o)->post(route('dhiran.onboarding.store'), $this->payload(['phone' => $phone]))->assertRedirect(route('dhiran.pending'))->assertSessionHasNoErrors();
            $this->assertSame('9876543210', Shop::findOrFail($o->fresh()->shop_id)->phone, "phone [$phone]");
        }
    }

    public function test_submitting_the_business_form_twice_makes_one_shop(): void
    {
        $owner = $this->newOwner(); Auth::shouldUse('web');
        $this->actingAs($owner)->post(route('dhiran.onboarding.store'), $this->payload())->assertRedirect(route('dhiran.pending'));
        $id = $owner->fresh()->shop_id;
        $this->actingAs($owner->fresh())->post(route('dhiran.onboarding.store'), $this->payload(['name' => 'Second Shop', 'loan_number_prefix' => 'ZZ']))->assertRedirect(route('dhiran.pending'));
        $this->assertSame(1, Shop::count()); $this->assertSame($id, $owner->fresh()->shop_id); $this->assertSame('Adv Gold', Shop::findOrFail($id)->name);
        $this->assertSame(1, Role::withoutTenant()->where('shop_id', $id)->where('name', 'owner')->count());
    }

    public function test_only_a_rejected_shops_owner_can_send_again_and_extra_fields_do_nothing(): void
    {
        $this->signedAdmin(); [$shop, $owner] = $this->rejected('Phone does not match');
        $staff = self::userWithRole($shop, ['name' => 'Staff', 'email' => 'staff@adv.test', 'mobile_number' => '9100000001', 'password' => 'a-long-test-password', 'is_active' => true, 'email_verified_at' => now(), 'realm' => 'dhiran'], 'staff');
        $this->own($staff)->post(route('dhiran.resubmit'), $this->payload(['name' => 'Staff Takeover']))->assertForbidden();
        $this->assertSame('rejected', $shop->fresh()->status); $this->assertSame('Sharma Gold', $shop->fresh()->name);
        $before = $shop->fresh()->submissions;
        $this->own($owner)->post(route('dhiran.resubmit'), $this->payload(['name' => '', 'status' => 'active']))->assertSessionHasErrors('name');
        $this->assertSame('rejected', $shop->fresh()->status);
        $this->own($owner)->post(route('dhiran.resubmit'), $this->payload(['name' => '🙏 Fixed', 'status' => 'active', 'submissions' => 99, 'review_note' => 'x', 'reviewed_at' => now()->toDateTimeString(), 'id' => 1, 'shop_id' => 1]))->assertRedirect(route('dhiran.pending'));
        $shop->refresh();
        $this->assertSame('pending', $shop->status); $this->assertSame('🙏 Fixed', $shop->name); $this->assertEquals($before + 1, $shop->submissions);
        $this->assertSame('Phone does not match', $shop->previous_review_note); $this->assertNull($shop->review_note); $this->assertNull($shop->reviewed_at);
        // a second send (double submit / back button) is told it is not waiting for changes and changes nothing
        $this->own($owner)->post(route('dhiran.resubmit'), $this->payload(['name' => 'Hijack']))->assertSessionHas('error');
        $this->assertSame('🙏 Fixed', $shop->fresh()->name); $this->assertEquals($before + 1, $shop->fresh()->submissions);
    }

    public function test_resubmit_is_a_noop_for_active_and_suspended_shops(): void
    {
        $this->signedAdmin(); [$shop, $owner] = $this->waiting();
        $this->adm()->post('/admin/requests/' . $shop->id . '/approve');
        foreach (['active', 'suspended'] as $status) {
            $shop->forceFill(['status' => $status])->save();
            $this->own($owner)->post(route('dhiran.resubmit'), $this->payload(['name' => 'Hijack']))->assertSessionHas('error');
            $this->assertSame('Sharma Gold', $shop->fresh()->name); $this->assertSame($status, $shop->fresh()->status);
        }
    }

    public function test_the_eleventh_resubmit_in_a_minute_is_429_with_a_friendly_page_and_json_for_json(): void
    {
        $this->signedAdmin(); [, $owner] = $this->rejected();
        for ($i = 1; $i <= 10; $i++) { $this->own($owner)->post(route('dhiran.resubmit'), ['name' => ''])->assertStatus(302); }
        $this->own($owner)->post(route('dhiran.resubmit'), ['name' => ''])->assertStatus(429)->assertSee('Too many tries')->assertDontSee('Illuminate')->assertDontSee('Whoops');
        $r = $this->own($owner)->postJson(route('dhiran.resubmit'), ['name' => ''])->assertStatus(429);
        $this->assertStringContainsString('json', (string) $r->headers->get('Content-Type'));
        $this->assertStringNotContainsString('Illuminate', $r->getContent());
    }

    /* ───────────────────────────── NOTICES ───────────────────────────── */

    private function noticeShop(string $mobile, string $name): array
    {
        $shop = self::createDhiranShop(['name' => $name, 'shop_type' => 'dhiran', 'phone' => $mobile, 'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $mobile, 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $owner = self::userWithRole($shop, ['name' => 'Owner', 'mobile_number' => $mobile, 'email' => "o$mobile@adv.test", 'password' => 'a-long-test-password', 'is_active' => true, 'email_verified_at' => now(), 'realm' => 'dhiran']);
        $cust = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'Ram', 'last_name' => 'Lal', 'mobile' => '9815' . substr($mobile, -6)]));

        return [$shop, $owner, $cust];
    }

    private function loanFor(Shop $shop, User $owner, Customer $c, array $set = []): DhiranLoan
    {
        $this->actingAs($owner);
        $loan = TenantContext::runFor($shop->id, fn () => app(DhiranService::class)->createLoan($shop, $c, [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000]],
            ['principal_amount' => 50000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 12, 'grace_period_days' => 5, 'loan_date' => today()->toDateString(), 'created_by' => $owner->id]));
        if ($set) { DhiranLoan::withoutGlobalScopes()->whereKey($loan->id)->update($set); }
        Notices::forget();

        return $loan;
    }

    private function noticeBody(array $over = []): array { return $over + ['popup' => '1', 'days' => [3, 7, 30], 'forfeit_days' => 30, 'show' => ['forfeit_ready', 'forfeit_notice', 'inst_late', 'pending', 'inst_w1', 'inst_w2', 'inst_w3', 'mat_w1', 'mat_w2', 'mat_w3', 'forfeit_soon']]; }

    public function test_hostile_notice_settings_are_refused_before_any_sql_and_change_nothing(): void
    {
        [$shop, $owner, $c] = $this->noticeShop('9390024100', 'Notice A');
        $this->loanFor($shop, $owner, $c, ['status' => 'pending_evidence']);
        $cases = [
            'days' => [['3); DROP TABLE dhiran_loans;--', 7, 30], [3, 7, '30 OR 1=1'], [3.5, 7, 30], ['3.0', 7, 30], [-1, 7, 30], [[3], 7, 30], ['a', 'b', 'c'], [3, 7], [3, 7, 30, 40], [3, 3, 30], [7, 3, 30], [3, 7, 7],
                [1, 2, 91], [0, 7, 30], ['1e1', 20, 30], 'x', null, [3, 7, null]],
            'forfeit_days' => [0, 91, -5, '30; DROP TABLE x', [30], '', 'abc', 30.5],
            'show' => [['bogus'], 'x', [['pending']], ['pending', "x'); DROP TABLE y;--"]],
        ];
        foreach ($cases as $field => $values) {
            foreach ($values as $v) {
                $this->own($owner)->patch(route('dhiran.settings.notices.update'), $this->noticeBody([$field => $v]))->assertSessionHasErrors();
            }
        }
        $this->assertTrue(Schema::hasTable('dhiran_loans'));
        Notices::forget(); $o = Notices::options($shop->id);
        $this->assertSame([3, 7, 30], $o['days']); $this->assertSame(30, $o['forfeit_days']); $this->assertSame([], $o['off']);
    }

    /** `days` keys are kept by array_map: days[a]=3&days[b]=7&days[c]=30 passes validation and then `$days[0]` is an undefined key (ErrorException, 500). */
    public function test_BUG_string_keyed_days_are_a_validation_error_not_a_500(): void
    {
        [$shop, $owner] = $this->noticeShop('9390024101', 'Notice K');
        foreach ([['a' => 3, 'b' => 7, 'c' => 30], [1 => 3, 2 => 7, 3 => 30]] as $days) {
            $r = $this->own($owner)->patch(route('dhiran.settings.notices.update'), $this->noticeBody(['days' => $days]));
            $this->assertLessThan(500, $r->getStatusCode());
        }
        Notices::forget(); $this->assertSame([3, 7, 30], Notices::options($shop->id)['days']);
    }

    public function test_windows_are_bound_parameters_and_stored_junk_cannot_reach_sql(): void
    {
        [$shop, $owner, $c] = $this->noticeShop('9390024102', 'Notice B');
        $this->loanFor($shop, $owner, $c, ['status' => 'pending_evidence']);
        $this->own($owner)->patch(route('dhiran.settings.notices.update'), $this->noticeBody(['days' => [11, 22, 33], 'forfeit_days' => 47]))->assertRedirect(route('dhiran.settings.notices'));
        $log = []; DB::listen(function ($q) use (&$log) { $log[] = [$q->sql, $q->bindings]; });
        Notices::forget(); Notices::forShop($shop->id);
        $main = collect($log)->first(fn ($l) => str_contains($l[0], 'dhiran_loans') && str_contains($l[0], 'FILTER'));
        $this->assertNotNull($main);
        foreach (['11', '22', '33', '47'] as $n) { $this->assertStringNotContainsString($n, $main[0], "window $n inlined in SQL"); }
        $ints = array_map('intval', array_filter($main[1], 'is_numeric'));
        foreach ([11, 12, 22, 23, 33, 47] as $n) { $this->assertContains($n, $ints); }
        // even a poisoned settings row (written behind the validator) is cast to integers before it is used
        DhiranSettings::getForShop($shop->id)->update(['notices_interest_days' => ['3); DROP TABLE dhiran_loans;--', 7, 30], 'notices_forfeit_days' => 0]);
        Notices::forget(); $this->assertSame([3, 7, 30], Notices::options($shop->id)['days']); $this->assertSame(30, Notices::options($shop->id)['forfeit_days']);
        $this->assertSame(1, collect(Notices::forShop($shop->id))->pluck('count', 'key')['pending']);
        $this->assertTrue(Schema::hasTable('dhiran_loans'));
    }

    public function test_the_smallest_and_largest_windows_still_run(): void
    {
        [$shop, $owner, $c] = $this->noticeShop('9390024103', 'Notice C');
        $this->loanFor($shop, $owner, $c, ['status' => 'pending_evidence']);
        $this->own($owner)->patch(route('dhiran.settings.notices.update'), $this->noticeBody(['days' => ['1', '2', '90'], 'forfeit_days' => '90', 'popup' => 'banana']))->assertSessionHasNoErrors();
        Notices::forget(); $o = Notices::options($shop->id);
        $this->assertSame([1, 2, 90], $o['days']); $this->assertFalse($o['popup']);
        $this->assertArrayHasKey('pending', collect(Notices::forShop($shop->id))->pluck('count', 'key')->all());
        $this->own($owner)->get('/dhiran/loans')->assertOk()->assertSee('1 loan waiting for evidence');
    }

    public function test_the_seen_route_is_post_only_guarded_and_touches_only_the_caller(): void
    {
        [$shop, $owner] = $this->noticeShop('9390024104', 'Notice D');
        $nobody = new Role(); $nobody->forceFill(['name' => 'nobody', 'display_name' => 'Nobody', 'shop_id' => $shop->id])->save();
        $blind = User::create(['name' => 'Blind', 'mobile_number' => '9100000002', 'email' => 'blind@adv.test', 'password' => 'a-long-test-password', 'is_active' => true, 'email_verified_at' => now(), 'realm' => 'dhiran', 'shop_id' => $shop->id, 'role_id' => $nobody->id]);
        $this->own($blind)->post(route('dhiran.notices.seen'))->assertForbidden();
        $this->assertNull($blind->fresh()->notices_seen_on);
        $this->own($blind)->get('/dhiran/loans')->assertForbidden();
        $this->own($owner)->get(route('dhiran.notices.seen'))->assertStatus(405);
        $this->assertNull($owner->fresh()->notices_seen_on);
        $this->own($owner)->post(route('dhiran.notices.seen'))->assertNoContent();
        $this->assertSame(today()->toDateString(), $owner->fresh()->notices_seen_on->toDateString()); $this->assertNull($blind->fresh()->notices_seen_on);
        Auth::guard('web')->logout(); $this->flushSession();
        $this->post(route('dhiran.notices.seen'))->assertRedirect(route('login'));
    }

    private function bigShop(int $extra = 4999): array
    {
        [$shop, $owner, $c] = $this->noticeShop('9390024105', 'Big Co');
        $base = $this->loanFor($shop, $owner, $c, ['status' => 'pending_evidence']);
        $cols = collect(DB::select("select column_name from information_schema.columns where table_schema = current_schema() and table_name = 'dhiran_loans' and column_name <> 'id' order by ordinal_position"))->pluck('column_name');
        $into = $cols->map(fn ($x) => '"' . $x . '"')->implode(',');
        $from = $cols->map(fn ($x) => $x === 'loan_number' ? '"loan_number" || \'-\' || g' : '"' . $x . '"')->implode(',');
        DB::statement("insert into dhiran_loans ($into) select $from from dhiran_loans cross join generate_series(1, $extra) g where id = ?", [$base->id]);
        Notices::forget();

        return [$shop, $owner];
    }

    public function test_5000_loans_are_counted_by_one_query_on_the_loans_table(): void
    {
        [$shop] = $this->bigShop();
        DB::flushQueryLog(); DB::enableQueryLog(); Notices::forget();
        $rows = Notices::forShop($shop->id); Notices::forShop($shop->id); Notices::forShop($shop->id);   // the bell, the card and the badge ask three times
        $log = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame(5000, collect($rows)->pluck('count', 'key')['pending']);
        $this->assertSame(1, collect($log)->filter(fn ($q) => str_contains($q['query'], 'dhiran_loans'))->count(), 'one aggregate query, never one per loan');
    }

    /** Notices::forShop = settings row + loans aggregate + Shop::find + currentSubscription (plan eager load = 2 queries): about 5, over the brief's 3. */
    public function test_BUG_notices_for_a_big_shop_run_at_most_three_queries(): void
    {
        [$shop] = $this->bigShop();
        DB::flushQueryLog(); DB::enableQueryLog(); Notices::forget();
        Notices::forShop($shop->id);
        $log = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertLessThanOrEqual(3, count($log), implode("\n", array_column($log, 'query')));
    }

    public function test_one_shops_notices_never_count_another_shops_loans(): void
    {
        [$a, $ownerA, $cA] = $this->noticeShop('9390024106', 'Shop A'); [$b, $ownerB, $cB] = $this->noticeShop('9390024107', 'Shop B');
        $la = $this->loanFor($a, $ownerA, $cA, ['status' => 'pending_evidence']);
        $this->loanFor($b, $ownerB, $cB, ['status' => 'pending_evidence']); $this->loanFor($b, $ownerB, $cB, ['status' => 'pending_evidence']);
        $this->loanFor($b, $ownerB, $cB, ['status' => 'closed']);
        DhiranSettings::getForShop($b->id)->update(['notices_off' => ['forfeit_notice'], 'notices_interest_days' => [1, 2, 3]]);
        Notices::forget();
        $this->assertSame(['pending' => 1], collect(Notices::forShop($a->id))->pluck('count', 'key')->all());
        $this->assertSame(['pending' => 2], collect(Notices::forShop($b->id))->pluck('count', 'key')->all());
        $this->assertSame(route('dhiran.show', $la->id), Notices::forShop($a->id)[0]['url'], 'a single notice links to the shop\'s own loan');
        $this->own($ownerA)->get('/dhiran/loans')->assertOk()->assertSee('1 loan waiting for evidence')->assertDontSee('2 loans waiting for evidence');
        $this->own($ownerB)->get('/dhiran/loans')->assertOk()->assertSee('2 loans waiting for evidence');
        $this->assertSame([3, 7, 30], Notices::options($a->id)['days']);
    }

    /* ───────────────────────────── ERROR PAGES ───────────────────────────── */

    private const LEAKS = ['Whoops', 'Stack trace', 'HttpException', 'RuntimeException', 'PDOException', 'QueryException', 'Illuminate\\', 'Symfony\\', 'vendor/', '.php', 'SQLSTATE', 'hunter2secret', '/var/www', 'Foo.php'];
    private const SECRET = 'SQLSTATE[08006] password=hunter2secret at /var/www/app/Foo.php';

    private function errorRoutes(): void
    {
        foreach ([401, 403, 404, 419, 429, 500, 503] as $code) { Route::get("/_adv/$code", fn () => abort($code, self::SECRET)); }
        Route::get('/_adv/throw', fn () => throw new \PDOException(self::SECRET));
        Route::get('/_adv/query', fn () => throw new QueryException('pgsql', 'select 1', [], new \PDOException(self::SECRET)));
        Route::get('/_adv/denied', fn () => throw new \Illuminate\Auth\Access\AuthorizationException(self::SECRET));
    }

    public function test_every_error_page_is_friendly_and_leaks_nothing(): void
    {
        $this->errorRoutes();
        $pages = ['/_adv/401' => 401, '/_adv/403' => 403, '/_adv/404' => 404, '/_adv/419' => 419, '/_adv/429' => 429, '/_adv/500' => 500, '/_adv/503' => 503, '/_adv/throw' => 500, '/_adv/query' => 500, '/_adv/denied' => 403, '/no/such/page' => 404];
        foreach ($pages as $url => $code) {
            $r = $this->get($url)->assertStatus($code);
            $html = $r->getContent();
            $this->assertStringContainsString('<!doctype html>', strtolower($html), $url);
            foreach (array_merge(self::LEAKS, [base_path()]) as $leak) { $this->assertStringNotContainsString($leak, $html, "$url leaks [$leak]"); }
        }
    }

    public function test_json_clients_get_json_never_html_or_a_trace(): void
    {
        $this->errorRoutes();
        foreach (['/_adv/401' => 401, '/_adv/403' => 403, '/_adv/404' => 404, '/_adv/419' => 419, '/_adv/429' => 429, '/_adv/500' => 500, '/_adv/503' => 503, '/_adv/throw' => 500, '/_adv/query' => 500, '/_adv/denied' => 403, '/no/such/page' => 404] as $url => $code) {
            $r = $this->getJson($url)->assertStatus($code);
            $this->assertStringContainsString('json', (string) $r->headers->get('Content-Type'), $url);
            $this->assertStringNotContainsString('<html', strtolower($r->getContent()), $url);
            foreach (['exception', 'trace', 'file', 'line'] as $key) { $r->assertJsonMissingPath($key); }
            $this->assertStringNotContainsString(base_path(), $r->getContent());
        }
        foreach (['/_adv/throw', '/_adv/query'] as $url) { $this->getJson($url)->assertStatus(500)->assertJson(['message' => 'Server Error']); }
    }

    /** Laravel returns the abort() message for every HttpException in JSON, so the 403 allowlist and the generic HTML texts do not apply to API clients. */
    public function test_BUG_json_error_messages_do_not_echo_abort_text(): void
    {
        $this->errorRoutes();
        foreach (['/_adv/403', '/_adv/404', '/_adv/500', '/_adv/503', '/_adv/denied'] as $url) {
            $body = $this->getJson($url)->getContent();
            $this->assertStringNotContainsString('hunter2secret', $body, $url); $this->assertStringNotContainsString('SQLSTATE', $body, $url);
        }
    }

    /* ───────────────────────────── IMAGES ───────────────────────────── */

    private User $imgOwner; private Customer $imgCustomer; private Shop $imgShop;

    private function imgSetup(): void
    {
        [$this->imgShop, $this->imgOwner, $this->imgCustomer] = $this->noticeShop('9390024120', 'Img Co');
    }

    private function upload(UploadedFile $f, array $over = [])
    {
        Auth::shouldUse('web');

        return $this->actingAs($this->imgOwner->fresh())->post(route('dhiran.attachments.store'), $over + ['owner_type' => 'customer', 'owner_id' => $this->imgCustomer->id, 'document_type' => 'id_proof_front', 'file' => $f]);
    }

    private function stored(): array
    {
        $a = DhiranAttachment::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();

        return [$a, Storage::disk('local')->get($a->file_path)];
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, DhiranAttachment::withoutGlobalScopes()->count()); $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function jpegBytes(int $w = 64, int $h = 48): string
    {
        mt_srand(3); $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 20; $i++) { imagefilledrectangle($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255))); }
        ob_start(); imagejpeg($im, null, 90); $b = (string) ob_get_clean(); imagedestroy($im);

        return $b;
    }

    private function pngBytes(bool $alpha = false): string
    {
        $im = imagecreatetruecolor(32, 32);
        if ($alpha) { imagealphablending($im, false); imagesavealpha($im, true); imagefill($im, 0, 0, imagecolorallocatealpha($im, 10, 20, 30, 100)); }
        ob_start(); imagepng($im); $b = (string) ob_get_clean(); imagedestroy($im);

        return $b;
    }

    /** A real upload (mime sniffed from the bytes like a browser upload); UploadedFile::fake() would report the mime from the file NAME and hide every disguise. */
    private function fakeFile(string $name, string $bytes): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'adv'); file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, $name, null, null, true);
    }

    public function test_a_one_pixel_png_is_stored(): void
    {
        $this->imgSetup(); $im = imagecreatetruecolor(1, 1); ob_start(); imagepng($im); $png = (string) ob_get_clean();
        $this->upload($this->fakeFile('dot.png', $png))->assertSessionHasNoErrors();
        [, $bytes] = $this->stored(); $this->assertSame([1, 1], array_slice(getimagesizefromstring($bytes), 0, 2));
    }

    public function test_images_that_decode_to_gigabytes_are_refused_by_their_header_alone(): void
    {
        $this->imgSetup();
        $jpeg = $this->jpegBytes(); $pos = strpos($jpeg, "\xFF\xC0\x00\x11\x08"); $this->assertNotFalse($pos);
        $big = substr_replace($jpeg, pack('nn', 5000, 5000), $pos + 5, 4);   // SOF0 now claims 5000x5000 = 25 MP (limit 20 MP); no bitmap is ever allocated by this test
        $this->assertSame([5000, 5000], array_slice(getimagesizefromstring($big), 0, 2));
        $this->upload($this->fakeFile('huge.jpg', $big))->assertSessionHasErrors('file');
        $png = $this->pngBytes(); $bomb = substr_replace($png, pack('NN', 60000, 60000), 16, 8);   // IHDR claims 3.6 gigapixels in a ~100 byte file
        $this->upload($this->fakeFile('bomb.png', $bomb))->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_exif_gps_is_stripped_from_a_stored_photo(): void
    {
        $this->imgSetup(); $jpeg = $this->jpegBytes();
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x01\x00\x00\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff . 'GPSLatitude=12.9716N GPSLongitude=77.5946E Make=SecretPhone';
        $tagged = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
        $this->assertStringContainsString('GPSLatitude', $tagged);
        $this->upload($this->fakeFile('gps.jpg', $tagged))->assertSessionHasNoErrors();
        [, $bytes] = $this->stored();
        foreach (["Exif\x00", 'GPS', 'SecretPhone', 'Make='] as $needle) { $this->assertStringNotContainsString($needle, $bytes, $needle); }
        $this->assertNotFalse(getimagesizefromstring($bytes));
    }

    public function test_the_stored_name_is_random_whatever_the_upload_was_called(): void
    {
        $this->imgSetup(); $jpeg = $this->jpegBytes();
        foreach (['evil.php.jpg', '../../x.jpg', '..\\..\\x.jpg', 'a"b%c;d.jpg', "tab\tname.jpg"] as $name) {
            $this->upload($this->fakeFile($name, $jpeg))->assertSessionHasNoErrors();
            [$a] = $this->stored();
            $this->assertMatchesRegularExpression('#^dhiran/' . $this->imgShop->id . '/[A-Za-z0-9]{2}/[A-Za-z0-9]{40}\.jpg$#', $a->file_path, $name);
            $this->assertStringNotContainsString('..', $a->file_path); $this->assertStringNotContainsString('/', $a->original_name); $this->assertStringNotContainsString('\\', $a->original_name);
        }
        foreach (['evil.jpg.php', 'evil.phtml', 'evil.PHP', 'evil.php.'] as $name) { $this->upload($this->fakeFile($name, $jpeg))->assertSessionHasErrors('file'); }
        $this->assertSame(5, DhiranAttachment::withoutGlobalScopes()->count());
    }

    public function test_code_hidden_in_a_png_never_reaches_the_stored_file(): void
    {
        $this->imgSetup();
        foreach ([false, true] as $alpha) {
            $png = $this->pngBytes($alpha); $data = "Comment\0<?php system('id'); ?>";
            $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data)); $iend = strlen($png) - 12;
            $evil = substr($png, 0, $iend) . $chunk . substr($png, $iend) . "<?php echo 'pwn'; ?>";
            $this->assertStringContainsString('<?php', $evil);
            $this->upload($this->fakeFile('pic.png', $evil))->assertSessionHasNoErrors();
            [, $bytes] = $this->stored();
            foreach (['<?php', 'system(', 'tEXt', 'pwn'] as $needle) { $this->assertStringNotContainsString($needle, $bytes, ($alpha ? 'alpha ' : 'opaque ') . $needle); }
            $this->assertNotFalse(getimagesizefromstring($bytes));
        }
    }

    public function test_disguised_and_broken_files_are_refused_and_nothing_is_stored(): void
    {
        $this->imgSetup();
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(document.cookie)</script></svg>';
        ob_start(); imagegif(imagecreatetruecolor(4, 4)); $gif = (string) ob_get_clean();
        $anim = substr($gif, 0, 13) . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00" . substr($gif, 13);
        $files = [
            'svg as png' => $this->fakeFile('logo.png', $svg), 'svg as jpg' => $this->fakeFile('logo.jpg', $svg), 'animated gif as jpg' => $this->fakeFile('anim.jpg', $anim), 'gif as png' => $this->fakeFile('a.png', $gif),
            'php source as jpg' => $this->fakeFile('shell.jpg', "<?php system(\$_GET['c']); ?>"), 'html as pdf' => $this->fakeFile('x.pdf', '<html><script>alert(1)</script></html>'), 'png as jpg' => $this->fakeFile('x.jpg', $this->pngBytes()),
            'zero bytes jpg' => $this->fakeFile('empty.jpg', ''), 'zero bytes pdf' => $this->fakeFile('empty.pdf', ''), 'header then junk' => $this->fakeFile('j.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\x13", 300)),
            '9 MB pdf' => $this->fakeFile('big.pdf', "%PDF-1.4\n" . str_repeat(' ', 9 * 1024 * 1024)), 'no extension' => $this->fakeFile('photo', $this->jpegBytes()),
        ];
        foreach ($files as $label => $f) { $this->upload($f)->assertSessionHasErrors('file', null, 'default'); }
        $this->assertNothingStored();
        $this->upload($this->fakeFile('ok.jpg', $this->jpegBytes()), ['file' => null])->assertSessionHasErrors('file');
        $this->upload($this->fakeFile('ok.jpg', $this->jpegBytes()), ['file' => ['x']])->assertSessionHasErrors('file');
    }

    public function test_two_uploads_in_the_same_second_get_distinct_private_paths(): void
    {
        $this->imgSetup(); $this->pinToSecond(); $jpeg = $this->jpegBytes();
        $this->upload($this->fakeFile('same.jpg', $jpeg))->assertSessionHasNoErrors(); $this->upload($this->fakeFile('same.jpg', $jpeg))->assertSessionHasNoErrors();
        $rows = DhiranAttachment::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $rows); $this->assertNotSame($rows[0]->file_path, $rows[1]->file_path);
        foreach ($rows as $r) { Storage::disk('local')->assertExists($r->file_path); $this->assertSame('local', $r->file_disk); }
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_an_upload_for_another_shops_customer_is_a_404_and_stores_nothing(): void
    {
        $this->imgSetup(); [, , $other] = $this->noticeShop('9390024121', 'Other Img Co');
        $this->upload($this->fakeFile('x.jpg', $this->jpegBytes()), ['owner_id' => $other->id])->assertNotFound();
        $this->upload($this->fakeFile('x.jpg', $this->jpegBytes()), ['owner_id' => 0])->assertNotFound();
        $this->upload($this->fakeFile('x.jpg', $this->jpegBytes()), ['owner_type' => 'shops'])->assertSessionHasErrors('owner_type');
        $this->upload($this->fakeFile('x.jpg', $this->jpegBytes()), ['owner_id' => '1 OR 1=1'])->assertSessionHasErrors('owner_id');
        $this->assertNothingStored();
    }

    public function test_a_pdf_with_javascript_is_stored_untouched_and_served_with_safe_headers(): void
    {
        $this->imgSetup();
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >> >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $this->upload($this->fakeFile('we"ird%name;.pdf', $pdf))->assertSessionHasNoErrors();
        [$a, $bytes] = $this->stored(); $this->assertSame($pdf, $bytes); $this->assertSame('application/pdf', $a->mime_type);
        $r = $this->own($this->imgOwner)->get(route('dhiran.attachments.show', $a->id))->assertOk();
        $r->assertHeader('X-Content-Type-Options', 'nosniff'); $r->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringStartsWith('application/pdf', (string) $r->headers->get('Content-Type'));
        $disposition = (string) $r->headers->get('Content-Disposition'); $this->assertStringContainsString('filename', $disposition); $this->assertStringNotContainsString($a->file_path, $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
    }

    public function test_an_image_is_served_sandboxed_and_other_shops_cannot_fetch_it(): void
    {
        $this->imgSetup(); $this->upload($this->fakeFile('p.jpg', $this->jpegBytes()))->assertSessionHasNoErrors(); [$a] = $this->stored();
        $r = $this->own($this->imgOwner)->get(route('dhiran.attachments.show', $a->id))->assertOk();
        $r->assertHeader('X-Content-Type-Options', 'nosniff'); $r->assertHeader('Content-Security-Policy', 'sandbox'); $this->assertStringStartsWith('image/jpeg', (string) $r->headers->get('Content-Type'));
        [, $stranger] = $this->noticeShop('9390024122', 'Stranger Co');
        $this->own($stranger)->get(route('dhiran.attachments.show', $a->id))->assertNotFound();
        Auth::guard('web')->logout(); $this->flushSession();
        $this->get(route('dhiran.attachments.show', $a->id))->assertRedirect(route('login'));
    }

    private function pinToSecond(): void { \Illuminate\Support\Carbon::setTestNow(now()->startOfSecond()); }
}
