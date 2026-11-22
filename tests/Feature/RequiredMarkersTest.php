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

    public function test_new_loan_stars_what_the_server_requires_and_leaves_the_rest_bare(): void
    {
        $html = $this->page('/dhiran/create');

        foreach (['Borrower', 'Principal amount', 'Description'] as $label) {
            $this->assertStringContainsString(self::STAR, $this->labelOf($html, $label), "$label is starred");
        }
        $this->assertMatchesRegularExpression('~id="principal_amount"[^>]*aria-required="true"|aria-required="true"[^>]*id="principal_amount"~', $html);
        $this->assertMatchesRegularExpression('~id="fm-customer-search"~', $html);
        foreach (['Notes', 'Penalty rate', 'Stone weight', 'HUID', 'Last name', 'PAN'] as $label) {
            $this->assertStringNotContainsString('ui-req', $this->labelOf($html, $label), "$label has no star");
        }
        $this->assertDoesNotMatchRegularExpression('~<textarea[^>]*id="notes"[^>]*aria-required~', $html);
        $this->assertSame(2, substr_count($html, self::LEGEND), 'one legend on the form and one on the add-borrower dialog');
    }

    public function test_every_mixed_form_has_the_legend_and_every_all_required_form_has_neither_stars_nor_legend(): void
    {
        foreach (['/dhiran/old-loans/create', '/dhiran/borrowers/create', '/dhiran/settings', '/staff/create'] as $path) {
            $this->assertStringContainsString(self::LEGEND, $this->page($path), "legend on $path");
        }
        foreach (['/dhiran/old-loans/upload', '/dhiran/settings/receipt'] as $path) { // one required file / no required field
            $html = $this->page($path);
            $this->assertStringNotContainsString('ui-req', $html, "no marker on $path");
        }
        foreach (['/login', '/register', '/forgot-password', '/admin/login', '/admin/forgot-password'] as $path) {
            $html = $this->get($path)->getContent();
            $this->assertStringNotContainsString('ui-req', $this->visible($html), "no star or legend on $path");
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
        foreach (['Full name', 'Mobile number', 'Password', 'Confirm password'] as $label) {
            $this->assertStringContainsString(self::STAR, $this->labelOf($html, $label), "$label is starred when adding");
        }
        $this->assertStringNotContainsString('ui-req', $this->labelOf($html, 'Email'));
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
        $this->assertStringNotContainsString('ui-req', $this->visible($register), 'every register field is required, so no star and no legend');

        $new = $this->newOwner();
        $this->paidShopless($new);
        $html = $this->actingAs($new)->get(route('dhiran.onboarding'))->assertOk()->getContent();
        $this->assertStringContainsString(self::LEGEND, $html);
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
        foreach (['payInterestModal', 'repayModal', 'releaseItemModal', 'renewModal'] as $id) {
            $this->assertStringContainsString(self::LEGEND, $dialog($id), "$id mixes required and unmarked fields");
        }
        $this->assertStringNotContainsString('ui-req', $dialog('precloseModal'), 'only a payment method: nothing to mark');
    }

    public function test_admin_plan_form_stars_the_numbers_and_not_the_reason(): void
    {
        $this->seedAdminAndPlans();
        $html = $this->get('/admin/plans')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<label for="plan-\d+-price_monthly">Monthly price \(₹\) <span class="ui-req" aria-hidden="true">\*</span></label>~', $html);
        $this->assertMatchesRegularExpression('~<label for="plan-\d+-reason">Reason</label>~', $html);
        $this->assertStringContainsString(self::LEGEND, $html);
    }

    public function test_admin_all_required_forms_carry_no_marker(): void
    {
        $this->seedAdminAndPlans();
        foreach (['/admin/accounts', '/admin/shops/' . $this->shop->id, '/admin/unresolved-payments'] as $path) {
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
