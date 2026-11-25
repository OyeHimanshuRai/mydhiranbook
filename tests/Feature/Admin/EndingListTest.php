<?php
namespace Tests\Feature\Admin;

use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Database\Seeders\PlanSeeder;

/** The dashboard lists approved shops whose year ends within 30 days or has ended, each with an Extend button. */
class EndingListTest extends AdminTestCase
{
    private function shopEnding(string $name, ?int $daysFromToday): Shop
    {
        $shop = Shop::create(['name' => $name, 'status' => 'active']);
        if ($daysFromToday !== null) {
            ShopSubscription::create(['shop_id' => $shop->id, 'plan_id' => \App\Models\Platform\Plan::where('code', 'dhiran_yearly')->value('id'), 'status' => 'active', 'billing_cycle' => 'yearly',
                'starts_at' => today()->subYear(), 'ends_at' => today()->addDays($daysFromToday), 'grace_ends_at' => today()->addDays($daysFromToday)]);
        }

        return $shop;
    }

    public function test_the_dashboard_lists_only_shops_ending_within_30_days_ended_or_without_a_term(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin());
        $this->shopEnding('Ends In Ten', 10); $this->shopEnding('Ended Long Ago', -90); $this->shopEnding('No Term Yet', null);
        $this->shopEnding('Safe For A Year', 200);
        Shop::create(['name' => 'Waiting Shop', 'status' => 'pending']);
        Shop::create(['name' => 'Suspended Shop', 'status' => 'suspended']);
        $page = $this->get('/admin')->assertOk();
        $page->assertSee('Ends In Ten')->assertSee('Ends in 10 d')->assertSee('Ended Long Ago')->assertSee('Ended 90 d ago')->assertSee('No Term Yet')->assertSee('No access term')
            ->assertDontSee('Safe For A Year')->assertDontSee('Waiting Shop')->assertDontSee('Suspended Shop')->assertSee('Extend 1 year');
    }

    public function test_the_shop_page_offers_extend_only_to_admins_who_may_and_only_for_an_approved_shop(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin());
        $live = $this->shopEnding('Live Shop', 5); $susp = Shop::create(['name' => 'Suspended Shop', 'status' => 'suspended']);
        $this->get('/admin/shops/'.$live->id)->assertOk()->assertSee('Extend by one year');
        $this->get('/admin/shops/'.$susp->id)->assertOk()->assertDontSee('Extend by one year');
    }
}
