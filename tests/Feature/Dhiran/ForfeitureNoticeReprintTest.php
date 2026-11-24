<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** A forfeiture notice is a legal letter: printing it again later must not change the day it was issued or the deadline it gave. */
class ForfeitureNoticeReprintTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_reprint_keeps_the_issue_date_and_the_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00', 'Asia/Kolkata'));
        $shop = self::createDhiranShop(['name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390024000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390024000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0, 'forfeiture_notice_days' => 30]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create(['mobile_number' => '9390024000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'o@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
        $svc = app(DhiranService::class);
        $customer = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'Late', 'last_name' => 'Payer', 'mobile' => '9815630001']));
        $loan = TenantContext::runFor($shop->id, fn () => $svc->createLoan($shop, $customer, [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000]],
            ['principal_amount' => 50000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 1, 'grace_period_days' => 5, 'loan_date' => '2026-01-01', 'created_by' => $owner->id]));

        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00', 'Asia/Kolkata'));   // long past maturity and grace
        $this->actingAs($owner);
        TenantContext::runFor($shop->id, fn () => $svc->sendForfeitureNotice(DhiranLoan::withoutGlobalScopes()->findOrFail($loan->id)));

        Carbon::setTestNow(Carbon::parse('2026-03-11 16:00', 'Asia/Kolkata'));   // printed again ten days later
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get("http://127.0.0.1:8787/dhiran/loans/{$loan->id}/forfeiture-notice"))->assertOk()->getContent();

        $this->assertStringContainsString('Date: 01 Mar 2026', $html, 'the day the notice was issued, not the day it was printed');
        $this->assertStringNotContainsString('Date: 11 Mar 2026', $html);
        $this->assertStringContainsString('by 31 Mar 2026', $html, '30 days from the notice, not from the reprint');
    }
}
