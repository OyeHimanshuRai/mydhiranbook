<?php
namespace Tests\Feature\Onboarding;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures, BillingFixtures;

    private function biz(array $over = []): array { return $over + ['name' => 'Sharma Gold Finance', 'phone' => '9876543210', 'address' => '12 Main Road, Indore', 'loan_number_prefix' => 'SGF']; }

    public function test_verified_user_without_payment_sees_plans(): void
    {
        (new \Database\Seeders\PlanSeeder)->run();
        $u = $this->newOwner();
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.plans'));
        $this->actingAs($u)->get(route('dhiran.plans'))->assertOk()->assertSee('Dhiran Monthly')->assertSee(route('subscription.choose'), false);
        $this->actingAs($u)->get(route('dhiran.onboarding'))->assertRedirect(route('dhiran.plans'));
    }

    public function test_paid_user_without_shop_resumes_create_business(): void
    {
        $u = $this->newOwner(); $this->paidShopless($u);
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.onboarding'));
        $this->actingAs($u)->get(route('dhiran.plans'))->assertRedirect(route('dhiran.onboarding'));
        $this->actingAs($u)->get(route('dhiran.onboarding'))->assertOk()->assertSee('Business name');
    }

    public function test_cannot_create_business_without_captured_payment(): void
    {
        $u = $this->newOwner();
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz())->assertRedirect(route('dhiran.plans'));
        $this->assertDatabaseCount('shops', 0); $this->assertNull($u->fresh()->shop_id);
        $this->paidShopless($u, 'cancelled');
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz())->assertRedirect(route('dhiran.plans'));
        $this->assertDatabaseCount('shops', 0);
    }

    public function test_create_business_links_subscription_and_owner_role(): void
    {
        $u = $this->newOwner(); $a = $this->paidShopless($u); $b = $this->paidShopless($u);
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz())->assertRedirect('/');
        $shop = Shop::sole(); $u->refresh();
        $this->assertSame($shop->id, $u->shop_id); $this->assertTrue($u->isShopOwner()); $this->assertSame('active', $shop->status);
        $this->assertSame($shop->id, $a->fresh()->shop_id); $this->assertSame($shop->id, $b->fresh()->shop_id);
        $s = DhiranSettings::withoutTenant()->where('shop_id', $shop->id)->sole();
        $this->assertTrue((bool) $s->is_enabled); $this->assertSame('SGF', $s->loan_number_prefix);
        $this->assertSame(6, \App\Models\Role::withoutTenant()->where('shop_id', $shop->id)->count());
        $this->actingAs($u)->get('/dhiran')->assertOk();
    }

    public function test_second_business_for_same_owner_refused(): void
    {
        $u = $this->newOwner(); $this->paidShopless($u);
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz());
        $this->paidShopless($u);
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz(['name' => 'Second']))->assertRedirect('/');
        $this->assertDatabaseCount('shops', 1);
        $this->assertSame(1, ShopSubscription::whereNull('shop_id')->count());
    }

    public function test_business_requires_valid_fields(): void
    {
        $u = $this->newOwner(); $this->paidShopless($u);
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz(['name' => '', 'phone' => '123']))->assertSessionHasErrors(['name', 'phone']);
        $this->assertDatabaseCount('shops', 0);
    }

    public function test_pre_shop_receipt_stays_visible_after_business_created(): void
    {
        \Illuminate\Support\Facades\Mail::fake(); $this->setUpBilling();
        $u = $this->shoplessUser();
        $this->actingAs($u)->post('/subscription/payment/callback', $this->paidOrder($u))->assertRedirect(route('dhiran.onboarding'));
        $inv = PlatformInvoice::sole(); $this->assertNull($inv->shop_id);
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz())->assertRedirect('/');
        $this->assertSame(Shop::sole()->id, $inv->fresh()->shop_id);
        $this->actingAs($u->fresh())->get(route('subscription.status'))->assertOk()->assertSee($inv->invoice_number);
    }

    public function test_expired_by_date_shopless_subscription_cannot_create_business(): void
    {
        $u = $this->newOwner(); $s = $this->paidShopless($u);
        $s->forceFill(['ends_at' => today()->subDays(20), 'grace_ends_at' => today()->subDays(6)])->save();
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.plans'));
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz())->assertRedirect(route('dhiran.plans'));
        $this->assertDatabaseCount('shops', 0);
        $s->forceFill(['ends_at' => today()->subDays(2), 'grace_ends_at' => today()->addDay()])->save();
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.onboarding'));
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
        $u = $this->newOwner(['email_verified_at' => null]); $this->paidShopless($u);
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz())->assertRedirect(route('dhiran.verify-email'));
        $this->actingAs($u)->get(route('dhiran.onboarding'))->assertRedirect(route('dhiran.verify-email'));
        $this->assertDatabaseCount('shops', 0);
    }

    public function test_dhiran_route_goes_through_onboarding_chain_for_shopless_user(): void
    {
        $u = $this->newOwner(['email_verified_at' => null]);
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.verify-email'));
        $u->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.plans'));
        $this->paidShopless($u);
        $this->actingAs($u)->get('/dhiran')->assertRedirect(route('dhiran.onboarding'));
    }

    public function test_posted_shop_id_and_role_fields_ignored_on_create_business(): void
    {
        $other = Shop::create(['name' => 'Someone else']);
        $u = $this->newOwner(); $this->paidShopless($u);
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), $this->biz(['shop_id' => $other->id, 'role' => 'viewer', 'role_id' => 1, 'user_id' => 1, 'status' => 'suspended']))->assertRedirect('/');
        $mine = Shop::where('name', 'Sharma Gold Finance')->sole(); $u->refresh();
        $this->assertSame($mine->id, $u->shop_id); $this->assertNotSame($other->id, $u->shop_id);
        $this->assertSame('active', $mine->status); $this->assertSame('owner', $u->role); $this->assertTrue($u->isShopOwner());
    }
}
