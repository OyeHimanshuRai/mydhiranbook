<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Dhiran\Notices;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The bell and the daily card: counts by day window, one-click links that land on exactly the counted loans, once a day, settings. */
class NoticesTest extends TestCase
{
    use RefreshDatabase;

    private $shop;
    private User $owner;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00', 'Asia/Kolkata'));
        $this->shop = self::createDhiranShop(['name' => 'Notice Co', 'shop_type' => 'dhiran', 'phone' => '9390024100', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390024100', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390024100', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'n@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Ram', 'last_name' => 'Lal', 'mobile' => '9815630100']));
        Notices::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Notices::forget();
        parent::tearDown();
    }

    private function loan(array $set = [], $shop = null, $customer = null): DhiranLoan
    {
        $shop ??= $this->shop;
        $customer ??= $this->customer;
        $this->actingAs($this->owner);
        $loan = TenantContext::runFor($shop->id, fn () => app(DhiranService::class)->createLoan($shop, $customer, [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000]],
            ['principal_amount' => 50000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 12, 'grace_period_days' => 5, 'loan_date' => '2026-06-01', 'created_by' => $this->owner->id]));
        DhiranLoan::withoutGlobalScopes()->whereKey($loan->id)->update($set);
        Notices::forget();

        return $loan;
    }

    private function counts(int $shopId = null): array
    {
        return collect(Notices::forShop($shopId ?? $this->shop->id))->pluck('count', 'key')->all();
    }

    public function test_a_calm_shop_has_no_notices(): void
    {
        $this->loan();
        $this->assertSame([], $this->counts());
    }

    public function test_installments_are_counted_by_the_days_until_they_fall_due(): void
    {
        // Paid-in-advance loans from the 1st: next due date = loan day + periods paid months.
        $due = fn (string $day) => ['advance_interest_months' => 1, 'interest_periods_paid' => 1, 'loan_date' => Carbon::parse($day)->subMonthNoOverflow()->toDateString()];
        $this->loan($due('2026-06-14'));   // yesterday: late
        $this->loan($due('2026-06-18'));   // 3 days
        $this->loan($due('2026-06-22'));   // 7 days
        $this->loan($due('2026-07-15'));   // 30 days
        $this->loan($due('2026-07-16'));   // 31 days: not yet
        $this->loan(['advance_interest_months' => 0, 'loan_date' => '2026-05-15']); // paid afterwards: no installments
        $this->assertSame(['inst_late' => 1, 'inst_w1' => 1, 'inst_w2' => 1, 'inst_w3' => 1], $this->counts());
    }

    public function test_forfeiture_rows_follow_the_loan_stages(): void
    {
        $this->loan(['maturity_date' => '2026-06-30', 'grace_period_days' => 5]);                     // forfeiture point 5 Jul: within 30 days
        $this->loan(['maturity_date' => '2026-06-01', 'grace_period_days' => 5]);                     // point 6 Jun: notice due
        $this->loan(['maturity_date' => '2026-05-01', 'grace_period_days' => 0, 'forfeiture_notice_sent_at' => '2026-05-20 10:00:00', 'forfeiture_deadline' => '2026-06-15']); // deadline today: ready
        $this->loan(['maturity_date' => '2026-05-01', 'grace_period_days' => 0, 'forfeiture_notice_sent_at' => '2026-06-10 10:00:00', 'forfeiture_deadline' => '2026-07-10']); // notice out, waiting: nothing to do
        $this->loan(['maturity_date' => '2026-09-30']);                                                // far away
        $this->assertSame(['forfeit_ready' => 1, 'forfeit_notice' => 1, 'forfeit_soon' => 1], $this->counts());
    }

    public function test_pending_loans_and_other_shops_and_closed_loans(): void
    {
        $this->loan(['status' => 'pending_evidence']);
        $this->loan(['status' => 'closed', 'maturity_date' => '2026-01-01']);
        $other = self::createDhiranShop(['name' => 'Other', 'shop_type' => 'dhiran', 'phone' => '9390024101', 'owner_first_name' => 'O', 'owner_last_name' => 'X',
            'owner_mobile' => '9390024101', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($other->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $oc = TenantContext::runFor($other->id, fn () => Customer::create(['shop_id' => $other->id, 'first_name' => 'Z', 'last_name' => 'Z', 'mobile' => '9815630101']));
        $this->loan(['status' => 'pending_evidence'], $other, $oc);
        $this->assertSame(['pending' => 1], $this->counts());
        $this->assertSame(['pending' => 1], $this->counts($other->id));
    }

    public function test_one_loan_links_to_itself_and_many_link_to_the_filtered_list_with_the_same_loans(): void
    {
        $a = $this->loan(['status' => 'pending_evidence']);
        $row = Notices::forShop($this->shop->id)[0];
        $this->assertSame(route('dhiran.show', $a->id), $row['url']);

        $b = $this->loan(['status' => 'pending_evidence']);
        $c = $this->loan(['maturity_date' => '2026-06-01', 'grace_period_days' => 5]); // notice due
        $d = $this->loan(['maturity_date' => '2026-06-01', 'grace_period_days' => 5]);
        $urls = collect(Notices::forShop($this->shop->id))->pluck('url', 'key');
        $this->assertSame(route('dhiran.loans', ['status' => 'pending_evidence']), $urls['pending']);
        $this->assertSame(route('dhiran.loans', ['forfeit' => 'notice']), $urls['forfeit_notice']);

        $list = $this->actingAs($this->owner)->get($urls['forfeit_notice'])->assertOk();
        $list->assertSee($c->loan_number)->assertSee($d->loan_number)->assertDontSee($a->loan_number)->assertSee('Forfeiture notice due');
    }

    public function test_the_card_opens_until_the_page_says_it_was_seen_then_stays_shut_for_the_day(): void
    {
        $this->loan(['status' => 'pending_evidence']);
        $auto = 'noticesCard-t" data-auto="1"';
        $first = $this->actingAs($this->owner)->get('/dhiran/loans')->assertOk();
        $first->assertSee($auto, false)->assertSee('1 loan waiting for evidence')->assertSee('data-notices-open', false);
        // A render nobody looked at (reload, redirect, prefetch) does not use the day's card up.
        $this->actingAs($this->owner)->get('/dhiran/loans')->assertSee($auto, false);
        $this->actingAs($this->owner)->post(route('dhiran.notices.seen'))->assertNoContent();
        $this->owner = User::findOrFail($this->owner->id);   // a real request loads the user afresh
        $this->actingAs($this->owner)->get('/dhiran/loans')->assertDontSee($auto, false)->assertSee('1 loan waiting for evidence')->assertSee('data-notices-open', false);

        Carbon::setTestNow(Carbon::parse('2026-06-16 09:00', 'Asia/Kolkata'));
        $this->actingAs($this->owner)->get('/dhiran/loans')->assertSee($auto, false);
    }

    public function test_a_prefetch_or_frame_refresh_never_opens_the_card(): void
    {
        $this->loan(['status' => 'pending_evidence']);
        $auto = 'noticesCard-t" data-auto="1"';
        foreach (['X-Sec-Purpose', 'Sec-Purpose', 'Purpose'] as $header) {
            $this->actingAs($this->owner)->get('/dhiran/loans', [$header => 'prefetch'])->assertDontSee($auto, false);
        }
        $this->actingAs($this->owner)->get('/dhiran/loans', ['Turbo-Frame' => 'settings-pane'])->assertDontSee($auto, false);
        $this->actingAs($this->owner)->get('/dhiran/loans')->assertSee($auto, false);
    }

    public function test_the_card_stays_shut_when_the_shop_turns_it_off_or_there_is_nothing_to_say(): void
    {
        $this->actingAs($this->owner)->get('/dhiran/loans')->assertSee('noticesCard-t" data-auto="0"', false)->assertSee('All clear');
        $this->loan(['status' => 'pending_evidence']);
        DhiranSettings::getForShop($this->shop->id)->update(['notices_popup' => false]);
        $this->actingAs($this->owner)->get('/dhiran/loans')->assertDontSee('noticesCard-t" data-auto="1"', false)->assertSee('1 loan waiting for evidence');
    }

    public function test_settings_change_the_windows_and_hide_lines(): void
    {
        $this->loan(['advance_interest_months' => 1, 'interest_periods_paid' => 1, 'loan_date' => '2026-05-25']); // next due 25 Jun: 10 days away
        $this->loan(['status' => 'pending_evidence']);
        $this->assertSame(['pending' => 1, 'inst_w3' => 1], $this->counts());

        $this->actingAs($this->owner)->get(route('dhiran.settings.notices'))->assertOk()->assertSee('Notifications')->assertSee('Forfeiture warning');
        $this->actingAs($this->owner)->patch(route('dhiran.settings.notices.update'), ['popup' => '1', 'days' => [5, 12, 40], 'forfeit_days' => 20,
            'show' => ['pending', 'inst_w1', 'inst_w2', 'inst_w3', 'inst_late', 'forfeit_soon', 'forfeit_notice', 'forfeit_ready']])->assertRedirect(route('dhiran.settings.notices'));
        Notices::forget();
        $this->assertSame(['pending' => 1, 'inst_w2' => 1], $this->counts());

        $this->actingAs($this->owner)->patch(route('dhiran.settings.notices.update'), ['popup' => '1', 'days' => [5, 12, 40], 'forfeit_days' => 20, 'show' => ['inst_w2']]);
        Notices::forget();
        $this->assertSame(['inst_w2' => 1], $this->counts());
        $this->actingAs($this->owner)->get(route('dhiran.loans', ['installment' => 'w2']))->assertOk()->assertSee('Interest due in 6–12 days');
    }

    public function test_windows_must_grow_and_stay_sane(): void
    {
        foreach ([[7, 3, 30], [3, 3, 30], [0, 7, 30], [3, 7, 91]] as $days) {
            $this->actingAs($this->owner)->patch(route('dhiran.settings.notices.update'), ['days' => $days, 'forfeit_days' => 30, 'show' => []])->assertSessionHasErrors();
        }
        $this->assertSame([3, 7, 30], Notices::options($this->shop->id)['days']);
    }
}
