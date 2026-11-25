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

/** Settings is split into Loan | Receipt | Users | Your access, with one shared sub-navigation. */
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
            '/subscription/status' => 'Your access',
        ];
        foreach ($pages as $path => $label) {
            $html = $this->page($owner, $path)->assertOk()->getContent();
            [$all, $cur] = $this->items($html);
            $this->assertSame(['Loan settings', 'Receipt settings', 'Notifications', 'Users & roles', 'Your access'], $all, $path);
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
        $this->assertSame(['Loan settings', 'Receipt settings', 'Notifications'], $all);
        $this->page($user, '/settings')->assertForbidden();
    }

    /** Loan, Users & roles and Your access: one form (or panel set), the title switcher with the open page marked, a save action. */
    public function test_loan_users_and_billing_pages_use_the_title_switcher_and_the_card_system(): void
    {
        $owner = $this->owner($this->shop());
        foreach (['/dhiran/settings' => 'Loan settings', '/settings?tab=staff' => 'Users & roles', '/subscription/status' => 'Your access'] as $path => $label) {
            $html = $this->page($owner, $path)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<button[^>]*data-section-switch[^>]*>.*?data-section-label>' . preg_quote(htmlspecialchars($label, ENT_QUOTES), '/') . '</s', $html, $path);
            $this->assertMatchesRegularExpression('/<nav class="dh-subnav"\s+id="settings-sections"\s+data-section-list/', $html, $path);
            $this->assertMatchesRegularExpression('/<a [^>]*aria-current="page"[^>]*>\s*' . preg_quote(htmlspecialchars($label, ENT_QUOTES), '/') . '\s*<\/a>/', $this->nav($html), $path);
            $this->assertStringContainsString('class="st-page"', $html, $path);
            $this->assertStringContainsString('class="st-panel"', $html, $path);
            $this->assertStringContainsString('role="status"', $html, "$path announces its flash");
        }
    }

    public function test_users_roles_tab_has_one_form_per_role_with_labelled_permission_rows(): void
    {
        $shop = $this->shop();
        $owner = $this->owner($shop);
        $html = $this->page($owner, '/settings?tab=roles')->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Role to change"', $html);
        $this->assertStringContainsString('class="st-role-btn"', $html);
        // every non-owner role posts to its own endpoint, one save button each; the owner role is locked and has none
        preg_match_all('/<form method="POST" action="[^"]*\/settings\/roles\/\d+"/', $html, $forms);
        $roles = Role::withoutGlobalScopes()->where('shop_id', $shop->id)->get();
        $this->assertCount($roles->count(), $forms[0], 'one form per role');
        $this->assertStringContainsString('Owner permissions are locked', $html);
        // each permission is a shared switch row (label wrapping its own role="switch" checkbox), so the whole row is the tap target
        $this->assertGreaterThan(0, preg_match_all('/<label class="ui-switch-row" for="perm-\d+-\d+">.*?role="switch"[^>]*name="permissions\[\]"/s', $html));
    }

    public function test_staff_list_rows_have_role_badge_and_icon_only_edit_with_a_name(): void
    {
        $shop = $this->shop();
        $owner = $this->owner($shop);
        $staff = $this->userWith($shop, ['dhiran.view']);
        $staff->forceFill(['name' => 'Asha Verma'])->save();
        $html = $this->page($owner, '/settings?tab=staff')->assertOk()->getContent();
        $this->assertStringContainsString('class="st-row', $html);
        $this->assertStringContainsString('Asha Verma', $html);
        $this->assertStringContainsString('class="st-badge', $html);
        $this->assertStringContainsString('aria-label="Edit Asha Verma"', $html);
        $this->assertStringContainsString('aria-label="Remove Asha Verma"', $html);
    }

    public function test_staff_forms_are_labelled_with_one_save_and_keep_their_field_names(): void
    {
        $shop = $this->shop();
        $owner = $this->owner($shop);
        $staff = $this->userWith($shop, ['dhiran.view']);
        foreach (['/staff/create', '/staff/' . $staff->id . '/edit'] as $path) {
            $html = $this->page($owner, $path)->assertOk()->getContent();
            foreach (['name', 'mobile_number', 'email', 'password', 'password_confirmation'] as $field) {
                $this->assertStringContainsString('name="' . $field . '"', $html, "$path $field");
                $this->assertStringContainsString('for="' . $field . '"', $html, "$path label for $field");
            }
            $this->assertStringContainsString('name="role_id"', $html);
            $this->assertStringContainsString('inputmode="numeric"', $html);
            $this->assertSame(1, preg_match_all('/type="submit" class="btn btn-dark btn-sm"/', $html), "$path one save");
        }
    }

    public function test_plan_page_in_the_app_shell_is_read_only_with_a_contact_line(): void
    {
        $owner = $this->owner($this->shop());
        $html = $this->page($owner, '/subscription/status')->assertOk()->getContent();
        $this->assertStringContainsString('To extend your access, contact us', $html);
        $this->assertStringNotContainsString('st-plans', $html);
        $this->assertDoesNotMatchRegularExpression('/>(Choose a plan|Renew|Buy this plan)</', $html);
    }

    /** Tabs and roles switch in place: both panels are in the server HTML, tab controls are buttons, the first paint already shows the asked tab and role. */
    public function test_users_roles_tabs_are_buttons_with_both_panels_in_the_first_paint(): void
    {
        $shop = $this->shop();
        $owner = $this->owner($shop);
        foreach (['staff' => ['true', 'false'], 'roles' => ['false', 'true']] as $tab => [$staffSel, $rolesSel]) {
            $html = $this->page($owner, '/settings?tab=' . $tab)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<button type="button" class="st-tab" role="tab" id="tab-staff" aria-controls="pane-staff" aria-selected="' . $staffSel . '"/', $html, $tab);
            $this->assertMatchesRegularExpression('/<button type="button" class="st-tab" role="tab" id="tab-roles" aria-controls="pane-roles" aria-selected="' . $rolesSel . '"/', $html, $tab);
            $this->assertStringContainsString('role="tablist"', $html);
            // no <a> tab links in the JS path (the plain links live in <noscript>)
            $js = preg_replace('/<nav class="st-tabs st-nojs".*?<\/nav>/s', '', $html);
            $this->assertDoesNotMatchRegularExpression('/<a [^>]*class="st-tab"/', $js);
            $this->assertStringContainsString('id="pane-staff" role="tabpanel"', $html);
            $this->assertStringContainsString('id="pane-roles" role="tabpanel"', $html);
            // the inactive panel is hidden in the server HTML, so there is no flash of the wrong tab
            $this->assertMatchesRegularExpression('/id="pane-' . ($tab === 'staff' ? 'roles' : 'staff') . '"[^>]*\shidden/', $html, $tab);
            $this->assertDoesNotMatchRegularExpression('/id="pane-' . $tab . '"[^>]*\shidden/', $html, $tab);
        }
        // role deep link: that role's tab is selected and its form is the only one not inert
        $role = Role::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'manager')->first() ?? Role::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', '!=', 'owner')->firstOrFail();
        $html = $this->page($owner, '/settings?tab=roles&role=' . $role->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="role-tab-' . $role->id . '"[^>]*aria-selected="true"/', $html);
        preg_match_all('/<form [^>]*role="tabpanel"[^>]*>/', $html, $forms);
        $this->assertCount(1, array_filter($forms[0], fn ($tag) => ! str_contains($tag, ' inert')), 'exactly one role form is live');
    }

    /** The four settings pages swap inside one Turbo frame: same frame id on all, the shared stylesheet is in every head, and each still answers as a whole page. */
    public function test_settings_pages_share_one_frame_and_stylesheet_and_stand_alone(): void
    {
        $owner = $this->owner($this->shop());
        foreach (['/dhiran/settings', '/dhiran/settings/receipt', '/settings?tab=staff', '/subscription/status'] as $path) {
            $html = $this->page($owner, $path)->assertOk()->getContent();
            $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($html), $path);
            preg_match('/<head>.*?<\/head>/s', $html, $head);
            $this->assertMatchesRegularExpression('/<link rel="stylesheet" href="[^"]*dhiran-settings-[^"]+\.css"/', $head[0] ?? '', "$path: settings css in the head, not injected later");
            $this->assertSame(1, substr_count($html, '<turbo-frame id="settings-pane" data-turbo-action="advance">'), $path);
            // the pane carries the section links, so aria-current moves with the pane; the shell (rail, header band) is outside it
            preg_match('/<turbo-frame id="settings-pane".*?<\/turbo-frame>/s', $html, $frame);
            $this->assertStringContainsString('class="dh-subnav"', $frame[0], $path);
            $this->assertStringNotContainsString('class="dh-sidebar"', $frame[0], $path);
            $this->assertStringContainsString('class="dh-sidebar"', $html, "$path: standalone page keeps its own rail");
            $this->assertStringContainsString('class="dh-page-header', $html, $path);
            $this->assertStringContainsString('</html>', $html, $path);
            $this->assertStringNotContainsString('<noscript><style>', $html, "$path: a style inside noscript turns live when a frame is swapped in");
        }
        // the receipt form's Alpine state ships with the app, not as a page script a frame swap would miss
        $receipt = $this->page($owner, '/dhiran/settings/receipt')->assertOk()->getContent();
        $this->assertStringNotContainsString('window.rsReceiptSettings =', $receipt);
        $this->assertStringContainsString('x-data="rsReceiptSettings(', $receipt);
        // links that leave the pane are told to break out of the frame, so they never hit "content missing"
        $loan = $this->page($owner, '/dhiran/settings')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a href="[^"]*\/dhiran\/loans" class="btn btn-secondary btn-sm" data-turbo-frame="_top">Cancel/', $loan);
    }
}
