<?php
namespace Tests\Feature\Admin;

use App\Mail\ShopDecisionMail;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OnboardingFixtures;

/** The admin's side of onboarding: list new shops, approve (grants access) or reject (with a reason the owner sees). */
class ShopRequestTest extends AdminTestCase
{
    use RefreshDatabase, OnboardingFixtures;

    /** A real new owner: verified email, business form sent (so the owner role and settings exist), waiting. */
    private function waiting(string $name = 'Sharma Gold'): array
    {
        $owner = $this->newOwner();
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $this->actingAs($owner)->post(route('dhiran.onboarding.store'), ['name' => $name, 'phone' => '9876543210', 'address' => 'Main Road'])->assertRedirect(route('dhiran.pending'));
        \Illuminate\Support\Facades\Auth::guard('web')->logout();

        return [Shop::where('name', $name)->sole(), $owner->fresh()];
    }

    private function approval(array $over = []): array
    {
        return $over + ['plan' => 'dhiran_monthly', 'billing_cycle' => 'monthly', 'ends_at' => today()->addMonth()->toDateString()];
    }

    public function test_the_list_shows_waiting_shops_with_their_owner(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin());
        [$shop, $owner] = $this->waiting();
        Shop::create(['name' => 'Already Live', 'status' => 'active']);
        $this->get('/admin/requests')->assertOk()->assertSee('Sharma Gold')->assertSee('9876543210')->assertSee($owner->email)->assertDontSee('Already Live');
        $this->get('/admin')->assertOk()->assertSee('1 new shop request');
    }

    public function test_approving_opens_the_shop_grants_access_audits_and_emails_the_owner(): void
    {
        Mail::fake(); (new PlanSeeder)->run(); $admin = $this->signIn($this->admin());
        [$shop, $owner] = $this->waiting();
        $this->post('/admin/requests/'.$shop->id.'/approve', $this->approval())->assertRedirect()->assertSessionHasNoErrors();
        $shop->refresh();
        $this->assertSame('active', $shop->status); $this->assertNotNull($shop->reviewed_at);
        $this->assertTrue($shop->hasEdition('dhiran'), 'the approval grants the plan');
        $this->assertDatabaseHas('platform_audit_logs', ['actor_admin_id' => $admin->id, 'action' => 'shop.approved', 'target_id' => $shop->id]);
        Mail::assertSent(ShopDecisionMail::class, fn ($m) => $m->hasTo($owner->email) && $m->approved === true);
        // and the owner now gets in
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $this->actingAs($owner->fresh())->get('/dhiran/loans')->assertOk();
        $this->actingAs($owner->fresh())->get(route('dhiran.pending'))->assertRedirect('/');
    }

    public function test_rejecting_keeps_the_shop_shut_and_tells_the_owner_why(): void
    {
        Mail::fake(); (new PlanSeeder)->run(); $this->signIn($this->admin());
        [$shop, $owner] = $this->waiting();
        $this->post('/admin/requests/'.$shop->id.'/reject', [])->assertSessionHasErrors('reason');
        $this->post('/admin/requests/'.$shop->id.'/reject', ['reason' => 'Phone does not match'])->assertRedirect()->assertSessionHasNoErrors();
        $shop->refresh();
        $this->assertSame('rejected', $shop->status); $this->assertSame('Phone does not match', $shop->review_note);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'shop.rejected', 'target_id' => $shop->id]);
        Mail::assertSent(ShopDecisionMail::class, fn ($m) => $m->hasTo($owner->email) && $m->approved === false && $m->note === 'Phone does not match');
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $this->actingAs($owner->fresh())->get('/dhiran/loans')->assertRedirect(route('dhiran.pending'));
    }

    public function test_a_decided_shop_cannot_be_decided_again_and_bad_input_is_refused(): void
    {
        Mail::fake(); (new PlanSeeder)->run(); $this->signIn($this->admin());
        [$shop] = $this->waiting();
        // approval takes no fields: a bad posted plan or a past date is ignored, the shop still gets exactly the 1-year term
        $this->post('/admin/requests/'.$shop->id.'/approve', $this->approval(['ends_at' => today()->subDay()->toDateString(), 'plan' => 'nope']))->assertSessionHasNoErrors();
        $sub = $shop->fresh()->currentSubscription();
        $this->assertSame('dhiran_yearly', $sub->plan->code);
        $this->assertSame(today()->addYear()->toDateString(), $sub->ends_at->toDateString());
        $audits = PlatformAuditLog::count();
        $this->post('/admin/requests/'.$shop->id.'/reject', ['reason' => 'too late'])->assertSessionHas('error');
        $this->post('/admin/requests/'.$shop->id.'/approve', $this->approval())->assertSessionHas('error');
        $this->assertSame('active', $shop->fresh()->status);
        $this->assertSame($audits, PlatformAuditLog::count());
        Mail::assertSentCount(1);
    }

    public function test_an_admin_without_the_shops_permission_is_refused(): void
    {
        (new PlanSeeder)->run(); $this->limited(['can_view_audit_logs']);
        [$shop] = $this->waiting();
        $this->get('/admin/requests')->assertForbidden();
        $this->post('/admin/requests/'.$shop->id.'/approve', $this->approval())->assertForbidden();
        $this->post('/admin/requests/'.$shop->id.'/reject', ['reason' => 'x'])->assertForbidden();
        $this->assertSame('pending', $shop->fresh()->status);
    }

    public function test_a_shop_owner_cannot_reach_the_requests(): void
    {
        [$shop, $owner] = $this->waiting();
        $this->actingAs($owner)->post('/admin/requests/'.$shop->id.'/approve', $this->approval())->assertRedirect('/admin/login');
        $this->assertSame('pending', $shop->fresh()->status);
    }
}
