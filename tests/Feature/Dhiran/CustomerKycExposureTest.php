<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** (PAN differs from the form's ABCDE1234F placeholder, which is legitimately in the page.) The New Loan page must not carry any borrower's PAN / ID number; one borrower's KYC is fetched on selection. */
class CustomerKycExposureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://127.0.0.1:8787';

    private int $n = 0;

    private function shop(): Shop
    {
        $m = '93903' . str_pad((string) ++$this->n, 5, '0', STR_PAD_LEFT);
        $shop = self::createDhiranShop([
            'name' => 'Pawn ' . $m, 'shop_type' => 'dhiran', 'phone' => $m,
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $m,
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);

        return $shop;
    }

    private function userWith(Shop $shop, array $permissions): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'custom' . ++$this->n, 'display_name' => 'Custom', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::create(['mobile_number' => '97' . fake()->unique()->numerify('########'), 'password' => bcrypt('x'), 'realm' => 'dhiran', 'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
    }

    private function customer(Shop $shop, string $first, string $mobile): Customer
    {
        return TenantContext::runFor($shop->id, fn () => Customer::create([
            'shop_id' => $shop->id, 'first_name' => $first, 'mobile' => $mobile,
            'pan' => 'QWERT9876Z', 'id_number' => 'ID-SECRET-77',
        ]));
    }

    private function visit(User $u, string $path)
    {
        return TenantContext::runFor($u->shop_id, fn () => $this->actingAs($u)->get(self::BASE . $path));
    }

    public function test_new_loan_page_source_has_no_pan_or_id_number(): void
    {
        $shop = $this->shop();
        $user = $this->userWith($shop, ['dhiran.view', 'dhiran.create']);
        $this->customer($shop, 'Asha', '9100000001');
        $this->customer($shop, 'Bina', '9100000002');

        $html = $this->visit($user, '/dhiran/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('Asha', $html); // the borrower book is searched on typing, never embedded
        $this->assertStringNotContainsString('9100000001', $html);
        $this->assertStringNotContainsString('QWERT9876Z', $html);
        $this->assertStringNotContainsString('ID-SECRET-77', $html);
    }

    public function test_kyc_endpoint_returns_only_pan_and_id_for_own_shop(): void
    {
        $shop = $this->shop();
        $user = $this->userWith($shop, ['dhiran.view', 'dhiran.create']);
        $c = $this->customer($shop, 'Asha', '9100000001');

        $this->visit($user, "/dhiran/borrowers/{$c->id}/kyc")
            ->assertOk()
            ->assertExactJson(['pan' => 'QWERT9876Z', 'id_number' => 'ID-SECRET-77'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_kyc_endpoint_is_404_for_another_shops_customer(): void
    {
        $mine = $this->shop();
        $other = $this->shop();
        $user = $this->userWith($mine, ['dhiran.view', 'dhiran.create']);
        $theirs = $this->customer($other, 'Zed', '9100000003');

        $this->visit($user, "/dhiran/borrowers/{$theirs->id}/kyc")->assertNotFound();
    }

    public function test_kyc_endpoint_needs_dhiran_create(): void
    {
        $shop = $this->shop();
        $viewer = $this->userWith($shop, ['dhiran.view']);
        $c = $this->customer($shop, 'Asha', '9100000001');

        $this->visit($viewer, "/dhiran/borrowers/{$c->id}/kyc")->assertForbidden();
    }

    public function test_kyc_endpoint_redirects_guests_to_login(): void
    {
        $c = $this->customer($this->shop(), 'Asha', '9100000001');

        $this->get(self::BASE . "/dhiran/borrowers/{$c->id}/kyc")->assertRedirect('/login');
    }
}
