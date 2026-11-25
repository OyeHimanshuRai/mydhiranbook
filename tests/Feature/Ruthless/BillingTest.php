<?php
namespace Tests\Feature\Ruthless;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\User;
use App\Services\DhiranService;
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\BillingFixtures;

/** Adversarial access-term suite (expiry, plan limits, admin grant/terminate). Every test states the business rule it enforces. */
class BillingTest extends AdminTestCase
{
    use BillingFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function freeze(string $ist): void { Carbon::setTestNow(Carbon::parse($ist, 'Asia/Kolkata')); }
    private function types(?int $subId = null): array { return SubscriptionEvent::when($subId, fn ($q) => $q->where('shop_subscription_id', $subId))->orderBy('id')->pluck('event_type')->all(); }

    private function term(\App\Models\Shop $shop, string $plan, string $start, string $end, string $grace, string $status = 'active'): ShopSubscription
    {
        $p = $this->plan($plan);
        return ShopSubscription::create(['shop_id' => $shop->id, 'plan_id' => $p->id, 'status' => $status, 'billing_cycle' => $p->price_yearly ? 'yearly' : 'monthly',
            'starts_at' => $start, 'ends_at' => $end, 'grace_ends_at' => $grace, 'price_paid' => $p->price_yearly ?? $p->price_monthly, 'actor_type' => 'system']);
    }

    // ───────────────────────── expiry command ─────────────────────────

    public function test_expiry_command_flips_exactly_at_the_ist_midnights(): void
    {
        // Rule: ends_at 10 Mar, grace 7d: still active through 23:59:59 on 10 Mar; grace from 00:00 11 Mar through 23:59:59 17 Mar; expired from 00:00 18 Mar.
        $shop = $this->shopUser()->shop; $s = $this->term($shop, 'dhiran_monthly', '2027-02-11', '2027-03-10', '2027-03-17');
        foreach ([['2027-03-10 23:59:59', 'active', 0], ['2027-03-11 00:00:00', 'grace', 1], ['2027-03-17 23:59:59', 'grace', 1], ['2027-03-18 00:00:00', 'expired', 2]] as [$now, $status, $events]) {
            $this->freeze($now);
            $this->artisan('subscription:check-expiry')->assertSuccessful();
            $this->artisan('subscription:check-expiry')->assertSuccessful(); // run twice: idempotent
            $this->assertSame($status, $s->fresh()->status, $now);
            $this->assertSame($events, SubscriptionEvent::count(), $now);
        }
        $this->assertSame(['subscription.grace', 'subscription.expired'], $this->types($s->id));
        $this->assertSame('2027-03-17', $s->fresh()->grace_ends_at->toDateString()); // stored grace never rewritten
    }

    public function test_expiry_command_on_a_quiet_day_changes_nothing_and_reports_zero(): void
    {
        $shop = $this->shopUser()->shop; $s = self::activeSubscription($shop);
        $before = $s->fresh()->toArray();
        $this->artisan('subscription:check-expiry')->expectsOutput('Done. Status changes: 0.')->assertSuccessful();
        $this->assertSame($before, $s->fresh()->toArray());
        $this->assertSame(0, SubscriptionEvent::count());
    }

    public function test_expiry_command_after_a_long_outage_walks_active_to_expired_in_one_run(): void
    {
        // Rule: if the scheduler was down past the grace window, one run records both transitions in order.
        $this->freeze('2027-06-01 03:00:00');
        $shop = $this->shopUser()->shop; $s = $this->term($shop, 'dhiran_yearly', '2026-03-11', '2027-03-10', '2027-03-24');
        $this->artisan('subscription:check-expiry')->expectsOutput('Done. Status changes: 2.')->assertSuccessful();
        $this->assertSame('expired', $s->fresh()->status);
        $this->assertSame(['subscription.grace', 'subscription.expired'], $this->types($s->id));
        $this->assertSame('2027-03-24', $s->fresh()->grace_ends_at->toDateString());
    }

    public function test_expiry_of_the_old_term_does_not_revoke_a_stacked_newer_term(): void
    {
        // Rule: the older term lapsing must leave the shop entitled through the early-renewed term.
        $this->freeze('2027-03-20 10:00:00');
        $owner = $this->shopUser();
        $old = $this->term($owner->shop, 'dhiran_yearly', '2026-03-11', '2027-03-10', '2027-03-24', 'grace');
        $new = $this->term($owner->shop, 'dhiran_yearly', '2027-03-11', '2028-03-10', '2028-03-24');
        $this->freeze('2027-03-25 00:00:00');
        $this->artisan('subscription:check-expiry')->assertSuccessful();
        $this->assertSame(['expired', 'active'], [$old->fresh()->status, $new->fresh()->status]);
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
        $this->assertSame($new->id, $owner->shop->fresh()->currentSubscription()->id);
    }

    public function test_expiry_command_never_resurrects_or_touches_cancelled_and_expired_rows(): void
    {
        $shop = $this->shopUser()->shop;
        $c = $this->term($shop, 'dhiran_yearly', '2026-01-01', '2026-12-31', '2027-01-14', 'cancelled');
        $e = $this->term($shop, 'dhiran_yearly', '2025-01-01', '2025-12-31', '2026-01-14', 'expired');
        $this->freeze('2027-06-01 00:00:00');
        $this->artisan('subscription:check-expiry')->assertSuccessful();
        $this->assertSame(['cancelled', 'expired'], [$c->fresh()->status, $e->fresh()->status]);
        $this->assertSame(0, SubscriptionEvent::count());
    }

    // ───────────────────────── plan limits ─────────────────────────

    private function staff(\App\Models\Shop $shop, int $n, bool $active = true): User
    {
        $roles = app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        return User::create(['name' => "Staff $n", 'email' => "st{$shop->id}x$n@billing.test", 'password' => 'a-long-test-password', 'shop_id' => $shop->id, 'role' => 'staff', 'role_id' => $roles['staff']->id, 'is_active' => $active]);
    }

    public function test_staff_cap_is_exactly_the_plan_number_owner_and_inactive_staff_do_not_count(): void
    {
        // Rule: monthly allows 3 staff: with 2 you can add, at exactly 3 you cannot; owner and deactivated staff are free.
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, 'dhiran_monthly', today()->subDays(5)->toDateString(), today()->addDays(25)->toDateString(), today()->addDays(32)->toDateString());
        $this->staff($shop, 1); $this->staff($shop, 2); $this->staff($shop, 99, false);
        $this->assertSame([3, 2, true], TenantContext::runFor($shop->id, fn () => [$shop->staffLimit(), $shop->currentStaffCount(), $shop->canAddStaff()]));
        $third = $this->staff($shop, 3);
        $this->assertSame([3, false], TenantContext::runFor($shop->id, fn () => [$shop->currentStaffCount(), $shop->canAddStaff()]));
        $third->update(['is_active' => false]);
        $this->assertTrue(TenantContext::runFor($shop->id, fn () => $shop->canAddStaff()));
    }

    public function test_downgrade_below_current_staff_blocks_new_staff_but_removes_nobody(): void
    {
        // Rule: lowering the plan never deletes staff (no automatic data deletion), it only blocks additions.
        $owner = $this->shopUser(); $shop = $owner->shop;
        $sub = $this->term($shop, 'dhiran_yearly', today()->subDay()->toDateString(), today()->addYear()->toDateString(), today()->addYear()->addDays(14)->toDateString());
        foreach (range(1, 5) as $i) { $this->staff($shop, $i); }
        $this->assertSame([7, true], TenantContext::runFor($shop->id, fn () => [$shop->staffLimit(), $shop->canAddStaff()]));
        $sub->update(['plan_id' => $this->plan('dhiran_monthly')->id]);
        $this->assertSame([3, 5, false], TenantContext::runFor($shop->id, fn () => [$shop->fresh()->staffLimit(), $shop->currentStaffCount(), $shop->fresh()->canAddStaff()]));
    }

    public function test_early_renewal_onto_a_smaller_plan_keeps_todays_limits_until_the_new_term_starts(): void
    {
        // Rule: limits come from the term that COVERS TODAY. A yearly shop (7 staff, 3000 items) that queues a monthly term
        // starting in 100 days must still have yearly limits today.
        $this->freeze('2027-01-10 10:00:00');
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, 'dhiran_yearly', '2026-04-21', '2027-04-20', '2027-05-04');
        $this->term($shop, 'dhiran_monthly', '2027-04-21', '2027-05-20', '2027-05-27');
        $this->assertSame(7, $shop->staffLimit());
        $this->assertSame(3000, $shop->itemLimit());
    }

    public function test_early_renewal_onto_a_bigger_plan_does_not_grant_its_limits_early(): void
    {
        // Rule: queuing a future yearly term must not hand the shop yearly limits (7 staff) while the monthly term (3) still runs.
        $this->freeze('2027-01-10 10:00:00');
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, 'dhiran_monthly', '2027-01-01', '2027-01-31', '2027-02-07');
        $this->term($shop, 'dhiran_yearly', '2027-02-01', '2028-01-31', '2028-02-14');
        $this->assertSame(3, $shop->staffLimit());
    }

    public function test_terminated_shop_cannot_add_staff_beyond_its_old_plan(): void
    {
        // Rule: once the only term is cancelled (refund/terminate) the shop is not entitled; "no plan" must not mean unlimited staff.
        $owner = $this->shopUser(); $shop = $owner->shop;
        $sub = $this->term($shop, 'dhiran_yearly', today()->subDay()->toDateString(), today()->addYear()->toDateString(), today()->addYear()->addDays(14)->toDateString());
        foreach (range(1, 7) as $i) { $this->staff($shop, $i); }
        $this->assertFalse(TenantContext::runFor($shop->id, fn () => $shop->canAddStaff()));
        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->assertFalse($shop->fresh()->hasEdition('dhiran'));
        $this->assertFalse(TenantContext::runFor($shop->id, fn () => $shop->fresh()->canAddStaff()));
    }

    private function loanShop(string $plan = 'dhiran_monthly', int $maxItems = 3): array
    {
        $owner = $this->shopUser(); $shop = $owner->shop;
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $this->term($shop, $plan, today()->subDay()->toDateString(), today()->addMonth()->toDateString(), today()->addMonth()->addDays(7)->toDateString());
        Plan::where('code', $plan)->update(['features' => array_merge($this->plan($plan)->features, ['max_items' => $maxItems])]);
        $c = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'Lim', 'last_name' => 'It', 'mobile' => '9815550099']));
        return [$owner, $c];
    }

    private function loan(User $owner, Customer $c, int $items)
    {
        $its = array_fill(0, $items, ['description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]);
        return TenantContext::runFor($owner->shop_id, fn () => app(DhiranService::class)->createLoan($owner->shop->fresh(), $c, $its, ['principal_amount' => 10000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id]));
    }

    public function test_item_cap_allows_exactly_n_items_and_refuses_n_plus_one_atomically(): void
    {
        // Rule: max_items 3: one loan of exactly 3 items is allowed; the very next item is refused and writes nothing.
        [$owner, $c] = $this->loanShop();
        $this->loan($owner, $c, 3);
        $this->assertSame(3, $owner->shop->fresh()->pledgedItemCount());
        try { $this->loan($owner, $c, 1); $this->fail('N+1 items must be refused'); }
        catch (\LogicException $e) { $this->assertStringContainsString('Item limit reached', $e->getMessage()); }
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count());
        $this->assertSame(3, $owner->shop->fresh()->pledgedItemCount());
    }

    public function test_item_cap_n_plus_one_in_a_single_first_loan_is_refused(): void
    {
        [$owner, $c] = $this->loanShop();
        try { $this->loan($owner, $c, 4); $this->fail('4 items over a cap of 3 must be refused'); }
        catch (\LogicException $e) { $this->assertStringContainsString('Item limit reached', $e->getMessage()); }
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count());
    }

    public function test_releasing_one_item_frees_exactly_one_slot(): void
    {
        // Rule: only items still pledged on unfinished loans count; a released item frees a slot.
        [$owner, $c] = $this->loanShop();
        $l = $this->loan($owner, $c, 3);
        \App\Models\Dhiran\DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $l->id)->limit(1)->update(['status' => 'released']);
        $this->assertSame(2, $owner->shop->fresh()->pledgedItemCount());
        $this->loan($owner, $c, 1); // 2 + 1 = 3 == cap: allowed
        $this->expectException(\LogicException::class);
        $this->loan($owner, $c, 1); // 4 > 3
    }

    // ───────────────────────── admin grant / terminate ─────────────────────────

    private function grant(int $shopId, string $endsAt, string $plan = 'dhiran_yearly', string $cycle = 'yearly')
    {
        return $this->post("/admin/shops/$shopId/subscription", ['plan' => $plan, 'billing_cycle' => $cycle, 'ends_at' => $endsAt, 'reason' => 'ruthless']);
    }

    private function d(int $days): string { return today()->addDays($days)->toDateString(); }

    public function test_admin_grant_one_day_shorter_than_a_paid_term_is_refused_but_equal_is_allowed(): void
    {
        // Rule: grant is extend-only: ends_at < live ends_at is refused with no writes; equal is a harmless no-shrink.
        $this->signIn($this->admin());
        $owner = $this->shopUser(); $s = $this->term($owner->shop, 'dhiran_yearly', $this->d(-300), $this->d(60), $this->d(74));
        $this->grant($owner->shop_id, $this->d(59))->assertSessionHasErrors('ends_at');
        $this->assertSame(0, SubscriptionEvent::count());
        $this->assertDatabaseMissing('platform_audit_logs', ['action' => 'subscription.updated']);
        $this->assertSame($this->d(60), $s->fresh()->ends_at->toDateString());
        $this->grant($owner->shop_id, $this->d(60))->assertSessionHasNoErrors();
        $this->assertSame($this->d(60), $s->fresh()->ends_at->toDateString());
    }

    public function test_admin_grant_in_the_past_is_refused_even_for_a_shop_with_no_terms(): void
    {
        $this->signIn($this->admin());
        $shop = \App\Models\Shop::create(['name' => 'Empty']);
        $this->grant($shop->id, $this->d(-1))->assertSessionHasErrors('ends_at');
        $this->assertSame(0, ShopSubscription::count());
        $this->grant($shop->id, $this->d(0))->assertSessionHasNoErrors();  // today is the floor
        $this->assertTrue($shop->fresh()->hasEdition('dhiran'));
    }

    public function test_admin_grant_on_a_grace_shop_extends_the_lapsing_row_in_place(): void
    {
        // Rule: grant to a shop in grace reactivates that same row (status active) and recomputes grace from the plan.
        $this->signIn($this->admin());
        $owner = $this->shopUser(); $s = $this->term($owner->shop, 'dhiran_monthly', $this->d(-32), $this->d(-2), $this->d(5), 'grace');
        $this->grant($owner->shop_id, $this->d(28), 'dhiran_monthly', 'monthly')->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertSame(['active', $this->d(28), $this->d(28)], [$s->status, $s->ends_at->toDateString(), $s->grace_ends_at->toDateString()]);
        $this->assertSame(1, ShopSubscription::count());
    }

    public function test_terminate_then_grant_creates_a_new_row_and_keeps_the_cancelled_history(): void
    {
        // Rule: terminate cancels every live row (all of them, even stacked); a later grant is a fresh row from today; history intact.
        $this->signIn($this->admin());
        $owner = $this->shopUser(); $shopId = $owner->shop_id;
        $a = $this->term($owner->shop, 'dhiran_yearly', $this->d(-300), $this->d(60), $this->d(74));
        $b = $this->term($owner->shop, 'dhiran_yearly', $this->d(61), $this->d(425), $this->d(439));
        $this->post("/admin/shops/$shopId/subscription/terminate", ['reason' => 'Chargeback'])->assertRedirect();
        $this->assertSame(['cancelled', 'cancelled'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertFalse($owner->shop->fresh()->hasEdition('dhiran'));
        $this->post("/admin/shops/$shopId/subscription/terminate", ['reason' => 'again'])->assertSessionHasErrors('reason'); // nothing live left
        $this->grant($shopId, $this->d(31), 'dhiran_monthly', 'monthly')->assertSessionHasNoErrors();
        $this->assertSame(3, ShopSubscription::where('shop_id', $shopId)->count());
        $new = ShopSubscription::where('shop_id', $shopId)->where('status', 'active')->sole();
        $this->assertSame([$this->d(0), $this->d(31), $this->d(31)], [$new->starts_at->toDateString(), $new->ends_at->toDateString(), $new->grace_ends_at->toDateString()]);
        $this->assertNull($new->cancelled_at);
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
        $this->assertSame($new->id, $owner->shop->fresh()->currentSubscription()->id);
        $this->assertSame(['admin_terminated', 'admin_terminated', 'admin_granted'], $this->types());
    }

    public function test_grant_for_a_cycle_the_plan_does_not_sell_writes_nothing(): void
    {
        $this->signIn($this->admin()); $shop = \App\Models\Shop::create(['name' => 'X']);
        $this->grant($shop->id, today()->addMonth()->toDateString(), 'dhiran_monthly', 'yearly')->assertSessionHasErrors('billing_cycle');
        $this->grant($shop->id, today()->addMonth()->toDateString(), 'dhiran_yearly', 'monthly')->assertSessionHasErrors('billing_cycle');
        $this->assertSame(0, ShopSubscription::count());
    }
}
