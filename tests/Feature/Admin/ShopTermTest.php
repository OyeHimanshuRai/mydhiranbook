<?php
namespace Tests\Feature\Admin;

use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Services\Dhiran\Notices;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OnboardingFixtures;

/** The one-year access term (owner 2026-11-25): approval grants a year, the end blocks everybody, an admin extends by another year. */
class ShopTermTest extends AdminTestCase
{
    use OnboardingFixtures;

    private function approvedShop(): array
    {
        Mail::fake(); (new PlanSeeder)->run();
        $owner = $this->newOwner();
        $this->actingAs($owner)->post(route('dhiran.onboarding.store'), ['name' => 'Term Co', 'phone' => '9876543210', 'address' => 'Main Road'])->assertRedirect(route('dhiran.pending'));
        Auth::guard('web')->logout();
        $admin = $this->signIn($this->admin());
        $shop = Shop::where('name', 'Term Co')->sole();
        $this->post('/admin/requests/'.$shop->id.'/approve')->assertSessionHasNoErrors();
        Auth::shouldUse('web');

        return [$shop->fresh(), $owner->fresh(), $admin];
    }

    public function test_approval_grants_exactly_one_year_and_ignores_posted_dates(): void
    {
        [$shop] = $this->approvedShop();
        $sub = $shop->currentSubscription();
        $this->assertSame(today()->addYear()->toDateString(), $sub->ends_at->toDateString());
        $this->assertSame($sub->ends_at->toDateString(), $sub->grace_ends_at->toDateString(), 'no grace days: the year is the year');
        $this->assertSame('dhiran_yearly', $sub->plan->code);

        $other = Shop::create(['name' => 'Other', 'status' => 'pending']);
        $this->post('/admin/requests/'.$other->id.'/approve', ['ends_at' => today()->addYears(50)->toDateString(), 'plan' => 'dhiran_monthly'])->assertSessionHasNoErrors();
        $this->assertSame(today()->addYear()->toDateString(), $other->fresh()->currentSubscription()->ends_at->toDateString());
    }

    public function test_approving_needs_both_the_shops_and_the_subscriptions_permission(): void
    {
        (new PlanSeeder)->run(); $this->limited(['can_manage_shops']);
        $shop = Shop::create(['name' => 'Waiting', 'status' => 'pending']);
        $this->post('/admin/requests/'.$shop->id.'/approve')->assertForbidden();
        $this->assertSame('pending', $shop->fresh()->status);
    }

    public function test_when_the_year_ends_everybody_of_the_shop_is_blocked_and_kept_out_of_every_page(): void
    {
        [$shop, $owner] = $this->approvedShop();
        $this->actingAs($owner)->get('/dhiran/loans')->assertOk();
        $this->travel(366)->days();
        foreach (['/dhiran', '/dhiran/loans', '/dhiran/create', '/dhiran/settings', '/dhiran/reports', '/settings'] as $url) {
            $this->actingAs($owner->fresh())->get($url)->assertRedirect(route('dhiran.expired'));
        }
        $this->actingAs($owner->fresh())->post('/dhiran/customers', ['first_name' => 'X', 'mobile' => '9815630111'])->assertRedirect(route('dhiran.expired'));
        $this->actingAs($owner->fresh())->get(route('dhiran.expired'))->assertOk()->assertSee('Your access has ended')->assertSee('Term Co');
    }

    public function test_the_last_day_of_the_term_still_works_and_the_next_day_does_not(): void
    {
        [$shop, $owner] = $this->approvedShop();
        $this->travelTo(today()->addYear()->setTime(10, 0));
        $this->actingAs($owner->fresh())->get('/dhiran/loans')->assertOk();
        $this->travelTo(today()->addDay()->setTime(10, 0));
        $this->actingAs($owner->fresh())->get('/dhiran/loans')->assertRedirect(route('dhiran.expired'));
    }

    public function test_extending_adds_a_year_counted_from_the_end_or_from_today_when_already_ended(): void
    {
        [$shop, $owner, $admin] = $this->approvedShop();
        $end = $shop->currentSubscription()->ends_at->copy();
        $this->post('/admin/shops/'.$shop->id.'/extend')->assertSessionHasNoErrors();   // still running: counted from its end
        $this->assertSame($end->copy()->addYear()->toDateString(), $shop->fresh()->currentSubscription()->ends_at->toDateString());

        // the term ended long ago: counted from today (no time travel: the admin sign-in code is tied to the real clock)
        ShopSubscription::where('shop_id', $shop->id)->update(['ends_at' => today()->subDays(100), 'grace_ends_at' => today()->subDays(100)]);
        Auth::shouldUse('web');   // one app instance serves both the admin and the owner session in a test
        $this->actingAs($owner->fresh())->get('/dhiran/loans')->assertRedirect(route('dhiran.expired'));
        Auth::shouldUse('platform_admin');
        $this->post('/admin/shops/'.$shop->id.'/extend')->assertSessionHasNoErrors();
        $this->assertSame(today()->addYear()->toDateString(), $shop->fresh()->currentSubscription()->ends_at->toDateString());
        Auth::shouldUse('web');
        $this->actingAs($owner->fresh())->get('/dhiran/loans')->assertOk();
        $this->assertSame(2, PlatformAuditLog::where('action', 'subscription.extended')->count());
    }

    public function test_extending_needs_the_subscriptions_permission_and_an_approved_shop(): void
    {
        (new PlanSeeder)->run(); $this->limited(['can_manage_shops']);
        $active = Shop::create(['name' => 'Live', 'status' => 'active']);
        $this->post('/admin/shops/'.$active->id.'/extend')->assertForbidden();
        $this->post('/admin/logout');
        $this->signIn($this->admin(['email' => 'second@example.test']));
        $pending = Shop::create(['name' => 'Waiting', 'status' => 'pending']);
        $this->post('/admin/shops/'.$pending->id.'/extend')->assertSessionHas('error');
        $this->assertSame(0, ShopSubscription::where('shop_id', $pending->id)->count());
    }

    public function test_the_bell_warns_in_the_last_30_days_of_the_term(): void
    {
        [$shop, $owner] = $this->approvedShop();
        Notices::forget();
        $this->assertNotContains('plan_ending', collect(Notices::forShop($shop->id))->pluck('key')->all());
        $this->travel(340)->days(); Notices::forget();
        $row = collect(Notices::forShop($shop->id))->firstWhere('key', 'plan_ending');
        $this->assertNotNull($row);
        $this->assertStringContainsString('Your access ends in', $row['title']);
        $this->assertSame(route('subscription.status'), $row['url']);
    }
}
