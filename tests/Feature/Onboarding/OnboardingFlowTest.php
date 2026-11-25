<?php
namespace Tests\Feature\Onboarding;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

/** Verify email -> business form -> wait for an admin (owner 2026-11-25: no payment step). */
class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures, BillingFixtures;

    private function biz(array $over = []): array { return $over + ['name' => 'Sharma Gold Finance', 'phone' => '9876543210', 'address' => '12 Main Road, Indore', 'loan_number_prefix' => 'SGF']; }

    public function test_a_verified_user_without_a_shop_goes_straight_to_the_business_form(): void
    {
        $u = $this->newOwner();
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.onboarding'));
        $this->actingAs($u)->get(route('dhiran.plans'))->assertRedirect(route('dhiran.onboarding'));   // the old plans step is gone
        $this->actingAs($u)->get(route('dhiran.onboarding'))->assertOk()->assertSee('Business name')->assertSee('Send for approval');
    }

    public function test_sending_the_business_form_makes_a_pending_shop_and_waits(): void
    {
        $u = $this->newOwner();
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz())->assertRedirect(route('dhiran.pending'));
        $shop = Shop::sole(); $u->refresh();
        $this->assertSame('pending', $shop->status);
        $this->assertSame($shop->id, $u->shop_id); $this->assertTrue($u->isShopOwner());
        $s = DhiranSettings::withoutTenant()->where('shop_id', $shop->id)->sole();
        $this->assertTrue((bool) $s->is_enabled); $this->assertSame('SGF', $s->loan_number_prefix);
        $this->assertSame(6, \App\Models\Role::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, $shop->subscriptions()->count(), 'no plan or payment until an admin approves');

        $this->actingAs($u)->get(route('dhiran.pending'))->assertOk()->assertSee('waiting for approval')->assertSee('Sharma Gold Finance');
    }

    public function test_a_waiting_shop_cannot_open_any_page_of_the_software(): void
    {
        $u = $this->newOwner();
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz());
        foreach (['/dhiran', '/dhiran/loans', '/dhiran/create', '/dhiran/settings', '/dhiran/borrowers', '/settings'] as $url) {
            $this->actingAs($u->fresh())->get($url)->assertRedirect(route('dhiran.pending'));
        }
        $this->actingAs($u->fresh())->get(route('dhiran.onboarding'))->assertRedirect(route('dhiran.pending'));
        $this->actingAs($u->fresh())->post(route('dhiran.onboarding.store'), $this->biz(['name' => 'Second']))->assertRedirect(route('dhiran.pending'));
        $this->assertDatabaseCount('shops', 1);
    }

    public function test_a_turned_down_shop_sees_the_reason_and_still_cannot_enter(): void
    {
        $u = $this->newOwner();
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz());
        Shop::sole()->update(['status' => 'rejected', 'review_note' => 'Phone number does not match the business', 'reviewed_at' => now()]);
        $this->actingAs($u->fresh())->get(route('dhiran.pending'))->assertOk()->assertSee('could not approve')->assertSee('Phone number does not match the business');
        $this->actingAs($u->fresh())->get('/dhiran/loans')->assertRedirect(route('dhiran.pending'));
    }

    public function test_once_approved_the_waiting_page_opens_the_software(): void
    {
        $u = $this->newOwner();
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz());
        $shop = Shop::sole();
        $shop->update(['status' => 'active']);
        self::activeSubscription($shop);
        $this->actingAs($u->fresh())->get(route('dhiran.pending'))->assertRedirect('/');
        $this->actingAs($u->fresh())->get('/dhiran/loans')->assertOk();
    }

    public function test_business_requires_valid_fields(): void
    {
        $u = $this->newOwner();
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz(['name' => '', 'phone' => '123']))->assertSessionHasErrors(['name', 'phone']);
        $this->assertDatabaseCount('shops', 0);
    }

    public function test_user_with_shop_passes_gates_even_if_unverified(): void
    {
        $this->setUpBilling();
        $o = $this->shopUser(); $this->assertNull($o->email_verified_at);
        self::activeSubscription($o->shop);
        $this->actingAs($o)->get('/dhiran')->assertOk();
        $this->actingAs($o)->get('/dhiran')->assertOk();
    }

    public function test_create_business_blocked_when_email_unverified(): void
    {
        $u = $this->newOwner(['email_verified_at' => null]);
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz())->assertRedirect(route('dhiran.verify-email'));
        $this->actingAs($u)->get(route('dhiran.onboarding'))->assertRedirect(route('dhiran.verify-email'));
        $this->assertDatabaseCount('shops', 0);
    }

    public function test_dhiran_route_goes_through_verify_then_business_form_for_a_shopless_user(): void
    {
        $u = $this->newOwner(['email_verified_at' => null]);
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.verify-email'));
        $u->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.onboarding'));
    }

    public function test_posted_shop_id_role_and_status_fields_are_ignored(): void
    {
        $other = Shop::create(['name' => 'Someone else']);
        $u = $this->newOwner();
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz(['shop_id' => $other->id, 'role' => 'viewer', 'role_id' => 1, 'user_id' => 1, 'status' => 'active']))->assertRedirect(route('dhiran.pending'));
        $mine = Shop::where('name', 'Sharma Gold Finance')->sole(); $u->refresh();
        $this->assertSame($mine->id, $u->shop_id); $this->assertNotSame($other->id, $u->shop_id);
        $this->assertSame('pending', $mine->status, 'a posted status never opens the shop'); $this->assertSame('owner', $u->role); $this->assertTrue($u->isShopOwner());
    }
}
