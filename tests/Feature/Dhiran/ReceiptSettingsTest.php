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

    /** The <input> tag that carries show[] = $value, whitespace collapsed. */
    private function switchTag(string $html, string $value): string
    {
        preg_match('/<input [^>]*value="' . preg_quote($value, '/') . '"[^>]*>/s', $html, $m);

        return preg_replace('/\s+/', ' ', $m[0] ?? '');
    }

    public function test_settings_page_lists_every_switch_with_its_state_and_a_name(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100003');
        $this->hide($owner, $shop, ['pledge.title']);
        $html = $this->receiptHtml($owner, $shop, '/dhiran/settings/receipt')->assertOk()->getContent();
        $this->assertStringContainsString(' checked', $this->switchTag($html, 'pledge.shop_name'));
        $this->assertStringNotContainsString(' checked', $this->switchTag($html, 'pledge.title'));
        foreach (DhiranSettings::receiptKeys() as $key) {
            $tag = $this->switchTag($html, $key);
            $this->assertStringContainsString('name="show[]"', $tag, $key);
            $this->assertStringContainsString('role="switch"', $tag, $key);
            $this->assertMatchesRegularExpression('/aria-labelledby="([^"]+)"/', $tag, $key);
            preg_match('/aria-labelledby="([^"]+)"/', $tag, $m);
            $this->assertMatchesRegularExpression('/id="' . preg_quote($m[1], '/') . '">[^<]+</', $html, "$key has a label to read");
        }
    }

    public function test_both_receipts_render_their_groups_with_master_controls_and_the_segmented_control(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100009');
        $html = $this->receiptHtml($owner, $shop, '/dhiran/settings/receipt')->assertOk()->getContent();
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('id="rs-tab-pledge"', $html);
        $this->assertStringContainsString('id="rs-tab-payment"', $html);
        // the payment panel is in the page (hidden), so its switches still post and are not stored as hidden
        $this->assertMatchesRegularExpression('/id="rs-panel-payment"[^>]*hidden/', $html);
        foreach (DhiranSettings::RECEIPT_FIELDS as $receipt => $def) {
            foreach (array_keys($def['groups']) as $group) {
                $this->assertStringContainsString('>' . $group . '</h3>', $html, "$receipt / $group");
            }
        }
        $this->assertStringContainsString('of 4 shown', $html);
        $this->assertStringContainsString('Hide all', $html);
    }

    public function test_loan_number_row_is_locked_but_accessible(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100010');
        $html = $this->receiptHtml($owner, $shop, '/dhiran/settings/receipt')->assertOk()->getContent();
        preg_match('/<input [^>]*id="rs-pledge-loan_number"[^>]*>/s', $html, $m);
        $tag = preg_replace('/\s+/', ' ', $m[0] ?? '');
        $this->assertStringContainsString(' checked', $tag);
        $this->assertStringContainsString('aria-disabled="true"', $tag);
        $this->assertStringContainsString('role="switch"', $tag);
        $this->assertStringNotContainsString('name=', $tag, 'never posted, so it can never be switched off');
        $this->assertStringContainsString('Always printed. It is how a loan is found.', $html);
    }

    public function test_the_form_posts_the_same_fields_and_has_a_save_bar(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100011');
        $html = $this->receiptHtml($owner, $shop, '/dhiran/settings/receipt')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<form[^>]*method="POST"[^>]*action="' . preg_quote(self::BASE, '#') . '/dhiran/settings/receipt"#s', $html);
        $this->assertStringContainsString('name="_method" value="PATCH"', $html);
        $this->assertStringContainsString('name="receipt_terms_text"', $html);
        $this->assertStringContainsString('name="closure_certificate_text"', $html);
        $this->assertStringContainsString('class="rs-bar"', $html);
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*>[^<]*(<svg.*?<\/svg>)?\s*(<span[^>]*>)?Save changes/s', $html);
        $this->assertStringContainsString('role="status"', $html);
    }

    public function test_sample_link_needs_a_loan_in_this_shop(): void
    {
        [$a, $ownerA] = $this->shopWithOwner('9390100012');
        [$b] = $this->shopWithOwner('9390100013');
        $this->assertStringNotContainsString('See a sample', $this->receiptHtml($ownerA, $a, '/dhiran/settings/receipt')->getContent());
        [$loanB] = $this->loanWithPayment($b);
        $this->assertStringNotContainsString('See a sample', $this->receiptHtml($ownerA, $a, '/dhiran/settings/receipt')->getContent(), 'another shop\'s loan is never offered');
        [$loanA] = $this->loanWithPayment($a);
        $html = $this->receiptHtml($ownerA, $a, '/dhiran/settings/receipt')->getContent();
        $this->assertStringContainsString("/dhiran/loans/{$loanA->id}/receipt", $html);
        $this->assertStringNotContainsString("/dhiran/loans/{$loanB->id}/receipt", $html);
    }

    public function test_a_refused_save_keeps_the_switches_the_user_set(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390100014');
        $this->hide($owner, $shop, ['pledge.title']);
        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->from(self::BASE . '/dhiran/settings/receipt')
            ->patch(self::BASE . '/dhiran/settings/receipt', ['show' => ['pledge.shop_name'], 'receipt_terms_text' => str_repeat('a', 5001)]));
        $res->assertSessionHasErrors('receipt_terms_text');
        $html = $this->receiptHtml($owner, $shop, '/dhiran/settings/receipt')->getContent();
        $this->assertStringContainsString(' checked', $this->switchTag($html, 'pledge.shop_name'));
        $this->assertStringNotContainsString(' checked', $this->switchTag($html, 'pledge.shop_phone'));
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
