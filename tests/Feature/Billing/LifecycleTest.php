<?php
namespace Tests\Feature\Billing;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    private const HOST = 'http://127.0.0.1:8787';

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); }

    /** Owner of a shop with Dhiran switched on and one borrower. */
    private function owner(): User
    {
        $owner = $this->shopUser();
        DhiranSettings::getForShop($owner->shop_id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        return $owner;
    }

    private function customer(User $owner): Customer
    {
        return TenantContext::runFor($owner->shop_id, fn () => Customer::create(['shop_id' => $owner->shop_id, 'first_name' => 'Life', 'last_name' => 'Cycle', 'mobile' => '9815550002']));
    }

    private function loanItems(int $n = 1): array
    {
        return array_fill(0, $n, ['description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'rate_per_gram_at_pledge' => 6000]);
    }

    private function makeLoan(User $owner, Customer $c, int $items = 1): DhiranLoan
    {
        return TenantContext::runFor($owner->shop_id, fn () => app(DhiranService::class)->createLoan($owner->shop, $c, $this->loanItems($items), ['principal_amount' => 10000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id]));
    }

    private function send(User $owner, string $method, string $path, array $data = [])
    {
        return TenantContext::runFor($owner->shop_id, fn () => $this->actingAs($owner)->call($method, self::HOST . $path, $data));
    }

    public function test_expiry_command_moves_active_to_grace_to_expired(): void
    {
        $shop = $this->shopUser()->shop;
        $sub = self::activeSubscription($shop, 'active', today()->subDay());
        $sub->update(['grace_ends_at' => null]);
        $fresh = self::activeSubscription($this->shopUser()->shop, 'active', today()->addDays(5)); // untouched

        $this->artisan('subscription:check-expiry')->assertSuccessful();
        $sub->refresh();
        $this->assertSame('grace', $sub->status);
        $this->assertSame(today()->subDay()->addDays(14)->toDateString(), $sub->grace_ends_at->toDateString());
        $this->assertSame('active', $fresh->fresh()->status);
        $this->assertTrue($shop->hasEdition('dhiran'));

        $this->artisan('subscription:check-expiry')->assertSuccessful(); // idempotent
        $this->assertSame(1, SubscriptionEvent::where('shop_subscription_id', $sub->id)->count());

        $sub->update(['grace_ends_at' => today()->subDay()]);
        $this->artisan('subscription:check-expiry')->assertSuccessful();
        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertSame(2, SubscriptionEvent::where('shop_subscription_id', $sub->id)->count());
        $this->assertSame(['subscription.grace', 'subscription.expired'], SubscriptionEvent::where('shop_subscription_id', $sub->id)->orderBy('id')->pluck('event_type')->all());

        $this->artisan('subscription:check-expiry')->assertSuccessful();
        $this->assertSame(2, SubscriptionEvent::where('shop_subscription_id', $sub->id)->count());
    }

    public function test_expiry_command_leaves_cancelled_alone(): void
    {
        $sub = self::activeSubscription($this->shopUser()->shop, 'cancelled', today()->subDays(40));
        $this->artisan('subscription:check-expiry')->assertSuccessful();
        $this->assertSame('cancelled', $sub->fresh()->status);
        $this->assertSame(0, SubscriptionEvent::count());
    }

    public function test_grace_shop_has_full_access(): void
    {
        $owner = $this->owner(); $c = $this->customer($owner);
        self::activeSubscription($owner->shop, 'grace', today()->subDays(2));
        $this->send($owner, 'GET', '/dhiran/create')->assertOk();
        $this->send($owner, 'POST', '/dhiran', ['customer_id' => $c->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000, 'idempotency_key' => 'grace-key-001', 'items' => $this->loanItems()])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count());
    }

    public function test_expired_shop_is_sent_to_the_access_ended_page_on_every_page_and_write_and_nothing_changes(): void
    {
        $owner = $this->owner(); $c = $this->customer($owner);
        $sub = self::activeSubscription($owner->shop);
        $loan = $this->makeLoan($owner, $c);
        $sub->update(['status' => 'expired', 'ends_at' => today()->subDays(30), 'grace_ends_at' => today()->subDays(30)]);
        $payments = fn () => \App\Models\Dhiran\DhiranPayment::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->count();
        $before = [$payments(), DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count(), Customer::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count(), $loan->fresh()->status];
        $ended = route('dhiran.expired');

        // no collect-only mode any more: reads and writes alike land on the access-ended page
        $this->send($owner, 'GET', '/dhiran/loans/' . $loan->id)->assertRedirect($ended);
        $this->send($owner, 'GET', '/dhiran/create')->assertRedirect($ended);
        $this->send($owner, 'POST', "/dhiran/loans/{$loan->id}/repay", ['amount' => 1000, 'payment_method' => 'cash', 'idempotency_key' => 'lapsed-key-01'])->assertRedirect($ended);
        foreach (['release-item', 'pre-close', 'close', 'renew'] as $a) {
            $this->send($owner, 'POST', "/dhiran/loans/{$loan->id}/{$a}", ['idempotency_key' => "lapsed-$a"])->assertRedirect($ended);
        }
        $this->send($owner, 'POST', '/dhiran', ['customer_id' => $c->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000, 'idempotency_key' => 'lapsed-key-02', 'items' => $this->loanItems()])->assertRedirect($ended);
        $this->send($owner, 'POST', '/dhiran/customers', ['first_name' => 'New', 'mobile' => '9815550003'])->assertRedirect($ended);
        $this->send($owner, 'GET', '/access-ended')->assertOk()->assertSee('Your access has ended');
        $this->assertSame($before, [$payments(), DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count(), Customer::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count(), $loan->fresh()->status]);
    }

    public function test_expired_shop_can_open_the_read_only_plan_page_and_an_extension_restores_access(): void
    {
        config(['business.email' => 'help@example.test']);
        $owner = $this->owner(); $c = $this->customer($owner);
        $sub = self::activeSubscription($owner->shop, 'expired', today()->subDays(60));
        $this->actingAs($owner)->get('/subscription/status')->assertOk()->assertSee('Ended')->assertSee('To extend your access, contact us')->assertSee('help@example.test')
            ->assertDontSee('Buy this plan')->assertDontSee('Renew with this plan')->assertDontSee('₹');
        $this->assertTrue($owner->shop->fresh()->isLapsed());
        $sub->update(['status' => 'active', 'ends_at' => today()->addYear(), 'grace_ends_at' => today()->addYear()->addDays(14)]); // what an admin extension does
        $this->assertTrue($owner->shop->fresh()->hasEdition('dhiran'));
        $this->send($owner, 'GET', '/dhiran/create')->assertOk();
        $this->get('/subscription/status')->assertOk()->assertSee('Active');
    }

    public function test_plan_page_without_a_term_says_no_access_term_yet(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get('/subscription/status')->assertOk()->assertSee('No access term yet');
    }

    public function test_removed_payment_routes_are_gone(): void
    {
        $owner = $this->owner();
        foreach (['/subscription/payment', '/subscription/receipts/1'] as $url) { $this->actingAs($owner)->get($url)->assertNotFound(); }
        foreach (['/subscription/choose', '/subscription/payment/initiate', '/subscription/payment/callback', '/subscription/payment/webhook'] as $url) { $this->actingAs($owner)->post($url)->assertStatus(404); }
    }

    public function test_staff_limit_blocks_extra_staff(): void
    {
        $owner = $this->owner();
        $sub = self::activeSubscription($owner->shop);
        $sub->update(['plan_id' => $this->plan('dhiran_monthly')->id, 'billing_cycle' => 'monthly']); // staff_limit 3
        $role = app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($owner->shop_id)['staff'];
        $post = fn (int $i) => $this->actingAs($owner)->post('/staff', ['name' => "S$i", 'mobile_number' => '90000010' . $i . '0', 'password' => 'Synthetic-pass-12345', 'password_confirmation' => 'Synthetic-pass-12345', 'role_id' => $role->id]);
        foreach ([1, 2, 3] as $i) { $post($i)->assertSessionHasNoErrors()->assertSessionMissing('error'); }
        $this->assertSame(3, TenantContext::runFor($owner->shop_id, fn () => $owner->shop->currentStaffCount())); // owner is not counted
        $post(4)->assertSessionHas('error');
        $this->assertSame(3, TenantContext::runFor($owner->shop_id, fn () => $owner->shop->fresh()->currentStaffCount()));
        $this->get('/staff/create')->assertRedirect();

        $first = User::where('mobile_number', '9000001010')->firstOrFail();
        $this->delete('/staff/' . $first->id);
        $post(4)->assertSessionMissing('error');
        $this->patch('/staff/' . $first->id . '/reactivate')->assertSessionHas('error'); // back at the limit
        $this->assertFalse($first->fresh()->is_active);
    }

    public function test_item_limit_blocks_new_loan_at_limit(): void
    {
        $owner = $this->owner(); $c = $this->customer($owner);
        $sub = self::activeSubscription($owner->shop);
        $sub->update(['plan_id' => $this->plan('dhiran_monthly')->id, 'billing_cycle' => 'monthly']);
        \App\Models\Platform\Plan::where('code', 'dhiran_monthly')->update(['features' => array_merge($this->plan('dhiran_monthly')->features, ['max_items' => 3])]);
        $this->assertSame(3, $owner->shop->fresh()->itemLimit());

        $a = $this->makeLoan($owner, $c, 2);
        $form = ['customer_id' => $c->id, 'principal_amount' => 10000, 'gold_rate_on_date' => 6000];
        $this->send($owner, 'POST', '/dhiran', $form + ['idempotency_key' => 'limit-key-01', 'items' => $this->loanItems(2)])
            ->assertSessionHasErrors('loan');
        $this->assertSame(1, DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count());
        $this->send($owner, 'POST', '/dhiran', $form + ['idempotency_key' => 'limit-key-02', 'items' => $this->loanItems(1)])->assertSessionHasNoErrors();
        $this->assertSame(2, DhiranLoan::withoutGlobalScopes()->where('shop_id', $owner->shop_id)->count());
        // at the limit now; closed loans free their items
        $this->send($owner, 'POST', '/dhiran', $form + ['idempotency_key' => 'limit-key-03', 'items' => $this->loanItems(1)])->assertSessionHasErrors('loan');
        $a->forceFill(['status' => 'closed'])->save();
        $this->send($owner, 'POST', '/dhiran', $form + ['idempotency_key' => 'limit-key-04', 'items' => $this->loanItems(1)])->assertSessionHasNoErrors();
    }

    public function test_expiry_command_runs_per_shop_tenant_context_restored(): void
    {
        $a = $this->shopUser()->shop; $b = $this->shopUser()->shop;
        self::activeSubscription($a, 'active', today()->subDay());
        self::activeSubscription($b, 'active', today()->subDay());
        $this->assertNull(TenantContext::get());
        $this->artisan('subscription:check-expiry')->assertSuccessful();
        $this->assertNull(TenantContext::get());
        $this->assertSame(2, ShopSubscription::where('status', 'grace')->count());
        $this->assertSame(2, SubscriptionEvent::whereIn('shop_id', [$a->id, $b->id])->count());
    }
}
