<?php
namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\ShopSubscription;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LapsedNoticeTest extends TestCase
{
    use RefreshDatabase;

    /** A shop without a valid term is blocked everywhere: "New loan" / "Add old loan" clicks land on the access-ended page that says why. */
    public function test_lapsed_shop_sees_why_new_loan_and_old_loan_are_blocked(): void
    {
        $shop = self::createDhiranShop(['name' => 'Lapsed shop']);
        TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]));
        ShopSubscription::where('shop_id', $shop->id)->update(['status' => 'cancelled']);
        $owner = self::userWithRole($shop, ['name' => 'O', 'email' => 'lapsed@example.test', 'password' => 'Lapsed-pass-12345']);

        foreach (['/dhiran/create', '/dhiran/old-loans/create'] as $url) {
            $this->actingAs($owner)->get($url)->assertRedirect(route('dhiran.expired'));
            $this->actingAs($owner)->followingRedirects()->get($url)->assertOk()->assertSee('Your access has ended');
        }
    }

    /** Alpine-rendered pages are not snapshot-safe: Back must refetch, not restore a snapshot (which threw "item is not defined"). */
    public function test_shop_pages_opt_out_of_turbo_snapshots(): void
    {
        $shop = self::createDhiranShop(['name' => 'Cache shop']);
        TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]));
        $owner = self::userWithRole($shop, ['name' => 'O', 'email' => 'cache@example.test', 'password' => 'Cache-pass-12345']);
        foreach (['/dhiran/loans', '/dhiran/create', '/dhiran/borrowers'] as $url) {
            $this->actingAs($owner)->get($url)->assertOk()->assertSee('<meta name="turbo-cache-control" content="no-cache">', false);
        }
    }
}
