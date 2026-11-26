<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

/** The server side of drafts and inline validation: the opt-in attributes are on the right forms and never on identity fields. */
class UxFormsTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures;

    private $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = self::createDhiranShop([
            'name' => 'Ux Co', 'shop_type' => 'dhiran', 'phone' => '9390019500', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390019500', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390019500', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'ux@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
    }

    private function page(string $routeName)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(route($routeName)));
    }

    /** Every <input|select|textarea ...> start tag in the HTML. */
    private function controls(string $html): array
    {
        preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $html, $m);

        return $m[0];
    }

    /** The start tag of the control with this name (attribute order does not matter to the browser, so not to the test). */
    private function assertRule(string $html, string $name, string $rule): void
    {
        $tag = collect($this->controls($html))->first(fn ($t) => str_contains($t, 'name="' . $name . '"'));
        $this->assertNotNull($tag, "no control named $name");
        $this->assertStringContainsString('data-rule="' . $rule . '"', $tag);
    }

    private function assertNoIdentityFieldIsDraftable(string $html): void
    {
        $sensitive = '/(aadhaar|\bpan\b|[-_]pan\b|id_proof|id_number|kyc|otp|token|password|card|signature|photo|image)/i';
        foreach ($this->controls($html) as $tag) {
            if (preg_match($sensitive, $tag) && ! str_contains($tag, 'name="_token"')) {
                $this->assertStringNotContainsString('data-autosave-include', $tag, "identity field opted into drafts: $tag");
            }
        }
        $this->assertDoesNotMatchRegularExpression('/data-autosave-include[^>]*(?:name|id)="[^"]*(aadhaar|pan|id_proof|id_number|kyc)/i', $html);
    }

    public function test_the_app_shell_exposes_a_16_hex_scope_to_signed_in_users(): void
    {
        $html = $this->page('dhiran.create')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<meta name="ux-scope" content="[0-9a-f]{16}">/', $html);
    }

    public function test_new_loan_form_opts_in_to_drafts_and_never_for_identity_fields(): void
    {
        $html = $this->page('dhiran.create')->assertOk()->getContent();
        $this->assertStringContainsString('data-autosave="new-loan"', $html);
        $this->assertStringContainsString('data-autosave-add-row', $html);
        $this->assertNoIdentityFieldIsDraftable($html);
        // the hidden pan / aadhaar inputs are plain hidden fields: no draft attribute, no validation rule
        foreach ($this->controls($html) as $tag) {
            if (preg_match('/name="(pan|aadhaar)"/', $tag)) {
                $this->assertStringNotContainsString('data-autosave', $tag);
                $this->assertStringNotContainsString('data-rule', $tag);
            }
        }
    }

    public function test_borrower_form_has_drafts_and_inline_rules(): void
    {
        $html = $this->page('dhiran.borrowers.create')->assertOk()->getContent();
        $this->assertStringContainsString('data-autosave="new-borrower"', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*id="bc-form"[^>]*\sdata-validate/', $html);
        $this->assertRule($html, 'mobile', 'mobile');
        $this->assertRule($html, 'pan', 'pan');
        $this->assertNoIdentityFieldIsDraftable($html);
    }

    public function test_staff_form_validates_the_mobile_inline(): void
    {
        $html = $this->page('staff.create')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form[^>]*\sdata-validate/', $html);
        $this->assertRule($html, 'mobile_number', 'mobile');
        $this->assertStringNotContainsString('data-autosave=', $html);
    }

    public function test_register_page_has_rules_but_no_scope_and_no_drafts(): void
    {
        auth()->logout();
        $html = $this->get('/register')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form[^>]*action="\/register"[^>]*\sdata-validate/', $html);
        $this->assertRule($html, 'mobile', 'mobile');
        $this->assertDoesNotMatchRegularExpression('/name="ux-scope" content="[0-9a-f]{16}"/', $html);
        $this->assertStringNotContainsString('data-autosave', $html);
    }

    public function test_onboarding_forms_validate_phone_and_prefix(): void
    {
        $owner = $this->newOwner();
        $html = $this->actingAs($owner)->get(route('dhiran.onboarding'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form[^>]*\sdata-validate/', $html);
        $this->assertRule($html, 'phone', 'mobile');
        $this->assertRule($html, 'loan_number_prefix', 'prefix');
        $this->assertStringNotContainsString('data-autosave=', $html);
    }

    public function test_resubmit_form_validates_phone_and_prefix(): void
    {
        $owner = $this->newOwner();
        $this->actingAs($owner)->post(route('dhiran.onboarding.store'), ['name' => 'Fix Me Co', 'phone' => '9876543210', 'address' => 'Main Road', 'loan_number_prefix' => 'FMC']);
        Shop::where('name', 'Fix Me Co')->update(['status' => 'rejected', 'review_note' => 'Check the phone', 'reviewed_at' => now()]);
        $html = $this->actingAs($owner->fresh())->get(route('dhiran.pending'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*resubmit"[^>]*\sdata-validate/', $html);
        $this->assertRule($html, 'phone', 'mobile');
        $this->assertRule($html, 'loan_number_prefix', 'prefix');
    }
}
