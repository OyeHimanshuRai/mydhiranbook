<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The slip stuck on the bag or packet of pledged items kept in the locker. */
class PledgeTagTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';
    private $shop;
    private User $owner;
    private DhiranLoan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390022000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390022000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390022000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'o@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Tag', 'last_name' => 'Holder', 'mobile' => '9815610001']));
        $this->loan = TenantContext::runFor($this->shop->id, fn () => app(DhiranService::class)->createLoan($this->shop, $customer, [
            ['description' => 'Gold chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10.5, 'stone_weight' => 0.5, 'rate_per_gram_at_pledge' => 6000],
            ['description' => 'Silver anklet', 'metal_type' => 'silver', 'purity' => 925, 'gross_weight' => 40, 'rate_per_gram_at_pledge' => 90],
        ], ['principal_amount' => 30000, 'gold_rate_on_date' => 6000, 'silver_rate_on_date' => 90, 'created_by' => $this->owner->id]));
    }

    private function fetch(string $path)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(self::HOST . $path));
    }

    public function test_the_tag_carries_what_finds_and_matches_the_bag(): void
    {
        $html = $this->fetch("/dhiran/loans/{$this->loan->id}/tag")->assertOk()->getContent();

        foreach ([$this->loan->loan_number, 'Tag Holder', 'Gold chain', 'Silver anklet', 'Gold 22K', 'Silver 925', '10.500', '10.000', '40.000', 'Locker / rack no.', 'Packed by', '₹30,000.00'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('2 items', $html);
        $this->assertStringContainsString('size: 58mm auto', $html, 'bill size by default, not a full page');
        $this->assertStringContainsString('size: 80mm auto', $this->fetch("/dhiran/loans/{$this->loan->id}/tag?w=80")->getContent(), 'an 80 mm roll on request');
        $this->assertStringNotContainsString('9815610001', $html, 'the mobile number is not printed on a tag that sits on a bag');
    }

    public function test_a_released_item_is_no_longer_on_the_tag(): void
    {
        DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $this->loan->id)->where('description', 'Silver anklet')->update(['status' => 'released']);
        $html = $this->fetch("/dhiran/loans/{$this->loan->id}/tag")->getContent();

        $this->assertStringContainsString('Gold chain', $html);
        $this->assertStringNotContainsString('Silver anklet', $html);
        $this->assertStringContainsString('1 item', $html);
    }

    public function test_the_loan_page_has_the_button_and_another_shops_loan_is_not_found(): void
    {
        $page = $this->fetch("/dhiran/loans/{$this->loan->id}")->getContent();
        $this->assertStringContainsString('Item Tag', $page);
        $this->assertStringContainsString("/dhiran/loans/{$this->loan->id}/tag", $page);

        $other = self::createDhiranShop(['name' => 'Other Co', 'shop_type' => 'dhiran', 'phone' => '9390022001', 'owner_first_name' => 'X', 'owner_last_name' => 'Y',
            'owner_mobile' => '9390022001', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($other->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $other->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $intruder = User::create(['mobile_number' => '9390022001', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'x@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $other->id, 'role_id' => $role->id]);
        TenantContext::runFor($other->id, fn () => $this->actingAs($intruder)->get(self::HOST . "/dhiran/loans/{$this->loan->id}/tag"))->assertNotFound();
    }
}
