<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Admin\AdminTestCase;

/** "Refer a jeweller": the shop's own link, and only its own referrals (name, date, state; no contact data). Plus the admin's view of it. */
class ReferralTest extends AdminTestCase
{
    private int $n = 0;

    private function liveShop(string $name, array $over = []): Shop
    {
        $shop = self::createDhiranShop(['name' => $name, 'phone' => '9000011'.++$this->n.'00', 'address' => $name.' street'] + $over);
        TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]));

        return $shop;
    }

    private function ownerOf(Shop $shop): User
    {
        return self::userWithRole($shop, ['name' => 'Owner of '.$shop->name, 'email' => 'ref'.$shop->id.'@example.test', 'password' => 'Refer-pass-12345', 'mobile_number' => '9811100'.str_pad((string) $shop->id, 3, '0', STR_PAD_LEFT)]);
    }

    public function test_the_page_shows_the_own_link_steps_and_only_safe_columns_of_own_referrals(): void
    {
        $a = $this->liveShop('Alpha Jewels');
        $b = Shop::create(['name' => 'Bravo Approved', 'status' => 'active', 'phone' => '9222233333', 'address' => 'Secret Lane 9', 'referred_by_shop_id' => $a->id]);
        Shop::create(['name' => 'Charlie Waiting', 'status' => 'pending', 'referred_by_shop_id' => $a->id]);
        Shop::create(['name' => 'Delta Turned Down', 'status' => 'rejected', 'review_note' => 'Fake shop reason', 'referred_by_shop_id' => $a->id]);
        $other = $this->liveShop('Echo Other');
        Shop::create(['name' => 'Foxtrot Elsewhere', 'status' => 'active', 'referred_by_shop_id' => $other->id]);

        $this->actingAs($this->ownerOf($a))->get(route('dhiran.referrals'))->assertOk()
            ->assertSee('Refer a jeweller')->assertSee(url('/register?ref='.$a->fresh()->referral_code))->assertSee('dh-ref-copy', false)
            ->assertSee('Share your link')->assertSee('Bravo Approved')->assertSee('Approved')->assertSee('Charlie Waiting')->assertSee('Waiting')
            ->assertSee('Delta Turned Down')->assertSee('Not approved')->assertSee('1 approved')
            ->assertDontSee('Foxtrot Elsewhere')->assertDontSee('Echo Other')->assertDontSee($other->fresh()->referral_code)
            ->assertDontSee('9222233333')->assertDontSee('Secret Lane 9')->assertDontSee('Fake shop reason');
    }

    public function test_another_shop_sees_only_its_own_and_an_empty_list_when_none(): void
    {
        $a = $this->liveShop('Alpha Jewels');
        Shop::create(['name' => 'Bravo Approved', 'status' => 'active', 'referred_by_shop_id' => $a->id]);
        $e = $this->liveShop('Echo Other');
        $this->actingAs($this->ownerOf($e))->get(route('dhiran.referrals'))->assertOk()->assertSee('No shop has come through your link yet.')
            ->assertDontSee('Bravo Approved')->assertDontSee($a->fresh()->referral_code)->assertSee($e->fresh()->referral_code);
    }

    public function test_guests_and_people_without_a_shop_cannot_open_it(): void
    {
        $this->get(route('dhiran.referrals'))->assertRedirect(route('login'));
        $lone = User::create(['name' => 'Lone', 'email' => 'lone@example.test', 'password' => 'Refer-pass-12345', 'mobile_number' => '9811199999']);
        $this->actingAs($lone)->get(route('dhiran.referrals'))->assertRedirect();
    }

    public function test_the_sidebar_links_to_it(): void
    {
        $a = $this->liveShop('Alpha Jewels');
        $this->actingAs($this->ownerOf($a))->get('/dhiran/loans')->assertSee('href="'.route('dhiran.referrals').'"', false)->assertSee('Refer a jeweller');
    }

    public function test_admin_requests_show_the_referrer_shop_as_a_link_or_the_typed_text_escaped(): void
    {
        $a = $this->liveShop('Alpha Jewels');
        Shop::create(['name' => 'Via Shop', 'status' => 'pending', 'referred_by_shop_id' => $a->id]);
        Shop::create(['name' => 'Via Text', 'status' => 'pending', 'referred_by_text' => '<i>Ramesh</i> 98765']);
        Shop::create(['name' => 'Via Nobody', 'status' => 'pending']);
        $this->signIn($this->admin());
        $this->get('/admin/requests')->assertOk()->assertSee('Referred by')
            ->assertSee('<a href="/admin/shops/'.$a->id.'">Alpha Jewels</a>', false)->assertSee('&lt;i&gt;Ramesh&lt;/i&gt; 98765', false)->assertDontSee('<i>Ramesh</i>', false);
    }

    public function test_admin_shop_page_shows_who_referred_it_and_how_many_approved_it_referred(): void
    {
        $a = $this->liveShop('Alpha Jewels');
        $b = Shop::create(['name' => 'Bravo Approved', 'status' => 'active', 'referred_by_shop_id' => $a->id]);
        Shop::create(['name' => 'Charlie Waiting', 'status' => 'pending', 'referred_by_shop_id' => $a->id]);
        $this->signIn($this->admin());
        $this->get('/admin/shops/'.$a->id)->assertOk()->assertSee('1 approved shop');
        $this->get('/admin/shops/'.$b->id)->assertOk()->assertSee('<a href="/admin/shops/'.$a->id.'">Alpha Jewels</a>', false)->assertSee('0 approved shops');
    }
}
