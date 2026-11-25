<?php
namespace Tests\Feature\Billing;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\User;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\BillingFixtures;

/** Rules: a queued early renewal never hides or rewrites the started term; lapsed shops get an honest staff message. */
class QueuedRenewalTest extends AdminTestCase
{
    use BillingFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); }

    private function term(Shop $shop, string $start, string $end, string $status = 'active'): ShopSubscription
    {
        $p = $this->plan('dhiran_yearly');
        return ShopSubscription::create(['shop_id' => $shop->id, 'plan_id' => $p->id, 'status' => $status, 'billing_cycle' => 'yearly', 'starts_at' => $start, 'ends_at' => $end,
            'grace_ends_at' => date('Y-m-d', strtotime($end.' +14 days')), 'actor_type' => 'system']);
    }

    private function grant(Shop $shop, string $endsAt)
    {
        return $this->post('/admin/shops/'.$shop->id.'/subscription', ['plan' => 'dhiran_yearly', 'billing_cycle' => 'yearly', 'ends_at' => $endsAt, 'reason' => 'Goodwill']);
    }

    public function test_plan_page_shows_the_started_term_not_the_queued_one(): void
    {
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, today()->subMonths(11)->toDateString(), today()->addDays(30)->toDateString());
        $this->term($shop, today()->addDays(30)->toDateString(), today()->addDays(395)->toDateString());
        $html = $this->actingAs($owner)->get('/subscription/status')->assertOk()->getContent();
        $this->assertStringContainsString('st-badge--warn">Ends in 30 days<', $html);
        $this->assertStringContainsString('Access until', $html);
        $this->assertStringContainsString(today()->addDays(30)->format('d M Y'), $html);
        $this->assertStringNotContainsString(today()->addDays(395)->format('d M Y'), $html);
    }

    public function test_cancelled_queued_row_does_not_shadow_the_live_term_on_plan_page(): void
    {
        $owner = $this->shopUser(); $shop = $owner->shop;
        $live = $this->term($shop, today()->subMonths(2)->toDateString(), today()->addMonths(10)->toDateString());
        $this->term($shop, today()->addMonths(10)->toDateString(), today()->addMonths(22)->toDateString(), 'cancelled');
        $html = $this->actingAs($owner)->get('/subscription/status')->assertOk()->getContent();
        $this->assertStringContainsString('st-badge--ok">Active<', $html);
        $this->assertStringContainsString($live->ends_at->format('d M Y'), $html);
        $this->assertStringNotContainsString('Renewal queued', $html);
    }

    public function test_admin_grant_refused_while_a_renewal_is_queued_and_terminate_still_works(): void
    {
        $this->signIn($this->admin()); $shop = Shop::create(['name' => 'Queued']);
        $old = $this->term($shop, today()->subMonths(11)->toDateString(), today()->addDays(20)->toDateString());
        $new = $this->term($shop, today()->addDays(20)->toDateString(), today()->addDays(385)->toDateString());
        $snap = [$old->fresh()->only('ends_at', 'plan_id'), $new->fresh()->only('ends_at', 'plan_id')];
        $this->grant($shop, today()->addDays(500)->toDateString())->assertSessionHasErrors('ends_at');
        $this->assertSame(0, SubscriptionEvent::where('shop_id', $shop->id)->count());
        $this->assertEquals($snap, [$old->fresh()->only('ends_at', 'plan_id'), $new->fresh()->only('ends_at', 'plan_id')]);
        $this->post('/admin/shops/'.$shop->id.'/subscription/terminate', ['reason' => 'Fraud'])->assertRedirect();
        $this->assertSame(['cancelled', 'cancelled'], ShopSubscription::where('shop_id', $shop->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_lapsed_shop_staff_screens_say_plan_ended_not_negative_limit(): void
    {
        $owner = $this->shopUser(); $shop = $owner->shop;
        $this->term($shop, today()->subYears(2)->toDateString(), today()->subYear()->toDateString());
        $this->assertTrue($shop->fresh()->isLapsed());
        // the whole shop is blocked, so there is no staff screen to carry a message: every staff page and write lands on the access-ended page
        $before = User::where('shop_id', $shop->id)->count();
        $this->actingAs($owner)->get('/staff/create')->assertRedirect(route('dhiran.expired'));
        $this->post('/staff', ['name' => 'X', 'mobile_number' => '9000000099', 'password' => 'Synthetic-pass-12345', 'password_confirmation' => 'Synthetic-pass-12345', 'role_id' => 1])->assertRedirect(route('dhiran.expired'));
        $this->assertSame($before, User::where('shop_id', $shop->id)->count());
        $this->actingAs($owner)->get(route('dhiran.expired'))->assertOk()->assertSee('Your access has ended');
    }
}
