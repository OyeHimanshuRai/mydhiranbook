<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** New Loan borrower picker: server search, this shop only, active only, 8 at most, throttled; the page carries no borrower book. */
class BorrowerSearchTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';
    private $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->shop, $this->owner] = $this->makeShop('9390017000', 'owner', true);
    }

    private function makeShop(string $mobile, string $roleName, bool $allPermissions): array
    {
        $shop = self::createDhiranShop([
            'name' => 'Shop ' . $mobile, 'shop_type' => 'dhiran', 'phone' => $mobile, 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => $mobile, 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => $roleName, 'display_name' => $roleName, 'shop_id' => $shop->id])->save();
        $role->permissions()->sync($allPermissions ? Permission::query()->pluck('id') : Permission::where('name', 'dhiran.view')->pluck('id'));
        $user = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => $mobile . '@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        return [$shop, $user];
    }

    private function borrower($shop, string $first, string $mobile, array $more = []): Customer
    {
        return TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => $first, 'last_name' => 'Test', 'mobile' => $mobile] + $more));
    }

    private function search(string $q, ?User $as = null)
    {
        $as ??= $this->owner;

        return TenantContext::runFor($as->shop_id, fn () => $this->actingAs($as)->getJson(self::HOST . '/dhiran/borrowers/search?q=' . urlencode($q)));
    }

    public function test_finds_by_name_mobile_and_address_and_returns_no_kyc(): void
    {
        $this->borrower($this->shop, 'Meena', '9811100001', ['address' => 'Lane 4 Pune', 'pan' => 'ABCDE1234F', 'id_number' => '123412341234']);

        foreach (['meen', '98111000', 'lane 4'] as $q) {
            $r = $this->search($q)->assertOk();
            $this->assertSame('Meena Test', $r->json('0.name'), $q);
        }
        $this->assertStringContainsString('no-store', (string) $this->search('meen')->headers->get('Cache-Control'));
        $this->assertSame(['id', 'name', 'mobile', 'address', 'state_code', 'photo_url'], array_keys($this->search('meen')->json('0')));
        $this->assertStringNotContainsString('ABCDE1234F', $this->search('meen')->getContent());
    }

    public function test_never_returns_another_shops_or_an_archived_borrower(): void
    {
        [$other] = $this->makeShop('9390017001', 'owner', true);
        $this->borrower($other, 'Zara', '9822200001');
        $gone = $this->borrower($this->shop, 'Zubin', '9822200002');
        $gone->forceFill(['is_active' => false])->save();
        $this->borrower($this->shop, 'Zoya', '9822200003');

        $names = array_column($this->search('z')->json() ?: [], 'name');
        $this->assertSame([], $names, 'one letter is not enough');
        $names = array_column($this->search('Z')->json() ?: [], 'name');
        $this->assertSame([], $names);

        $names = array_column($this->search('Zo')->json(), 'name');
        $this->assertSame(['Zoya Test'], $names);
        $this->assertSame([], $this->search('Zara')->json());
        $this->assertSame([], $this->search('Zubin')->json());
    }

    public function test_short_or_wildcard_input_lists_nothing_and_results_are_capped(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->borrower($this->shop, 'Cap' . sprintf('%02d', $i), '98333000' . sprintf('%02d', $i));
        }
        $this->assertSame([], $this->search('')->json());
        $this->assertSame([], $this->search('%%')->json(), 'a wildcard is searched as text, not as a pattern');
        $this->assertSame([], $this->search('__')->json());
        $this->assertCount(8, $this->search('Cap')->json());
    }

    public function test_needs_the_create_permission_and_is_throttled(): void
    {
        [, $viewer] = $this->makeShop('9390017002', 'viewer', false);
        $this->search('meen', $viewer)->assertForbidden();

        for ($i = 0; $i < 60; $i++) {
            $this->search('me')->assertOk();
        }
        $this->search('me')->assertStatus(429);
    }

    public function test_new_loan_page_carries_no_borrower_book_only_the_preselected_one(): void
    {
        $a = $this->borrower($this->shop, 'Hidden', '9844400001', ['address' => 'Secret Road 9']);
        $b = $this->borrower($this->shop, 'Chosen', '9844400002');
        [$other] = $this->makeShop('9390017003', 'owner', true);
        $x = $this->borrower($other, 'Foreign', '9844400003');
        $page = fn (string $qs) => TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(self::HOST . '/dhiran/create' . $qs)->getContent());

        $html = $page('');
        foreach (['Hidden', '9844400001', 'Secret Road 9', 'Chosen', 'Foreign'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "$leak must not ride in the page");
        }
        $this->assertStringContainsString('preselected: null', $html);

        $html = $page('?customer_id=' . $b->id);
        $this->assertStringContainsString('Chosen', $html);
        $this->assertStringNotContainsString('Hidden', $html);

        $this->assertStringNotContainsString('Foreign', $page('?customer_id=' . $x->id), "another shop's borrower is never preselected");
    }
}
