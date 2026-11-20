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
        $this->actingAs($u)->get('/')->assertRedirect(route('dhiran.plans'));
        $this->actingAs($u)->get(route('dhiran.plans'))->assertOk()->assertSee('Dhiran Monthly')->assertSee(route('subscription.choose'), false);
        $this->actingAs($u)->get(route('dhiran.onboarding'))->assertRedirect(route('dhiran.plans'));
    }

    public function test_paid_user_without_shop_resumes_create_business(): void
    {
        $u = $this->newOwner(); $this->paidShopless($u);
        $this->actingAs($u)->get('/')->assertRedirect(route('dhiran.onboarding'));
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
        $this->actingAs($u)->get('/')->assertOk();
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
        $this->assertNull($inv->fresh()->shop_id);
        $this->actingAs($u->fresh())->get(route('subscription.status'))->assertOk()->assertSee($inv->invoice_number);
    }
}
