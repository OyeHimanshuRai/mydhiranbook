<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Platform\PlatformAdmin;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Services\TotpService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

/**
 * Owner 2026-11-22: a star marks every field the server requires; the rest carry no marker, and the word "optional"
 * appears nowhere. Forms that mix both show one "* Required" line; forms where every field is required show neither.
 */
class RequiredMarkersTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures;

    private const STAR = '<span class="ui-req" aria-hidden="true">*</span>';
    private const LEGEND = 'class="ui-req-legend"';

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = self::createDhiranShop(['name' => 'Star Co', 'shop_type' => 'dhiran', 'phone' => '9390033000', 'owner_first_name' => 'O',
            'owner_last_name' => 'W', 'owner_mobile' => '9390033000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390033000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
    }

    private function clerk(string $mobile): User
    {
        $role = Role::where('shop_id', $this->shop->id)->where('name', 'staff')->first() ?? (new Role())->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $this->shop->id]);
        $role->save();

        return User::create(['mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Clerk', 'is_active' => true,
            'shop_id' => $this->shop->id, 'role_id' => $role->id]);
    }

    /** A shop page as the signed-in owner. */
    private function page(string $path): string
    {
        return TenantContext::runFor($this->shop->id, function () use ($path) {
            $this->actingAs($this->owner);

            return $this->get($path)->assertOk()->getContent();
        });
    }

    /** What a person can read or hear: the page without script, style and comments (attributes such as placeholders stay). */
    private function visible(string $html): string
    {
        return preg_replace(['~<script\b.*?</script>~is', '~<style\b.*?</style>~is', '~<!--.*?-->~s'], '', $html);
    }

    /** The whole <label> or legend element around $text, to read its star. */
    private function labelOf(string $html, string $text): string
    {
        $this->assertSame(1, preg_match('~<label[^>]*>\s*' . preg_quote($text, '~') . '[^<]*(?:<[^/l][^>]*>[^<]*</[^>]+>)*[^<]*</label>~', $html, $m), "label \"$text\" found");

        return $m[0];
    }

    private function assertNoOptionalWord(string $html, string $where): void
    {
        $this->assertDoesNotMatchRegularExpression('/optional/i', $this->visible($html), "the word \"optional\" appears on $where");
    }

    public function test_the_word_optional_is_nowhere_on_the_shop_pages(): void
    {
        $loan = TenantContext::runFor($this->shop->id, function () {
            $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Asha', 'last_name' => 'B', 'mobile' => '9811122233']);

            return app(DhiranService::class)->createLoan($this->shop, $c, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 200000, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id, 'status' => 'active']);
        });
        $staff = $this->clerk('9390033111');

        $paths = ['/dhiran/create', '/dhiran/old-loans/create', '/dhiran/old-loans/upload', '/dhiran/borrowers/create', '/dhiran/settings',
            '/dhiran/settings/receipt', '/settings?tab=staff', '/settings?tab=roles', '/staff/create', "/staff/{$staff->id}/edit",
            '/dhiran/loans/' . $loan->id, '/subscription/status'];
        foreach ($paths as $path) {
            $this->assertNoOptionalWord($this->page($path), $path);
        }
    }

    public function test_the_word_optional_is_nowhere_on_public_onboarding_and_admin_pages(): void
    {
        foreach (['/register', '/login', '/forgot-password', '/contact', '/pricing', '/admin/login', '/admin/forgot-password'] as $path) {
            $this->assertNoOptionalWord($this->get($path)->getContent(), $path);
        }
        $new = $this->newOwner();
        $this->paidShopless($new);
        $this->assertNoOptionalWord($this->actingAs($new)->get(route('dhiran.onboarding'))->assertOk()->getContent(), 'onboarding');

        $admin = PlatformAdmin::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Admin-pass-12345', 'role' => 'super_admin', 'is_active' => true,
            'two_factor_secret' => app(TotpService::class)->generateSecret(), 'two_factor_enabled' => true])->fresh();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-pass-12345']);
        $this->post('/admin/mfa', ['code' => app(TotpService::class)->computeCode($admin->two_factor_secret, (int) floor(time() / 30))]);
        foreach (['/admin/plans', '/admin/accounts', '/admin/shops/' . $this->shop->id, '/admin/unresolved-payments'] as $path) {
            $this->assertNoOptionalWord($this->get($path)->assertOk()->getContent(), $path);
        }
    }

    /** Rule names whose rule list has a plain, unconditional `required`. */
    private function requiredIn(array $rules): array
    {
        $names = [];
        foreach ($rules as $name => $rule) {
            $parts = is_string($rule) ? explode('|', $rule) : array_filter($rule, 'is_string');
            if (in_array('required', $parts, true)) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    /** The <input>/<select>/<textarea> tag whose id (or Alpine :id expression) is $id. */
    private function tagOf(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('~<(?:input|select|textarea)\b[^>]*\s:?id="' . preg_quote($id, '~') . '"[^>]*>~', $html, $m), "control $id found");

        return $m[0];
    }

    private function assertNoRequiredRule(string $html, array $ids): void
    {
        foreach ($ids as $id) {
            $this->assertDoesNotMatchRegularExpression('~\s:?(aria-)?required\b~', $this->tagOf($html, $id), "$id is nullable on the server: no required, no aria-required");
            $this->assertDoesNotMatchRegularExpression('~<label[^>]*for="' . preg_quote($id, '~') . '"[^>]*>[^<]*(<[^/][^>]*>[^<]*)*ui-req~', $html, "$id has no star");
        }
    }

    /** Field names whose label carries a star (items.*.x for the repeated pledged-item fields). */
    private function starredNames(string $html): array
    {
        preg_match_all('~<label[^>]*\s:?for="([^"]+)"[^>]*>(.*?)</label>~s', $html, $labels, PREG_SET_ORDER);
        $names = [];
        foreach ($labels as [, $for, $inner]) {
            if (! str_contains($inner, 'ui-req')) {
                continue;
            }
            $tag = $this->tagOf($html, $for);
            $names[] = match (true) {
                preg_match('~\s:name="(?:\'items\[\'\+index\+\'\]|`items\[\$\{i\}\])\[(\w+)\]~', $tag, $m) === 1 => 'items.*.' . $m[1],
                preg_match('~\sname="([^"]+)"~', $tag, $m) === 1 => $m[1],
                $for === 'fm-customer-search' => 'customer_id',
                default => preg_replace('~^nb-~', '', $for),
            };
        }
        sort($names);

        return array_values(array_unique($names));
    }

    public function test_every_star_matches_an_unconditional_server_required_rule(): void
    {
        $collateral = $this->requiredIn(\App\Services\Dhiran\DhiranCollateral::rules());
        $oldLoanRules = \App\Services\Dhiran\DhiranOldLoans::rules([], $this->shop->id);
        $oldLoan = array_values(array_diff($this->requiredIn($oldLoanRules), ['items']));
        $this->assertContains('items.*.description', $collateral);

        $settings = ['default_interest_rate_monthly', 'default_interest_type', 'default_penalty_rate_monthly', 'default_min_interest_months', 'default_ltv_ratio',
            'high_value_threshold', 'high_value_ltv_ratio', 'min_loan_amount', 'max_loan_amount', 'processing_fee_type', 'processing_fee_value',
            'default_tenure_months', 'default_min_lock_months', 'grace_period_days', 'forfeiture_notice_days'];
        sort($settings);

        // Form => what its controller validates as plain `required` (DhiranController::store/storeCustomer/updateSettings, StaffController::store, ShopAuthController::register, DhiranOnboardingController).
        $table = [
            '/dhiran/create' => ['customer_id', 'first_name', 'items.*.description', 'mobile', 'principal_amount'], // first_name, mobile: the add-borrower dialog posts to storeCustomer
            '/dhiran/old-loans/create' => $oldLoan,
            '/dhiran/borrowers/create' => ['first_name', 'mobile'],
            '/dhiran/settings' => $settings,
            '/staff/create' => ['mobile_number', 'name', 'password'],
        ];
        foreach ($table as $path => $expected) {
            sort($expected);
            $html = $this->page($path);
            $this->assertSame($expected, $this->starredNames($html), "starred fields on $path");
        }

        $this->assertSame(['email', 'mobile', 'name', 'password'], $this->starredNames($this->get('/register')->getContent()), 'ShopAuthController::register');
        $new = $this->newOwner();
        $this->paidShopless($new);
        $this->assertSame(['address', 'name', 'phone'], $this->starredNames($this->actingAs($new)->get(route('dhiran.onboarding'))->getContent()), 'DhiranOnboardingController::store');
    }

    public function test_nullable_new_loan_fields_carry_no_star_and_no_required_attribute(): void
    {
        $html = $this->page('/dhiran/create');

        $this->assertNoRequiredRule($html, ['interest_rate_monthly', 'interest_type', 'tenure_months', 'penalty_rate_monthly', 'gold_rate_on_date', 'notes',
            "'it-metal-' + index", "'it-purity-' + index", "'it-rate-' + index", "'it-value-' + index", "'it-gross-' + index", "'it-stone-' + index", "'it-huid-' + index"]);
        foreach (['principal_amount', "'it-desc-' + index"] as $id) {
            $this->assertMatchesRegularExpression('~\saria-required="true"~', $this->tagOf($html, $id));
        }
        $this->assertStringContainsString(self::STAR, $this->labelOf($html, 'Principal amount'));
        $this->assertStringContainsString(self::STAR, $this->labelOf($html, 'Borrower'));
        $this->assertStringNotContainsString(self::LEGEND, $html, 'the star explains itself: no legend line');
    }

    public function test_nullable_old_loan_fields_carry_no_star_and_no_required_attribute(): void
    {
        $html = $this->page('/dhiran/old-loans/create');

        $this->assertNoRequiredRule($html, ['customer_id', 'borrower_first_name', 'borrower_mobile', 'borrower_pan', 'unpaid_interest', 'penalty_rate_monthly',
            'gold_rate_on_date', "'oi-purity-' + i", "'oi-gross-' + i", "'oi-rate-' + i", "'oi-value-' + i", 'notes']);
        foreach (['paper_loan_number', 'interest_type', 'tenure_months'] as $id) {
            $this->assertMatchesRegularExpression('~\saria-required="true"~', $this->tagOf($html, $id));
        }
    }

    public function test_no_legend_line_anywhere_and_forms_with_no_required_rule_carry_no_star(): void
    {
        foreach (['/dhiran/create', '/dhiran/old-loans/create', '/dhiran/borrowers/create', '/dhiran/settings', '/staff/create'] as $path) {
            $html = $this->page($path);
            $this->assertStringNotContainsString(self::LEGEND, $html, "no legend on $path");
            $this->assertDoesNotMatchRegularExpression('~\*\s*Required~', strip_tags($this->visible($html)), "no \"* Required\" line on $path");
        }
        $this->assertStringNotContainsString(self::LEGEND, $this->get('/register')->getContent());
        foreach (['/dhiran/old-loans/upload', '/dhiran/settings/receipt'] as $path) { // one required file / no required field
            $this->assertStringNotContainsString('ui-req', $this->page($path), "no marker on $path");
        }
        foreach (['/login', '/forgot-password', '/admin/login', '/admin/forgot-password'] as $path) {
            $this->assertStringNotContainsString('ui-req', $this->visible($this->get($path)->getContent()), "no star or legend on $path");
        }
    }

    public function test_borrower_form_stars_name_and_mobile_only(): void
    {
        $html = $this->page('/dhiran/borrowers/create');

        foreach (['First name', 'Mobile number'] as $label) {
            $this->assertStringContainsString(self::STAR, $this->labelOf($html, $label));
        }
        foreach (['Last name', 'PAN', 'ID number', 'State code', 'Address', 'Notes'] as $label) {
            $this->assertStringNotContainsString('ui-req', $this->labelOf($html, $label), "$label has no star");
        }
        $this->assertMatchesRegularExpression('~id="bc-first_name"[^>]*aria-required="true"~', $html);
        $this->assertDoesNotMatchRegularExpression('~id="bc-last_name"[^>]*aria-required~', $html);
    }

    public function test_staff_form_marks_password_only_when_adding(): void
    {
        $html = $this->page('/staff/create');
        foreach (['Full name', 'Mobile number', 'Password'] as $label) {
            $this->assertStringContainsString(self::STAR, $this->labelOf($html, $label), "$label is starred when adding");
        }
        foreach (['Email', 'Confirm password'] as $label) {
            $this->assertStringNotContainsString('ui-req', $this->labelOf($html, $label), "$label is not a required rule on the server");
        }
        $this->assertStringContainsString(self::STAR, $this->labelOf($html, 'Mobile number'));
        $this->assertMatchesRegularExpression('~id="name"[^>]*aria-required="true"~', $html);
        $this->assertDoesNotMatchRegularExpression('~id="email"[^>]*aria-required~', $html);

        $staff = $this->clerk('9390033222');
        $edit = $this->page("/staff/{$staff->id}/edit");
        $this->assertStringContainsString(self::STAR, $this->labelOf($edit, 'Full name'));
        $this->assertStringNotContainsString('ui-req', $this->labelOf($edit, 'New password'), 'a blank password keeps the old one');
    }

    public function test_loan_settings_star_the_numbers_and_not_the_prefix(): void
    {
        $html = $this->page('/dhiran/settings');

        $this->assertStringContainsString(self::STAR, $this->labelOf($html, 'Monthly interest rate'));
        $this->assertStringContainsString(self::STAR, $this->labelOf($html, 'Tenure'));
        $this->assertStringNotContainsString('ui-req', $this->labelOf($html, 'Loan number prefix'));
    }

    public function test_registration_and_onboarding(): void
    {
        $register = $this->get('/register')->getContent();
        $this->assertStringNotContainsString(self::LEGEND, $register);
        foreach (['Your name', 'Email address', 'Mobile number', 'Password'] as $label) {
            $this->assertStringContainsString(self::STAR, $this->labelOf($register, $label));
        }
        $this->assertStringNotContainsString('ui-req', $this->labelOf($register, 'Confirm password'), 'confirmed is not a plain required rule');

        $new = $this->newOwner();
        $this->paidShopless($new);
        $html = $this->actingAs($new)->get(route('dhiran.onboarding'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::LEGEND, $html);
        foreach (['Business name', 'Business phone', 'Business address'] as $label) {
            $this->assertStringContainsString(self::STAR, $this->labelOf($html, $label));
        }
        $this->assertStringNotContainsString('ui-req', $this->labelOf($html, 'Loan receipt prefix'));
        $this->assertStringContainsString('Printed before every loan number.', $html);
    }

    public function test_loan_dialogs_star_amounts_and_the_all_required_ones_stay_bare(): void
    {
        $loan = TenantContext::runFor($this->shop->id, function () {
            $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Asha', 'last_name' => 'B', 'mobile' => '9811122244']);

            return app(DhiranService::class)->createLoan($this->shop, $c, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 200000, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id, 'status' => 'active']);
        });
        $html = $this->page('/dhiran/loans/' . $loan->id);

        foreach (['pi-amount', 'rp-amount', 'rel-amount'] as $id) {
            $this->assertMatchesRegularExpression('~<label for="' . $id . '">Amount <span class="ui-req" aria-hidden="true">\*</span></label>~', $html);
            $this->assertMatchesRegularExpression('~id="' . $id . '"[^>]*aria-required="true"~', $html);
        }
        $dialog = fn (string $id) => preg_match('~<dialog id="' . $id . '".*?</dialog>~s', $html, $m) ? $m[0] : '';
        foreach (['payInterestModal', 'repayModal', 'releaseItemModal'] as $id) {
            $this->assertStringNotContainsString(self::LEGEND, $dialog($id), "no legend in $id");
        }
        foreach (['precloseModal', 'renewModal'] as $id) {
            $this->assertStringNotContainsString('ui-req', $dialog($id), 'nothing in it is required by the server');
            $this->assertDoesNotMatchRegularExpression('~\s:?(aria-)?required\b~', $dialog($id), "$id adds no rule of its own");
        }
        $this->assertDoesNotMatchRegularExpression('~\s:?(aria-)?required\b~', $this->tagOf($html, 'rn-tenure'));
    }

    public function test_admin_plan_form_stars_the_numbers_and_not_the_reason(): void
    {
        $this->seedAdminAndPlans();
        $html = $this->get('/admin/plans')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<label for="plan-\d+-price_monthly">Monthly price \(₹\) <span class="ui-req" aria-hidden="true">\*</span></label>~', $html);
        $this->assertMatchesRegularExpression('~<label for="plan-\d+-reason">Reason</label>~', $html);
        $this->assertMatchesRegularExpression('~<label for="plan-\d+-price_yearly">Yearly price \(₹\)</label>~', $html, 'nullable on the server');
        $this->assertDoesNotMatchRegularExpression('~\s:?(aria-)?required\b~', $this->tagOf($html, preg_match('~id="(plan-\d+-price_yearly)"~', $html, $m) ? $m[1] : 'none'));
        $this->assertStringNotContainsString(self::LEGEND, $html);
    }

    public function test_admin_forms_follow_the_server_rules(): void
    {
        $this->seedAdminAndPlans();
        PlatformAdmin::create(['name' => 'Support', 'email' => 'support@example.test', 'password' => 'Admin-pass-12345', 'role' => 'support', 'is_active' => true]);
        $accounts = $this->get('/admin/accounts')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<label for="admin-\d+-is_active">Access <span class="ui-req"~', $accounts);
        $this->assertStringNotContainsString(self::LEGEND, $accounts);
        $reset = $this->app['view']->make('admin.reset', ['token' => 't', 'email' => 'a@example.test'])->render();
        $this->assertStringNotContainsString(self::LEGEND, $reset);
        $this->assertStringNotContainsString('ui-req', $this->labelOf($reset, 'Confirm password'));
        foreach (['/admin/shops/' . $this->shop->id, '/admin/unresolved-payments'] as $path) {
            $this->assertStringNotContainsString('ui-req', $this->visible($this->get($path)->assertOk()->getContent()), $path);
        }
    }

    private function seedAdminAndPlans(): void
    {
        $admin = PlatformAdmin::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Admin-pass-12345', 'role' => 'super_admin', 'is_active' => true,
            'two_factor_secret' => app(TotpService::class)->generateSecret(), 'two_factor_enabled' => true])->fresh();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-pass-12345'])->assertRedirect('/admin/mfa');
        $this->post('/admin/mfa', ['code' => app(TotpService::class)->computeCode($admin->two_factor_secret, (int) floor(time() / 30))])->assertRedirect('/admin');
    }
}
