<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Settings is split into Loan | Receipt | Users | Plan & billing, with one shared sub-navigation. */
class SettingsSubPagesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://127.0.0.1:8787';

    private int $n = 0;

    private function shop(): Shop
    {
        $m = '93902' . str_pad((string) ++$this->n, 5, '0', STR_PAD_LEFT);
        $shop = self::createDhiranShop([
            'name' => 'Pawn ' . $m, 'shop_type' => 'dhiran', 'phone' => $m,
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $m,
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);

        return $shop;
    }

    private function owner(Shop $shop): User
    {
        return self::userWithRole($shop, ['mobile_number' => '98' . fake()->unique()->numerify('########'), 'password' => bcrypt('x'), 'realm' => 'dhiran', 'is_active' => true, 'email_verified_at' => now()]);
    }

    /** A user whose role holds exactly the named permissions. */
    private function userWith(Shop $shop, array $permissions): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'custom' . ++$this->n, 'display_name' => 'Custom', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return User::create(['mobile_number' => '97' . fake()->unique()->numerify('########'), 'password' => bcrypt('x'), 'realm' => 'dhiran', 'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
    }

    private function page(User $u, string $path)
    {
        return TenantContext::runFor($u->shop_id, fn () => $this->actingAs($u)->get(self::BASE . $path));
    }

    private function nav(string $html): string
    {
        preg_match('/<nav class="dh-subnav".*?<\/nav>/s', $html, $m);

        return $m[0] ?? '';
    }

    /** The nav's labels, and the one marked aria-current. */
    private function items(string $html): array
    {
        $nav = $this->nav($html);
        preg_match_all('/<a [^>]*>\s*([^<]+?)\s*<\/a>/', $nav, $all);
        preg_match_all('/<a [^>]*aria-current="page"[^>]*>\s*([^<]+?)\s*<\/a>/', $nav, $cur);

        return [array_map("html_entity_decode", $all[1]), array_map("html_entity_decode", $cur[1])];
    }

    public function test_nav_is_on_all_four_pages_with_the_right_active_item(): void
    {
        $owner = $this->owner($this->shop());
        $pages = [
            '/dhiran/settings' => 'Loan settings',
            '/dhiran/settings/receipt' => 'Receipt settings',
            '/settings?tab=staff' => 'Users & roles',
            '/subscription/status' => 'Plan & billing',
        ];
        foreach ($pages as $path => $label) {
            $html = $this->page($owner, $path)->assertOk()->getContent();
            [$all, $cur] = $this->items($html);
            $this->assertSame(['Loan settings', 'Receipt settings', 'Users & roles', 'Plan & billing'], $all, $path);
            $this->assertCount(1, $cur, $path);
            $this->assertStringStartsWith(explode(' ', $label)[0], $cur[0], $path);
            $this->assertStringContainsString($label, html_entity_decode(strip_tags($html)), "$path heading");
        }
    }

    public function test_receipt_page_title_is_the_section_switcher_and_the_links_are_its_list(): void
    {
        $owner = $this->owner($this->shop());
        $html = $this->page($owner, '/dhiran/settings/receipt')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*data-section-switch[^>]*aria-controls="settings-sections"[^>]*>.*?data-section-label>Receipt settings</s', $html);
        $this->assertMatchesRegularExpression('/<nav class="dh-subnav" id="settings-sections" data-section-list/', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*aria-current="page"[^>]*>\s*Receipt settings\s*<\/a>/', $this->nav($html));
    }

    public function test_receipt_page_has_switches_and_terms_textareas(): void
    {
        $owner = $this->owner($this->shop());
        $html = $this->page($owner, '/dhiran/settings/receipt')->assertOk()->getContent();
        $this->assertStringContainsString('name="show[]" value="pledge.shop_name"', $html);
        $this->assertStringContainsString('name="receipt_terms_text"', $html);
        $this->assertStringContainsString('name="closure_certificate_text"', $html);
        $this->assertStringNotContainsString('name="default_interest_rate_monthly"', $html);

        $loan = $this->page($owner, '/dhiran/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="receipt_terms_text"', $loan);
        $this->assertStringNotContainsString('name="closure_certificate_text"', $loan);
        $this->assertStringNotContainsString('Staff & Permissions', $loan);
    }

    public function test_terms_save_through_the_receipt_endpoint_and_loan_form_leaves_them_alone(): void
    {
        $shop = $this->shop();
        $owner = $this->owner($shop);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $as = fn (string $method, string $path, array $data) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->{$method}(self::BASE . $path, $data));

        $as('patch', '/dhiran/settings/receipt', ['show' => ['pledge.title'], 'receipt_terms_text' => 'Pay on time.', 'closure_certificate_text' => 'Closed.'])
            ->assertRedirect(self::BASE . '/dhiran/settings/receipt')->assertSessionHasNoErrors();
        $s = DhiranSettings::getForShop($shop->id)->fresh();
        $this->assertSame(['Pay on time.', 'Closed.'], [$s->receipt_terms_text, $s->closure_certificate_text]);

        // Switches-only post (no text fields) keeps the saved text.
        $as('patch', '/dhiran/settings/receipt', ['show' => []])->assertSessionHasNoErrors();
        $this->assertSame('Pay on time.', DhiranSettings::getForShop($shop->id)->fresh()->receipt_terms_text);

        $as('patch', '/dhiran/settings/receipt', ['receipt_terms_text' => str_repeat('a', 5001)])->assertSessionHasErrors('receipt_terms_text');

        // The Loan form no longer carries or changes the terms, even if posted.
        $as('patch', '/dhiran/settings', [
            'default_interest_rate_monthly' => 2, 'default_interest_type' => 'flat', 'default_penalty_rate_monthly' => 1, 'default_min_interest_months' => 1,
            'default_ltv_ratio' => 75, 'high_value_threshold' => 500000, 'high_value_ltv_ratio' => 70, 'min_loan_amount' => 1000, 'max_loan_amount' => 1000000,
            'processing_fee_type' => 'flat', 'processing_fee_value' => 0, 'default_tenure_months' => 12, 'default_min_lock_months' => 0, 'grace_period_days' => 7,
            'forfeiture_notice_days' => 30, 'loan_number_prefix' => 'DH', 'receipt_terms_text' => 'Hacked', 'closure_certificate_text' => 'Hacked',
        ])->assertSessionHasNoErrors();
        $s = DhiranSettings::getForShop($shop->id)->fresh();
        $this->assertSame(['Pay on time.', 'Closed.'], [$s->receipt_terms_text, $s->closure_certificate_text]);
        $this->assertSame('DH', $s->loan_number_prefix);
    }

    public function test_staff_only_user_sees_only_users_and_is_refused_loan_and_receipt(): void
    {
        $user = $this->userWith($this->shop(), ['staff.view']);
        $html = $this->page($user, '/settings?tab=staff')->assertOk()->getContent();
        [$all, $cur] = $this->items($html);
        $this->assertSame(['Users & roles'], $all);
        $this->assertSame(['Users & roles'], $cur);
        $this->page($user, '/dhiran/settings')->assertForbidden();
        $this->page($user, '/dhiran/settings/receipt')->assertForbidden();
    }

    public function test_user_without_staff_view_does_not_see_users(): void
    {
        $user = $this->userWith($this->shop(), ['dhiran.view', 'dhiran.settings']);
        [$all] = $this->items($this->page($user, '/dhiran/settings')->assertOk()->getContent());
        $this->assertSame(['Loan settings', 'Receipt settings'], $all);
        $this->page($user, '/settings')->assertForbidden();
    }
}
