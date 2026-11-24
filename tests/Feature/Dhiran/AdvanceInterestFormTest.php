<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Advance interest through the screens: the owner's switch, the New Loan choice, and the server refusing what the shop has not switched on. */
class AdvanceInterestFormTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';
    private $shop;
    private User $owner;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390019000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390019000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390019000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'o@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Adv', 'last_name' => 'Form', 'mobile' => '9815590001']));
    }

    private function as(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, fn () => $fn($this->actingAs($this->owner)));
    }

    private function settingsForm(array $over = []): array
    {
        return $over + [
            'default_interest_rate_monthly' => 2, 'default_interest_type' => 'flat', 'default_penalty_rate_monthly' => 0, 'default_min_interest_months' => 1,
            'default_ltv_ratio' => 75, 'high_value_threshold' => 200000, 'high_value_ltv_ratio' => 65, 'min_loan_amount' => 1000, 'max_loan_amount' => 900000,
            'processing_fee_type' => 'flat', 'processing_fee_value' => 0, 'default_tenure_months' => 6, 'default_min_lock_months' => 0,
            'grace_period_days' => 30, 'forfeiture_notice_days' => 15, 'loan_number_prefix' => 'DH-',
        ];
    }

    private function loanForm(array $over = []): array
    {
        return $over + ['customer_id' => $this->customer->id, 'principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2,
            'interest_type' => 'flat', 'tenure_months' => 6, 'idempotency_key' => 'adv-' . uniqid(),
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 24, 'gross_weight' => 100, 'rate_per_gram_at_pledge' => 6000]]];
    }

    private function enable(int $months = 1): void
    {
        DhiranSettings::getForShop($this->shop->id)->update(['advance_interest_enabled' => true, 'advance_interest_default_months' => $months]);
    }

    public function test_owner_switches_it_on_and_off_in_loan_settings(): void
    {
        $this->as(fn ($t) => $t->patch(self::HOST . '/dhiran/settings', $this->settingsForm(['advance_interest_enabled' => '1', 'advance_interest_default_months' => 2])))->assertSessionHasNoErrors();
        $s = DhiranSettings::getForShop($this->shop->id)->fresh();
        $this->assertTrue((bool) $s->advance_interest_enabled);
        $this->assertSame(2, (int) $s->advance_interest_default_months);

        $this->as(fn ($t) => $t->patch(self::HOST . '/dhiran/settings', $this->settingsForm()))->assertSessionHasNoErrors();
        $this->assertFalse((bool) DhiranSettings::getForShop($this->shop->id)->fresh()->advance_interest_enabled, 'an unchecked switch posts nothing');

        $this->as(fn ($t) => $t->patch(self::HOST . '/dhiran/settings', $this->settingsForm(['advance_interest_default_months' => 4])))->assertSessionHasErrors('advance_interest_default_months');
    }

    public function test_the_choice_is_on_new_loan_only_when_the_shop_switched_it_on(): void
    {
        $html = $this->as(fn ($t) => $t->get(self::HOST . '/dhiran/create'))->getContent();
        $this->assertStringNotContainsString('name="advance_interest_months"', $html);
        $this->assertStringNotContainsString('Cash to hand over', $html);

        $this->enable(2);
        $html = $this->as(fn ($t) => $t->get(self::HOST . '/dhiran/create'))->getContent();
        $this->assertStringContainsString('name="advance_interest_months"', $html);
        $this->assertStringContainsString('First month taken now', $html);
        $this->assertStringContainsString('Cash to hand over', $html);
        $this->assertStringContainsString('advance_interest_months: 2,', $html, 'the shop default is preselected');
    }

    public function test_new_loan_with_the_advance_books_it_and_without_does_not(): void
    {
        $this->enable();
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm(['advance_interest_months' => 1])))->assertSessionHasNoErrors();
        $l = DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->first();
        $this->assertSame(1, (int) $l->advance_interest_months);
        $this->assertEquals(2000.00, (float) $l->interest_prepaid);

        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm(['advance_interest_months' => 0])));
        $this->assertEquals(0.00, (float) DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->orderByDesc('id')->first()->interest_prepaid);
    }

    public function test_the_server_refuses_it_when_the_shop_has_not_switched_it_on(): void
    {
        $r = $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm(['advance_interest_months' => 1])));
        $r->assertSessionHasErrors(['loan' => 'Advance interest is not switched on for this shop.']);
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());

        $this->enable();
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm(['advance_interest_months' => 4])))->assertSessionHasErrors('advance_interest_months');
    }

    public function test_the_pledge_receipt_prints_the_advance_and_the_cash_handed_over(): void
    {
        $this->enable();
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm(['advance_interest_months' => 1])));
        $l = DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->first();

        $html = $this->as(fn ($t) => $t->get(self::HOST . "/dhiran/loans/{$l->id}/receipt"))->getContent();
        $this->assertStringContainsString('Advance Interest (1 month)', $html);
        $this->assertStringContainsString('2,000.00', $html);
        $this->assertStringContainsString('Cash Handed to Borrower', $html);
        $this->assertStringContainsString('98,000.00', $html);

        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm(['advance_interest_months' => 0])));
        $plain = DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->orderByDesc('id')->first();
        $html = $this->as(fn ($t) => $t->get(self::HOST . "/dhiran/loans/{$plain->id}/receipt"))->getContent();
        $this->assertStringNotContainsString('Advance Interest', $html);
        $this->assertStringNotContainsString('Cash Handed to Borrower', $html);
    }
}
