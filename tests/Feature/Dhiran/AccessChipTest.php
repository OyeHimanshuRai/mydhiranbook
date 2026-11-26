<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sidebar access chip (outline states by days left) and the staff / pledged-item usage meters. */
class AccessChipTest extends TestCase
{
    use RefreshDatabase;

    private function owner(int $daysLeft): User
    {
        static $n = 0; $n++;
        $shop = self::createDhiranShop(['name' => "Chip shop $n"]);
        TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]));
        $end = today()->addDays($daysLeft);
        ShopSubscription::where('shop_id', $shop->id)->update(['ends_at' => $end, 'grace_ends_at' => $end]);

        return self::userWithRole($shop, ['name' => 'O', 'email' => "chip$n@example.test", 'password' => 'Chip-pass-12345']);
    }

    public function test_far_away_end_shows_date_and_days_left_without_icon_and_links_to_the_access_page(): void
    {
        $owner = $this->owner(312);
        $res = $this->actingAs($owner)->get('/dhiran/loans')->assertOk();
        $res->assertSee('Access until '.today()->addDays(312)->format('j M Y'))->assertSee('312 days left')
            ->assertSee('dh-access dh-access--ok', false)->assertSee('href="'.route('subscription.status').'"', false);
        $this->assertStringNotContainsString('dh-access--warn', $res->getContent());
    }

    public function test_each_state_has_its_own_class_and_wording(): void
    {
        $this->actingAs($this->owner(45))->get('/dhiran/loans')->assertSee('dh-access--soon', false)->assertSee('45 days left');
        $this->actingAs($this->owner(30))->get('/dhiran/loans')->assertSee('dh-access--warn', false)->assertSee('30 days left');
        $this->actingAs($this->owner(8))->get('/dhiran/loans')->assertSee('dh-access--warn', false);
        $this->actingAs($this->owner(7))->get('/dhiran/loans')->assertSee('dh-access--danger', false)->assertSee('Ends in 7 days');
        $this->actingAs($this->owner(1))->get('/dhiran/loans')->assertSee('Ends in 1 day')->assertDontSee('Ends in 1 days');
        $this->actingAs($this->owner(0))->get('/dhiran/loans')->assertSee('Ends today');
    }

    public function test_no_term_renders_no_chip(): void
    {
        $shop = Shop::create(['name' => 'No term']);
        $u = self::userWithRole($shop, ['name' => 'O', 'email' => 'noterm@example.test', 'password' => 'Chip-pass-12345']);
        $this->actingAs($u);
        $this->assertSame('', trim(view('components.dhiran.access-chip')->render()));
    }

    public function test_staff_meter_is_a_labelled_meter_and_hidden_when_unlimited(): void
    {
        $owner = $this->owner(200);
        $this->actingAs($owner)->get(route('settings.edit', ['tab' => 'staff']))->assertOk()
            ->assertSee('role="meter"', false)->assertSee('aria-valuemax="7"', false)->assertSee('aria-valuenow="0"', false)
            ->assertSee('0 of 7 staff accounts used');

        $plan = Plan::where('code', 'dhiran_yearly')->firstOrFail();
        $plan->update(['features' => ['staff_limit' => -1] + $plan->features]);
        $this->actingAs($owner->fresh())->get(route('settings.edit', ['tab' => 'staff']))->assertOk()->assertDontSee('role="meter"', false);
    }

    /** A shop whose pledged-item count is stubbed, so the test needs no loan rows. */
    private function shopHolding(User $owner, int $items): void
    {
        $stub = new class extends Shop {
            protected $table = 'shops';
            public int $held = 0;
            public function getForeignKey() { return 'shop_id'; } // relations are keyed by class name otherwise
            public function pledgedItemCount(): int { return $this->held; }
        };
        $stub->setRawAttributes($owner->shop->getAttributes(), true);
        $stub->exists = true;
        $stub->held = $items;
        $owner->setRelation('shop', $stub);
    }

    public function test_item_hint_shows_from_80_percent_of_the_item_limit_only(): void
    {
        $owner = $this->owner(200);
        $plan = Plan::where('code', 'dhiran_yearly')->firstOrFail();
        $plan->update(['features' => ['max_items' => 100] + $plan->features]);

        $this->shopHolding($owner, 79);
        $this->actingAs($owner)->get('/dhiran/create')->assertOk()->assertDontSee('Pledged items: 79');
        $this->shopHolding($owner, 85);
        $this->actingAs($owner)->get('/dhiran/create')->assertOk()->assertSee('Pledged items: 85 of 100 (this access term)');

        $plan->update(['features' => ['max_items' => -1] + $plan->features]);
        $this->shopHolding($owner, 5000);
        $this->actingAs($owner)->get('/dhiran/create')->assertOk()->assertDontSee('Pledged items: 5000');
    }
}
