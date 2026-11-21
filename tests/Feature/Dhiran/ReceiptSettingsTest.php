<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://127.0.0.1:8787';

    private function shopWithOwner(string $mobile, bool $allPerms = true): array
    {
        $shop = self::createDhiranShop([
            'name' => 'Pawn ' . $mobile, 'shop_type' => 'dhiran', 'phone' => $mobile, 'address' => 'Addr ' . $mobile,
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $mobile,
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync($allPerms ? Permission::query()->pluck('id') : Permission::query()->where('name', 'dhiran.view')->pluck('id'));
        $user = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        return [$shop, $user];
    }

    private function loanWithPayment(Shop $shop): array
    {
        return TenantContext::runFor($shop->id, function () use ($shop) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'Rama', 'last_name' => 'Devi', 'address' => 'Gandhi Road', 'mobile' => '98' . fake()->unique()->numerify('########')]);
            $loan = app(DhiranService::class)->createLoan($shop, $c, [[
                'description' => 'Zebra Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'huid' => 'AB12CD', 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'tenure_months' => 12, 'terms_text' => 'Pay promptly.',
                'loan_date' => today()->toDateString(), 'created_by' => null]);
            $payment = app(DhiranService::class)->recordPayment($loan, 500.0, 'cash');

            return [$loan, $payment];
        });
    }

    private function hide(User $u, Shop $s, array $keys): void
    {
        TenantContext::runFor($s->id, fn () => DhiranSettings::getForShop($s->id)->update(['hidden_receipt_fields' => $keys]));
    }

    private function receiptHtml(User $u, Shop $s, string $path)
    {
        return TenantContext::runFor($s->id, fn () => $this->actingAs($u)->get(self::BASE . $path));
    }

    public function test_save_validates_and_stores_hidden_keys(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100001');
        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->patch(self::BASE . '/dhiran/settings/receipt', ['show' => ['pledge.title']]));
        $res->assertRedirect();
        $hidden = DhiranSettings::getForShop($shop->id)->hidden_receipt_fields;
        $this->assertNotContains('pledge.title', $hidden);
        $this->assertContains('pledge.shop_phone', $hidden);
        $this->assertNotContains('pledge.loan_number', $hidden);

        $bad = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->patchJson(self::BASE . '/dhiran/settings/receipt', ['show' => ['pledge.bogus']]));
        $bad->assertStatus(422);
        $ln = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->patchJson(self::BASE . '/dhiran/settings/receipt', ['show' => ['loan_number']]));
        $ln->assertStatus(422);
    }

    public function test_user_without_settings_permission_gets_403(): void
    {
        [$shop, $viewer] = $this->shopWithOwner('9390100002', false);
        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)->patch(self::BASE . '/dhiran/settings/receipt', ['show' => []]))->assertForbidden();
    }

    public function test_settings_page_lists_switches_and_locks_loan_number(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100003');
        $this->hide($owner, $shop, ['pledge.title']);
        $html = $this->receiptHtml($owner, $shop, '/dhiran/settings')->assertOk()->getContent();
        $this->assertStringContainsString('value="pledge.shop_name" checked', preg_replace('/\s+/', ' ', $html) ?: '') ;
        $this->assertDoesNotMatchRegularExpression('/value="pledge\.title"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/Loan number.*?checked disabled/s', $html);
    }

    public function test_hidden_fields_are_absent_and_visible_ones_present(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100004');
        [$loan, $payment] = $this->loanWithPayment($shop);

        $p = $this->receiptHtml($owner, $shop, "/dhiran/loans/{$loan->id}/receipt")->assertOk()->getContent();
        foreach (['Zebra Chain', 'AB12CD', 'Rama Devi', 'Pay promptly.', 'Principal Amount Disbursed', 'Pawn 9390100004', $loan->loan_number] as $s) {
            $this->assertStringContainsString($s, $p);
        }
        $this->hide($owner, $shop, ['pledge.item_description', 'pledge.item_huid', 'pledge.terms', 'pledge.customer_name', 'pledge.item_gross', 'pledge.summary_principal', 'pledge.shop_name']);
        $p = $this->receiptHtml($owner, $shop, "/dhiran/loans/{$loan->id}/receipt")->assertOk()->getContent();
        foreach (['Zebra Chain', 'AB12CD', 'Rama Devi', 'Pay promptly.', 'Principal Amount Disbursed', 'Gross wt', 'class="receipt-shop-name"'] as $s) {
            $this->assertStringNotContainsString($s, $p);
        }
        $this->assertStringContainsString($loan->loan_number, $p);
        $this->assertStringContainsString('Net wt', $p);
        $this->assertStringContainsString('Total Market Value', $p);

        $q = $this->receiptHtml($owner, $shop, "/dhiran/loans/{$loan->id}/payments/{$payment->id}/receipt")->assertOk()->getContent();
        $this->assertStringContainsString('Gandhi Road', $q);
        $this->hide($owner, $shop, ['payment.customer_address', 'payment.method', 'payment.balance_total']);
        $q = $this->receiptHtml($owner, $shop, "/dhiran/loans/{$loan->id}/payments/{$payment->id}/receipt")->assertOk()->getContent();
        foreach (['Gandhi Road', 'Method', 'Total Balance After Payment'] as $s) {
            $this->assertStringNotContainsString($s, $q);
        }
        $this->assertStringContainsString('Amount Paid', $q);
        $this->assertStringContainsString($loan->loan_number, $q);
    }

    public function test_everything_off_still_renders_clean_structure(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100005');
        [$loan, $payment] = $this->loanWithPayment($shop);
        $this->hide($owner, $shop, DhiranSettings::receiptKeys());

        foreach (["/dhiran/loans/{$loan->id}/receipt", "/dhiran/loans/{$loan->id}/payments/{$payment->id}/receipt"] as $path) {
            $html = $this->receiptHtml($owner, $shop, $path)->assertOk()->getContent();
            $this->assertStringContainsString($loan->loan_number, $html);
            $this->assertStringNotContainsString('<table', $html);
            $this->assertStringNotContainsString('<h2', $html, 'no section heading without content');
            $this->assertStringNotContainsString('<header', $html);
            $this->assertStringNotContainsString('rc-sigs', substr($html, strpos($html, '<body')));
            $this->assertSame(1, substr_count($html, '<dd>'), 'only the loan number row');
        }
    }

    public function test_totals_row_adjusts_when_columns_drop(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100006');
        [$loan] = $this->loanWithPayment($shop);
        $this->hide($owner, $shop, ['pledge.item_description', 'pledge.item_metal', 'pledge.item_gross', 'pledge.item_net', 'pledge.item_purity', 'pledge.item_fine']);
        $html = $this->receiptHtml($owner, $shop, "/dhiran/loans/{$loan->id}/receipt")->getContent();
        $this->assertStringContainsString('<td colspan="1">Total</td>', $html);
        $this->hide($owner, $shop, ['pledge.item_value', 'pledge.item_description', 'pledge.item_metal', 'pledge.item_gross', 'pledge.item_net', 'pledge.item_purity', 'pledge.item_fine']);
        $this->assertStringNotContainsString('<table', $this->receiptHtml($owner, $shop, "/dhiran/loans/{$loan->id}/receipt")->getContent());
    }

    public function test_one_shops_settings_do_not_touch_another(): void
    {
        [$a, $ownerA] = $this->shopWithOwner('9390100007');
        [$b, $ownerB] = $this->shopWithOwner('9390100008');
        [$loanB] = $this->loanWithPayment($b);
        TenantContext::runFor($a->id, fn () => $this->actingAs($ownerA)->patch(self::BASE . '/dhiran/settings/receipt', ['show' => []]));
        $this->assertSame([], DhiranSettings::getForShop($b->id)->hidden_receipt_fields ?? []);
        $this->receiptHtml($ownerB, $b, "/dhiran/loans/{$loanB->id}/receipt")->assertSee('Zebra Chain');
    }
}
