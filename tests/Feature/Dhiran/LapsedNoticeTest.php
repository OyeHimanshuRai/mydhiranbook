<?php
namespace Tests\Feature\Dhiran;

use App\Http\Middleware\EnsureDhiranEnabled;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\ShopSubscription;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LapsedNoticeTest extends TestCase
{
    use RefreshDatabase;

    /** A blocked "New loan" / "Add old loan" click must say why, not silently land on the loans list. */
    public function test_lapsed_shop_sees_why_new_loan_and_old_loan_are_blocked(): void
    {
        $shop = self::createDhiranShop(['name' => 'Lapsed shop']);
        TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]));
        ShopSubscription::where('shop_id', $shop->id)->update(['status' => 'cancelled']);
        $owner = self::userWithRole($shop, ['name' => 'O', 'email' => 'lapsed@example.test', 'password' => 'Lapsed-pass-12345']);

        foreach (['/dhiran/create', '/dhiran/old-loans/create'] as $url) {
            $this->actingAs($owner)->followingRedirects()->get($url)
                ->assertOk()->assertSee('Pledge Loans')->assertSee(EnsureDhiranEnabled::LAPSED);
        }
    }
}
