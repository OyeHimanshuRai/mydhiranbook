<?php
namespace Tests\Feature\Onboarding;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

/** A turned-down shop's owner fixes the details and sends them again (owner 2026-11-25). */
class ShopResubmitTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures;

    private function rejected(): array
    {
        $u = $this->newOwner();
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), ['name' => 'Wrong Phone Co', 'phone' => '9876543210', 'address' => 'Main Road', 'loan_number_prefix' => 'WPC']);
        $shop = Shop::sole();
        $shop->update(['status' => 'rejected', 'review_note' => 'Phone number is wrong', 'reviewed_at' => now()]);

        return [$shop->fresh(), $u->fresh()];
    }

    private function fixed(array $over = []): array { return $over + ['name' => 'Right Phone Co', 'phone' => '9876500099', 'address' => '14 Sarafa Bazaar', 'loan_number_prefix' => 'RPC']; }

    public function test_the_rejected_page_shows_the_reason_and_a_prefilled_form(): void
    {
        [$shop, $u] = $this->rejected();
        $this->actingAs($u)->get(route('dhiran.pending'))->assertOk()->assertSee('could not approve')->assertSee('Phone number is wrong')
            ->assertSee('Fix and send again')->assertSee('value="Wrong Phone Co"', false)->assertSee('value="9876543210"', false)->assertSee('value="WPC"', false);
    }

    public function test_sending_again_updates_the_details_and_puts_the_shop_back_in_the_queue(): void
    {
        [$shop, $u] = $this->rejected();
        $this->actingAs($u)->post(route('dhiran.resubmit'), $this->fixed())->assertRedirect(route('dhiran.pending'));
        $shop->refresh();
        $this->assertSame(['pending', 'Right Phone Co', '9876500099', '14 Sarafa Bazaar', 2], [$shop->status, $shop->name, $shop->phone, $shop->address, $shop->submissions]);
        $this->assertSame('Phone number is wrong', $shop->previous_review_note); $this->assertNull($shop->review_note); $this->assertNotNull($shop->resubmitted_at);
        $this->assertSame('RPC', DhiranSettings::withoutTenant()->where('shop_id', $shop->id)->value('loan_number_prefix'));
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'shop.resubmitted', 'target_id' => $shop->id]);
        $this->actingAs($u->fresh())->get(route('dhiran.pending'))->assertOk()->assertSee('waiting for approval')->assertSee('Right Phone Co');
    }

    public function test_only_a_rejected_shop_can_be_sent_again_and_the_details_are_validated(): void
    {
        $u = $this->newOwner();
        $this->actingAs($u)->post(route('dhiran.onboarding.store'), ['name' => 'Waiting Co', 'phone' => '9876543210', 'address' => 'Main Road']);
        $this->actingAs($u->fresh())->post(route('dhiran.resubmit'), $this->fixed())->assertSessionHas('error');
        $this->assertSame('Waiting Co', Shop::sole()->name);                       // pending stays untouched

        Shop::sole()->update(['status' => 'active']);
        $this->actingAs($u->fresh())->post(route('dhiran.resubmit'), $this->fixed())->assertSessionHas('error');
        $this->assertSame('Waiting Co', Shop::sole()->name);

        Shop::sole()->update(['status' => 'rejected']);
        $this->actingAs($u->fresh())->post(route('dhiran.resubmit'), $this->fixed(['name' => '', 'phone' => '123']))->assertSessionHasErrors(['name', 'phone']);
        $this->assertSame('rejected', Shop::sole()->status);
    }

    public function test_someone_without_a_shop_or_not_the_owner_cannot_resubmit(): void
    {
        $this->actingAs($this->newOwner())->post(route('dhiran.resubmit'), $this->fixed())->assertForbidden();
        [$shop, $owner] = $this->rejected();
        $staff = \App\Models\User::create(['name' => 'Staff', 'email' => 'staff@onboard.test', 'mobile_number' => '9000000888', 'password' => 'a-long-test-password', 'shop_id' => $shop->id, 'role' => 'staff', 'is_active' => true, 'email_verified_at' => now()]);
        $this->actingAs($staff)->post(route('dhiran.resubmit'), $this->fixed())->assertForbidden();
        $this->assertSame('rejected', $shop->fresh()->status);
    }
}
