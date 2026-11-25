<?php
namespace Tests\Feature\Adversarial;

use App\Mail\ShopDecisionMail;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\{Plan, ShopSubscription};
use App\Models\{Shop, User};
use App\Services\Dhiran\Notices;
use App\Support\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\{Auth, Mail};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\OnboardingFixtures;

/**
 * Implicit state machines of the SHOP (pending/rejected/active/suspended) and USER lifecycle and their cross product.
 * Tests named test_BUG_* assert the SAFE behaviour and are expected to FAIL until the app is fixed.
 */
class StateMachineTest extends AdminTestCase
{
    use OnboardingFixtures;

    private int $seq = 0;
    private const LANDING = ['/', '/dhiran', '/pending', '/access-ended', '/account-blocked', '/onboarding/business', '/verify-email', '/onboarding/plans', '/subscription/status'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake(); (new PlanSeeder)->run(); Notices::forget();
    }

    // ───────────── fixtures ─────────────

    /** term: valid|expired|cancelled|null_ends|queued_only|renewal|gap_renewal|grace_legacy|none */
    private function shop(string $status = 'active', string $term = 'valid', array $attrs = []): Shop
    {
        $s = Shop::create($attrs + ['name' => 'Zed Shop ' . (++$this->seq), 'phone' => '9876543210', 'address' => 'Main Road', 'status' => $status]);
        TenantContext::runFor($s->id, fn () => DhiranSettings::getForShop($s->id)->update(['is_enabled' => true]));
        $plan = Plan::where('code', 'dhiran_yearly')->firstOrFail();
        $rows = match ($term) {
            'valid' => [[today()->subDays(10), today()->addDays(355), 'active', null]],
            'expired' => [[today()->subYear(), today()->subDays(30), 'active', null]],
            'cancelled' => [[today()->subDays(10), today()->addYear(), 'cancelled', null]],
            'null_ends' => [[today()->subDays(10), null, 'active', null]],
            'queued_only' => [[today()->addDay(), today()->addYear(), 'active', null]],
            'renewal' => [[today()->subDays(355), today()->addDays(10), 'active', null], [today()->addDays(11), today()->addDays(376), 'active', null]],
            'gap_renewal' => [[today()->subYear(), today()->subDays(30), 'expired', null], [today()->addDays(40), today()->addDays(405), 'active', null]],
            'grace_legacy' => [[today()->subYear(), today()->subDays(5), 'active', today()->addDays(9)]],
            default => [],
        };
        foreach ($rows as [$from, $to, $st, $grace]) {
            ShopSubscription::create(['shop_id' => $s->id, 'plan_id' => $plan->id, 'billing_cycle' => 'yearly', 'status' => $st, 'starts_at' => $from, 'ends_at' => $to, 'grace_ends_at' => $grace ?? $to, 'actor_type' => 'admin']);
        }
        return $s;
    }

    private function person(?Shop $s, string $role = 'owner', array $o = []): User
    {
        $n = ++$this->seq;
        $a = $o + ['name' => "P$n", 'email' => "p$n@state.test", 'mobile_number' => '9100' . str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'password' => 'a-long-test-password', 'email_verified_at' => now(), 'is_active' => true, 'terms_accepted_at' => now()];
        return $s ? self::userWithRole($s, $a, $role) : User::create($a);
    }

    private function loginAdmin(): void { $this->signIn($this->admin()); }
    private function adm(): static { Auth::shouldUse('platform_admin'); return $this; }
    private function probe(User $u, string $url = '/dhiran/loans'): \Illuminate\Testing\TestResponse { Auth::shouldUse('web'); return $this->actingAs($u->fresh())->get($url); }
    private function liveRows(Shop $s): int { return ShopSubscription::where('shop_id', $s->id)->where('status', '!=', 'cancelled')->count(); }
    private function grant(Shop $s, array $over = []) { return $this->adm()->post("/admin/shops/{$s->id}/subscription", $over + ['plan' => 'dhiran_yearly', 'billing_cycle' => 'yearly', 'ends_at' => today()->addDays(30)->toDateString(), 'reason' => 'test']); }

    /** Follow redirects as $u with a hop limit; returns [final status or -1 on a loop, hops]. */
    private function walk(User $u, string $url, int $max = 5): array
    {
        $hops = [];
        for ($i = 0; $i <= $max; $i++) {
            $r = $this->probe($u, $url);
            if (!$r->isRedirect()) { return [$r->getStatusCode(), $hops]; }
            $url = $r->headers->get('Location'); $hops[] = $url;
        }
        return [-1, $hops];
    }

    // ───────────── (1) status flips by an admin ─────────────

    #[DataProvider('unapproved')]
    public function test_BUG_activating_an_unapproved_shop_skips_the_approval(string $from): void
    {
        $this->loginAdmin(); $s = $this->shop($from, 'none');
        $this->adm()->patch("/admin/shops/{$s->id}", ['status' => 'active', 'reason' => 'x']);
        $this->assertSame($from, $s->fresh()->status, 'update() has no from-state guard: pending/rejected jumps straight to active');
    }

    #[DataProvider('unapproved')]
    public function test_BUG_suspending_an_unapproved_shop_drops_it_out_of_the_queue_for_good(string $from): void
    {
        $this->loginAdmin(); $s = $this->shop($from, 'none'); $o = $this->person($s);
        $this->adm()->patch("/admin/shops/{$s->id}", ['status' => 'suspended', 'reason' => 'x']);
        $this->assertSame($from, $s->fresh()->status, 'a suspended request is neither approvable nor rejectable nor resubmittable');
    }

    public function test_BUG_a_suspend_only_admin_cannot_open_an_unapproved_shop(): void
    {
        $this->limited(['can_suspend_shop']); $s = $this->shop('pending', 'none');
        $this->adm()->patch("/admin/shops/{$s->id}", ['status' => 'active', 'reason' => 'x']);
        $this->assertSame('pending', $s->fresh()->status, 'approval needs shops+subscriptions permission, activation only can_suspend_shop');
    }

    public function test_BUG_pending_to_active_to_extend_gives_a_year_without_any_approval(): void
    {
        $this->loginAdmin(); $s = $this->shop('pending', 'none'); $o = $this->person($s);
        $this->adm()->patch("/admin/shops/{$s->id}", ['status' => 'active', 'reason' => 'x']);
        $this->adm()->post("/admin/shops/{$s->id}/extend");
        $s->refresh();
        $this->assertFalse($s->status === 'active' && $s->hasEdition('dhiran') && $s->reviewed_at === null, 'shop is open for a year, never reviewed, owner never emailed');
    }

    public function test_a_shop_forced_active_without_a_subscription_stays_behind_the_term_gate(): void
    {
        $this->loginAdmin(); $s = $this->shop('pending', 'none'); $o = $this->person($s);
        $this->adm()->patch("/admin/shops/{$s->id}", ['status' => 'active', 'reason' => 'x']);
        $this->assertNotSame(200, $this->probe($o)->getStatusCode());
        $this->assertSame(0, ShopSubscription::where('shop_id', $s->id)->count());
    }

    #[DataProvider('badStatuses')]
    public function test_update_only_takes_active_or_suspended(string $status): void
    {
        $this->loginAdmin(); $s = $this->shop('active');
        $this->adm()->patch("/admin/shops/{$s->id}", ['status' => $status, 'reason' => 'x'])->assertSessionHasErrors('status');
        $this->assertSame('active', $s->fresh()->status);
    }

    public function test_suspend_then_activate_blocks_everybody_and_restores_the_term(): void
    {
        $this->loginAdmin(); $s = $this->shop('active'); $o = $this->person($s); $st = $this->person($s, 'staff');
        $this->adm()->patch("/admin/shops/{$s->id}", ['status' => 'suspended', 'reason' => 'x']);
        foreach ([$o, $st] as $u) { $this->probe($u)->assertRedirect(route('dhiran.blocked')); $this->probe($u, '/account-blocked')->assertOk(); }
        $this->adm()->patch("/admin/shops/{$s->id}", ['status' => 'active', 'reason' => 'x']);
        $this->probe($o)->assertOk(); $this->probe($o, '/account-blocked')->assertRedirect('/');
    }

    // ───────────── (2) decisions out of order ─────────────

    #[DataProvider('wrongStateDecisions')]
    public function test_a_decision_from_the_wrong_state_is_refused(string $action, string $from): void
    {
        $this->loginAdmin(); $s = $this->shop($from, 'none'); $this->person($s);
        $this->adm()->post("/admin/requests/{$s->id}/$action", ['reason' => 'late'])->assertSessionHas('error');
        $this->assertSame($from, $s->fresh()->status);
        $this->assertSame(0, ShopSubscription::where('shop_id', $s->id)->count());
        Mail::assertNothingSent();
    }

    public function test_approving_twice_makes_one_term_one_email(): void
    {
        $this->loginAdmin(); $s = $this->shop('pending', 'none'); $this->person($s);
        $this->adm()->post("/admin/requests/{$s->id}/approve")->assertSessionHasNoErrors();
        $this->adm()->post("/admin/requests/{$s->id}/approve")->assertSessionHas('error');
        $this->assertSame(1, ShopSubscription::where('shop_id', $s->id)->count());
        Mail::assertSent(ShopDecisionMail::class, 1);
    }

    public function test_rejecting_twice_or_approving_after_reject_is_refused(): void
    {
        $this->loginAdmin(); $s = $this->shop('pending', 'none'); $this->person($s);
        $this->adm()->post("/admin/requests/{$s->id}/reject", ['reason' => 'a'])->assertSessionHasNoErrors();
        $this->adm()->post("/admin/requests/{$s->id}/reject", ['reason' => 'b'])->assertSessionHas('error');
        $this->adm()->post("/admin/requests/{$s->id}/approve")->assertSessionHas('error');
        $this->assertSame(['rejected', 'a'], [$s->fresh()->status, $s->fresh()->review_note]);
    }

    public function test_BUG_approving_after_an_admin_grant_on_the_pending_shop_leaves_one_live_row(): void
    {
        $this->loginAdmin(); $s = $this->shop('pending', 'none'); $this->person($s);
        $this->grant($s)->assertSessionHasNoErrors();
        $this->adm()->post("/admin/requests/{$s->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(1, $this->liveRows($s), 'approve() always inserts a row: two live terms for one shop (billing says one live subscription per shop)');
    }

    #[DataProvider('unapprovedOrSuspended')]
    public function test_a_grant_on_a_shop_that_is_not_active_opens_nothing(string $status, string $landing): void
    {
        $this->loginAdmin(); $s = $this->shop($status, 'none'); $o = $this->person($s);
        $this->grant($s)->assertSessionHasNoErrors();
        $this->probe($o)->assertRedirect(route($landing));
    }

    #[DataProvider('unapprovedOrSuspended')]
    public function test_extend_needs_an_active_shop(string $status): void
    {
        $this->loginAdmin(); $s = $this->shop($status, 'none');
        $this->adm()->post("/admin/shops/{$s->id}/extend")->assertSessionHas('error');
        $this->assertSame(0, ShopSubscription::where('shop_id', $s->id)->count());
    }

    public function test_extending_twice_stacks_two_years_on_the_end(): void
    {
        $this->loginAdmin(); $s = $this->shop('active'); $o = $this->person($s);
        $end = $s->currentSubscription()->ends_at->copy();
        $this->adm()->post("/admin/shops/{$s->id}/extend"); $this->adm()->post("/admin/shops/{$s->id}/extend");
        $this->assertSame($end->copy()->addYear()->addYear()->toDateString(), $s->fresh()->currentSubscription()->ends_at->toDateString());
        $this->assertSame(1, $this->liveRows($s));
    }

    public function test_terminate_blocks_all_and_extend_afterwards_makes_a_fresh_valid_term(): void
    {
        $this->loginAdmin(); $s = $this->shop('active'); $o = $this->person($s); $st = $this->person($s, 'staff');
        $this->adm()->post("/admin/shops/{$s->id}/subscription/terminate", ['reason' => 'x'])->assertSessionHasNoErrors();
        foreach ([$o, $st] as $u) { $this->probe($u)->assertRedirect(route('dhiran.expired')); }
        $this->probe($o, '/access-ended')->assertOk()->assertDontSee('ended on');
        $this->adm()->post("/admin/shops/{$s->id}/subscription/terminate", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->adm()->post("/admin/shops/{$s->id}/extend")->assertSessionHasNoErrors();
        $this->assertSame(today()->addYear()->toDateString(), $s->fresh()->currentSubscription()->ends_at->toDateString());
        $this->assertSame([1, 1], [$this->liveRows($s), ShopSubscription::where('shop_id', $s->id)->where('status', 'cancelled')->count()]);
        $this->probe($o)->assertOk();
    }

    public function test_a_grant_can_extend_or_keep_but_never_shorten_and_a_queued_renewal_blocks_it(): void
    {
        $this->loginAdmin(); $s = $this->shop('active'); $end = $s->currentSubscription()->ends_at->toDateString();
        foreach ([today()->addDays(30)->toDateString(), today()->subDay()->toDateString()] as $d) {
            $this->grant($s, ['ends_at' => $d])->assertSessionHasErrors('ends_at');
        }
        $this->assertSame($end, $s->fresh()->currentSubscription()->ends_at->toDateString());
        $this->grant($s, ['ends_at' => $end])->assertSessionHasNoErrors();
        $r = $this->shop('active', 'renewal');
        $this->grant($r, ['ends_at' => today()->addYears(3)->toDateString()])->assertSessionHasErrors('ends_at');
    }

    public function test_BUG_billing_grant_adds_plan_grace_days_beyond_the_chosen_end_date(): void
    {
        $this->loginAdmin(); $s = $this->shop('active');
        $this->grant($s, ['ends_at' => today()->addYear()->addDays(5)->toDateString()])->assertSessionHasNoErrors();
        $sub = $s->fresh()->currentSubscription();
        $this->assertSame($sub->ends_at->toDateString(), $sub->grace_ends_at->toDateString(), 'approve/extend set grace=end ("the year is the year"); billing grant adds 14 grace days the owner never sees');
    }

    public function test_BUG_extending_a_shop_whose_only_row_starts_tomorrow_must_give_access_today(): void
    {
        $this->loginAdmin(); $s = $this->shop('active', 'queued_only'); $o = $this->person($s);
        $this->probe($o)->assertRedirect(route('dhiran.expired'));
        $this->adm()->post("/admin/shops/{$s->id}/extend")->assertSessionHas('success');
        $this->assertTrue($s->fresh()->hasEdition('dhiran'), 'extend reports success but starts_at stays in the future: still blocked');
    }

    #[DataProvider('permissionGaps')]
    public function test_a_partial_admin_is_refused_and_nothing_changes(string $method, string $url, array $body, string $status, array $held): void
    {
        $this->limited($held); $s = $this->shop($status, $status === 'pending' ? 'none' : 'valid');
        $body = array_map(fn ($v) => $v === '{END}' ? today()->addYear()->toDateString() : $v, $body);
        $rows = ShopSubscription::where('shop_id', $s->id)->get()->map->only('status', 'ends_at')->all();
        $this->adm()->{$method}(str_replace('{id}', (string) $s->id, $url), $body)->assertForbidden();
        $this->assertSame($status, $s->fresh()->status);
        $this->assertEquals($rows, ShopSubscription::where('shop_id', $s->id)->get()->map->only('status', 'ends_at')->all());
    }

    // ───────────── (3)(4) cross product, redirects ─────────────

    public function test_suspended_and_expired_shows_the_blocked_page_in_one_hop_then_the_ended_page_after_activation(): void
    {
        $this->loginAdmin(); $s = $this->shop('suspended', 'expired'); $o = $this->person($s);
        [$code, $hops] = $this->walk($o, '/dhiran');
        $this->assertSame(200, $code); $this->assertLessThanOrEqual(2, count($hops)); $this->assertStringEndsWith('/account-blocked', end($hops));
        $this->adm()->patch("/admin/shops/{$s->id}", ['status' => 'active', 'reason' => 'x']);
        [$code, $hops] = $this->walk($o, '/dhiran');
        $this->assertSame(200, $code); $this->assertStringEndsWith('/access-ended', end($hops));
    }

    public function test_BUG_a_suspended_owner_is_not_shown_a_bare_403_on_the_other_status_pages(): void
    {
        $s = $this->shop('suspended', 'expired'); $o = $this->person($s);
        foreach (['/pending', '/access-ended'] as $url) { $this->probe($o, $url)->assertRedirect(route('dhiran.blocked')); }
    }

    #[DataProvider('userStates')]
    public function test_no_user_state_loops_or_500s_on_any_landing_page(string $state): void
    {
        $u = $this->stateUser($state);
        foreach (self::LANDING as $url) {
            [$code, $hops] = $this->walk($u, $url);
            $this->assertNotSame(-1, $code, "$state $url loops: " . implode(' > ', $hops));
            $this->assertLessThan(500, $code, "$state $url ended in $code after " . implode(' > ', $hops));
        }
    }

    #[DataProvider('userStates')]
    public function test_a_shop_that_is_not_active_with_a_live_term_never_opens_the_software(string $state): void
    {
        $u = $this->stateUser($state);
        $open = in_array($state, ['active_valid', 'staff_valid', 'unverified_with_shop', 'queued_renewal', 'grace_legacy'], true);
        $this->assertSame($open, $this->probe($u)->getStatusCode() === 200, $state);
    }

    public function test_a_deactivated_user_of_an_expired_shop_and_a_pending_shop_owner_land_on_the_blocked_page(): void
    {
        foreach ([$this->person($this->shop('active', 'expired'), 'owner', ['is_active' => false]), $this->person($this->shop('pending', 'none'), 'owner', ['is_active' => false])] as $u) {
            $this->probe($u)->assertRedirect(route('dhiran.blocked')); $this->probe($u, '/account-blocked')->assertOk()->assertSee('switched off');
        }
    }

    // ───────────── (5)(8) email and verification ─────────────

    public function test_a_verified_shop_owner_cannot_change_or_unverify_the_email_and_keeps_access(): void
    {
        $o = $this->person($this->shop('active')); $email = $o->email;
        foreach (['/verify-email/send', '/verify-email/resend'] as $url) {
            Auth::shouldUse('web'); $this->actingAs($o->fresh())->postJson($url, ['email' => 'new@state.test'])->assertStatus(422);
        }
        $this->assertSame($email, $o->fresh()->email); $this->assertNotNull($o->fresh()->email_verified_at);
        Mail::assertNothingSent(); $this->probe($o)->assertOk();
    }

    #[DataProvider('emailVariants')]
    public function test_a_shopless_user_cannot_take_another_users_email_in_any_case(string $variant): void
    {
        $this->person(null, 'owner', ['email' => 'taken@state.test']);
        $u = $this->person(null, 'owner', ['email_verified_at' => null]); $before = $u->email;
        Auth::shouldUse('web'); $this->actingAs($u)->postJson('/verify-email/send', ['email' => $variant])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame($before, $u->fresh()->email); Mail::assertNothingSent();
    }

    public function test_the_verified_gate_only_applies_to_users_without_a_shop(): void
    {
        $withShop = $this->person($this->shop('pending', 'none'), 'owner', ['email_verified_at' => null]);
        $this->probe($withShop, '/pending')->assertOk();
        $this->probe($withShop, '/onboarding/business')->assertRedirect(route('dhiran.pending'));
        $this->probe($this->person($this->shop('active'), 'owner', ['email_verified_at' => null]))->assertOk();
        $shopless = $this->person(null, 'owner', ['email_verified_at' => null]);
        foreach (['/pending', '/access-ended', '/onboarding/business', '/dhiran'] as $url) { $this->probe($shopless, $url)->assertRedirect(route('dhiran.verify-email')); }
        $this->probe($shopless, '/verify-email')->assertOk();
    }

    public function test_a_second_business_form_post_never_makes_a_second_shop(): void
    {
        $u = $this->person(null);
        $f = ['name' => 'One Co', 'phone' => '9876543210', 'address' => 'Main Road'];
        Auth::shouldUse('web'); $this->actingAs($u)->post(route('dhiran.onboarding.store'), $f)->assertRedirect(route('dhiran.pending'));
        $this->actingAs($u->fresh())->post(route('dhiran.onboarding.store'), ['name' => 'Two Co'] + $f)->assertRedirect(route('dhiran.pending'));
        $this->assertSame(['One Co'], Shop::pluck('name')->all());
    }

    // ───────────── (6)(7) resubmit ─────────────

    private function rejected(): array
    {
        $s = $this->shop('rejected', 'none', ['review_note' => 'Phone is wrong', 'reviewed_at' => now()]);
        return [$s, $this->person($s)];
    }

    private function fixed(array $o = []): array { return $o + ['name' => 'Fixed Co', 'phone' => '9876500099', 'address' => '14 Sarafa Bazaar']; }

    public function test_resubmit_ignores_posted_status_shop_role_and_counters(): void
    {
        [$s, $o] = $this->rejected(); $other = $this->shop('active'); $role = $o->role_id;
        Auth::shouldUse('web');
        $this->actingAs($o)->post(route('dhiran.resubmit'), $this->fixed(['status' => 'active', 'shop_id' => $other->id, 'role_id' => 1, 'role' => 'admin', 'submissions' => 99, 'is_active' => 0]))->assertRedirect(route('dhiran.pending'));
        $s->refresh();
        $this->assertSame(['pending', 2], [$s->status, $s->submissions]);
        $this->assertSame([$s->id, $role, true], [$o->fresh()->shop_id, $o->fresh()->role_id, $o->fresh()->is_active]);
        $this->assertSame('Main Road', $other->fresh()->address);
    }

    public function test_a_double_submit_is_refused_and_a_pending_shop_cannot_be_resubmitted(): void
    {
        [$s, $o] = $this->rejected(); Auth::shouldUse('web');
        $this->actingAs($o)->post(route('dhiran.resubmit'), $this->fixed())->assertSessionHas('success');
        $this->actingAs($o->fresh())->post(route('dhiran.resubmit'), $this->fixed(['name' => 'Second']))->assertSessionHas('error');
        $this->assertSame(['Fixed Co', 2], [$s->fresh()->name, $s->fresh()->submissions]);
    }

    public function test_staff_and_another_shops_owner_cannot_resubmit(): void
    {
        [$s, $o] = $this->rejected(); $staff = $this->person($s, 'staff'); $b = $this->person($this->shop('active'));
        Auth::shouldUse('web');
        $this->actingAs($staff)->post(route('dhiran.resubmit'), $this->fixed())->assertForbidden();
        $this->actingAs($b)->post(route('dhiran.resubmit'), $this->fixed(['shop_id' => $s->id]))->assertSessionHas('error');
        $this->assertSame(['rejected', 1], [$s->fresh()->status, $s->fresh()->submissions]);
    }

    public function test_BUG_a_deactivated_owner_cannot_resubmit_the_shop(): void
    {
        [$s, $o] = $this->rejected(); $o->update(['is_active' => false]);
        Auth::shouldUse('web'); $this->actingAs($o->fresh())->post(route('dhiran.resubmit'), $this->fixed());
        $this->assertSame('rejected', $s->fresh()->status, 'resubmit has only auth+verified: no is_active check, a switched-off owner re-enters the admin queue');
    }

    public function test_html_in_the_resubmitted_name_and_reasons_is_escaped_on_every_surface(): void
    {
        $this->loginAdmin(); [$s, $o] = $this->rejected(); $xss = '<script>alert(1)</script>';
        Auth::shouldUse('web'); $this->actingAs($o)->post(route('dhiran.resubmit'), $this->fixed(['name' => $xss]))->assertSessionHas('success');
        $this->probe($o, '/pending')->assertOk()->assertDontSee($xss, false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->adm()->get('/admin/requests')->assertOk()->assertDontSee($xss, false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->adm()->post("/admin/requests/{$s->id}/reject", ['reason' => '<img src=x onerror=alert(1)>'])->assertSessionHasNoErrors();
        $this->probe($o, '/pending')->assertOk()->assertDontSee('<img src=x', false);
        $this->adm()->get('/admin/requests')->assertDontSee('<img src=x', false);
        Mail::assertSent(ShopDecisionMail::class, function (ShopDecisionMail $m) use ($xss) {
            $html = $m->render();
            return !str_contains($html, $xss) && !str_contains($html, '<img src=x') && str_contains($html, '&lt;script&gt;');
        });
    }

    public function test_a_stale_rejected_tab_cannot_resubmit_after_the_shop_moved_on(): void
    {
        $this->loginAdmin(); [$s, $o] = $this->rejected(); Auth::shouldUse('web');
        $this->probe($o, '/pending')->assertOk()->assertSee('Fix and send again');           // browser 1 keeps this form
        $this->actingAs($o->fresh())->post(route('dhiran.resubmit'), $this->fixed());      // browser 2 sends it
        $this->adm()->post("/admin/requests/{$s->id}/approve")->assertSessionHasNoErrors();
        $this->probe($o, '/pending')->assertRedirect('/');
        Auth::shouldUse('web'); $this->actingAs($o->fresh())->post(route('dhiran.resubmit'), $this->fixed(['name' => 'Stale']))->assertSessionHas('error');
        $this->assertSame(['active', 'Fixed Co', 2], [$s->fresh()->status, $s->fresh()->name, $s->fresh()->submissions]);
        $this->probe($o)->assertOk();
    }

    // ───────────── (9) subscription rows vs access, ended page, bell, admin list ─────────────

    public function test_a_queued_renewal_keeps_access_and_a_gap_before_it_blocks(): void
    {
        $this->assertSame(200, $this->probe($this->person($this->shop('active', 'renewal')))->getStatusCode());
        $o = $this->person($this->shop('active', 'gap_renewal')); $this->probe($o)->assertRedirect(route('dhiran.expired'));
    }

    public function test_BUG_the_ended_page_never_says_ended_on_a_date_in_the_future(): void
    {
        $o = $this->person($this->shop('active', 'gap_renewal'));
        $this->probe($o, '/access-ended')->assertOk()->assertDontSee(today()->addDays(405)->format('d M Y'));   // ended() takes max(ends_at) over all non-cancelled rows, queued term included
    }

    #[DataProvider('oddTerms')]
    public function test_a_row_without_a_valid_end_blocks_and_is_listed_for_the_admin(string $term): void
    {
        $this->loginAdmin(); $s = $this->shop('active', $term); $o = $this->person($s);
        $this->probe($o)->assertRedirect(route('dhiran.expired')); $this->probe($o, '/access-ended')->assertOk();
        $this->adm()->get('/admin')->assertOk()->assertSee($s->name);
    }

    #[DataProvider('bellTerms')]
    public function test_BUG_the_bell_and_the_admin_ending_list_tell_the_same_story(string $term): void
    {
        $this->loginAdmin(); $s = $this->shop('active', $term); $this->person($s);
        $listed = str_contains($this->adm()->get('/admin')->getContent(), $s->name);
        Notices::forget(); $warns = collect(Notices::forShop($s->id))->contains('key', 'plan_ending');
        $this->assertSame($listed, $warns, "$term: admin list says " . ($listed ? 'ending' : 'fine') . ', bell says ' . ($warns ? 'ending' : 'fine'));
    }

    // ───────────── providers ─────────────

    public static function unapproved(): array { return ['pending' => ['pending'], 'rejected' => ['rejected']]; }
    public static function badStatuses(): array { return [['pending'], ['rejected'], ['deleted'], ['ACTIVE']]; }
    public static function emailVariants(): array { return [['taken@state.test'], ['TAKEN@STATE.TEST'], ['  Taken@State.Test ']]; }
    public static function oddTerms(): array { return [['null_ends'], ['cancelled'], ['none']]; }
    public static function bellTerms(): array { return [['renewal'], ['grace_legacy']]; }
    public static function wrongStateDecisions(): array
    {
        return ['approve rejected' => ['approve', 'rejected'], 'approve active' => ['approve', 'active'], 'approve suspended' => ['approve', 'suspended'],
            'reject rejected' => ['reject', 'rejected'], 'reject active' => ['reject', 'active'], 'reject suspended' => ['reject', 'suspended']];
    }
    public static function unapprovedOrSuspended(): array { return ['pending' => ['pending', 'dhiran.pending'], 'rejected' => ['rejected', 'dhiran.pending'], 'suspended' => ['suspended', 'dhiran.blocked']]; }
    public static function permissionGaps(): array
    {
        $grant = ['plan' => 'dhiran_yearly', 'billing_cycle' => 'yearly', 'ends_at' => '{END}', 'reason' => 'x'];
        return [
            'approve w/o shops' => ['post', '/admin/requests/{id}/approve', [], 'pending', ['can_manage_subscriptions', 'can_suspend_shop']],
            'approve w/o subs' => ['post', '/admin/requests/{id}/approve', [], 'pending', ['can_manage_shops', 'can_suspend_shop']],
            'reject w/o shops' => ['post', '/admin/requests/{id}/reject', ['reason' => 'x'], 'pending', ['can_manage_subscriptions', 'can_suspend_shop']],
            'extend' => ['post', '/admin/shops/{id}/extend', [], 'active', ['can_manage_shops', 'can_suspend_shop']],
            'grant' => ['post', '/admin/shops/{id}/subscription', $grant, 'active', ['can_manage_shops', 'can_suspend_shop']],
            'terminate' => ['post', '/admin/shops/{id}/subscription/terminate', ['reason' => 'x'], 'active', ['can_manage_shops', 'can_suspend_shop']],
            'suspend' => ['patch', '/admin/shops/{id}', ['status' => 'suspended', 'reason' => 'x'], 'active', ['can_manage_shops', 'can_manage_subscriptions']],
        ];
    }
    public static function userStates(): array
    {
        return array_combine($k = ['shopless_unverified', 'shopless_verified', 'deactivated_shopless', 'pending', 'rejected', 'active_valid', 'active_expired', 'suspended', 'suspended_expired', 'deactivated_active',
            'deactivated_pending', 'staff_valid', 'staff_expired', 'unverified_with_shop', 'active_no_sub', 'active_cancelled', 'queued_renewal', 'grace_legacy', 'null_ends', 'queued_only'], array_map(fn ($s) => [$s], $k));
    }

    private function stateUser(string $state): User
    {
        return match ($state) {
            'shopless_unverified' => $this->person(null, 'owner', ['email_verified_at' => null]),
            'shopless_verified' => $this->person(null),
            'deactivated_shopless' => $this->person(null, 'owner', ['is_active' => false]),
            'pending' => $this->person($this->shop('pending', 'none')),
            'rejected' => $this->person($this->shop('rejected', 'none', ['review_note' => 'no'])),
            'active_valid' => $this->person($this->shop('active')),
            'active_expired' => $this->person($this->shop('active', 'expired')),
            'suspended' => $this->person($this->shop('suspended')),
            'suspended_expired' => $this->person($this->shop('suspended', 'expired')),
            'deactivated_active' => $this->person($this->shop('active'), 'owner', ['is_active' => false]),
            'deactivated_pending' => $this->person($this->shop('pending', 'none'), 'owner', ['is_active' => false]),
            'staff_valid' => $this->person($this->shop('active'), 'staff'),
            'staff_expired' => $this->person($this->shop('active', 'expired'), 'staff'),
            'unverified_with_shop' => $this->person($this->shop('active'), 'owner', ['email_verified_at' => null]),
            'active_no_sub' => $this->person($this->shop('active', 'none')),
            'active_cancelled' => $this->person($this->shop('active', 'cancelled')),
            'queued_renewal' => $this->person($this->shop('active', 'renewal')),
            'grace_legacy' => $this->person($this->shop('active', 'grace_legacy')),
            'null_ends' => $this->person($this->shop('active', 'null_ends')),
            'queued_only' => $this->person($this->shop('active', 'queued_only')),
        };
    }
}
