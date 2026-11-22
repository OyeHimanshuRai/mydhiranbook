<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 3A (owner 2026-11-22): New Loan, Old Loan (+ file import) and Add Customer are one-column forms that fit every
 * screen. The markup carries the contract: one form, a label for every control, the totals bar with the submit button,
 * pledged-item cards, collapse buttons that keep their text, and errors named per field.
 */
class FormsRedesignTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';
    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = self::createDhiranShop(['name' => 'Forms Co', 'shop_type' => 'dhiran', 'phone' => '9390022000', 'owner_first_name' => 'O',
            'owner_last_name' => 'W', 'owner_mobile' => '9390022000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390022000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'name' => 'Owner',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
    }

    private function in(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, function () use ($fn) {
            $this->actingAs($this->owner);

            return $fn();
        });
    }

    private function page(string $path): string
    {
        return $this->in(fn () => $this->get(self::HOST . $path))->assertOk()->getContent();
    }

    public function test_new_loan_is_one_form_with_a_label_for_every_control(): void
    {
        $html = $this->page('/dhiran/create');

        $this->assertSame(1, preg_match_all('/<form[^>]*class="fm-page dhiran-create-form"/', $html), 'one form');
        $this->assertSame(1, substr_count($html, 'name="idempotency_key"'));
        $this->assertSame(1, substr_count($html, 'name="_token"') - substr_count($html, 'action="' . route('logout') . '"'));
        foreach (['principal_amount', 'gold_rate_on_date', 'interest_rate_monthly', 'interest_type', 'tenure_months', 'penalty_rate_monthly', 'notes', 'fm-customer-search'] as $id) {
            $this->assertMatchesRegularExpression('/<label[^>]*for="' . $id . '"/', $html, "label for #$id");
            $this->assertMatchesRegularExpression('/id="' . $id . '"/', $html, "#$id exists");
        }
        // Pledged-item controls are drawn per item by Alpine: each label is bound to its control's id.
        foreach (['desc', 'metal', 'mode', 'purity', 'rate', 'value', 'gross', 'stone', 'huid'] as $k) {
            $this->assertStringContainsString(":for=\"'it-$k-' + index\"", $html);
            $this->assertStringContainsString(":id=\"'it-$k-' + index\"", $html);
        }
        // The posted field names are unchanged.
        foreach (['description', 'metal_type', 'value_mode', 'purity', 'rate_per_gram_at_pledge', 'market_value', 'gross_weight', 'stone_weight', 'huid', 'loan_value', 'net_metal_weight', 'fine_weight'] as $f) {
            $this->assertStringContainsString("'items['+index+'][$f]'", $html);
        }
        $this->assertStringContainsString('name="customer_id"', $html);
        $this->assertStringContainsString('name="pan"', $html);
        $this->assertStringContainsString('name="aadhaar"', $html);
        $this->assertStringContainsString('name="processing_fee"', $html);
    }

    public function test_new_loan_has_steps_item_cards_totals_bar_and_the_submit_inside_it(): void
    {
        $html = $this->page('/dhiran/create');

        foreach (['fm-step-customer', 'fm-step-items', 'fm-step-terms', 'fm-step-kyc'] as $step) {
            $this->assertStringContainsString('id="' . $step . '"', $html);
            $this->assertStringContainsString('href="#' . $step . '"', $html, 'step strip links to it');
        }
        $this->assertStringContainsString('x-for="(item, index) in items"', $html);
        $this->assertStringContainsString('<fieldset class="fm-item"', $html);
        $this->assertMatchesRegularExpression('/data-principal-limit[^>]*x-text="principalHint"/', $html);
        $this->assertStringContainsString('inputmode="decimal"', $html);

        $this->assertSame(1, preg_match('/<div class="fm-bar".*?<\/form>/s', $html, $m));
        foreach (['Principal', 'Max loan', 'Items total', 'type="submit"', ':disabled="submitting"'] as $needle) {
            $this->assertStringContainsString($needle, $m[0]);
        }
        $this->assertStringContainsString('<dialog class="fm-dialog"', $html, 'borrower sheet is a native dialog');
    }

    public function test_collapse_buttons_keep_their_text_for_screen_readers(): void
    {
        foreach (['/dhiran/create' => 'Back to Loans', '/dhiran/old-loans/create' => 'Old Loans', '/dhiran/old-loans/upload' => 'Old Loans', '/dhiran/borrowers/create' => 'Back to Customers'] as $path => $label) {
            $this->assertMatchesRegularExpression('/btn-collapse[^>]*>.*?<span class="btn-text">' . preg_quote($label, '/') . '<\/span>/s', $this->page($path), $path);
        }
    }

    public function test_a_refused_new_loan_names_the_problem_at_the_top_and_at_the_field(): void
    {
        $this->in(fn () => $this->from(self::HOST . '/dhiran/create')->post(self::HOST . '/dhiran', [
            'idempotency_key' => 'forms-test-0001', 'principal_amount' => '1000', 'interest_rate_monthly' => '2', 'interest_type' => 'flat', 'tenure_months' => '6',
            'items' => [['description' => 'Chain', 'metal_type' => 'gold', 'gross_weight' => 10, 'purity' => 22]],
        ]))->assertSessionHasErrors('customer_id');
        $html = $this->in(fn () => $this->get(self::HOST . '/dhiran/create'))->getContent();

        $this->assertStringContainsString('class="fm-alert" role="alert"', $html);
        $this->assertStringContainsString('The loan was not created', $html);
        $this->assertStringContainsString('id="err-customer_id"', $html);
        $this->assertStringContainsString('aria-describedby="err-customer_id"', $html);
    }

    public function test_old_loan_form_has_numbered_steps_labelled_fields_and_a_save_bar(): void
    {
        $html = $this->page('/dhiran/old-loans/create');

        $this->assertSame(1, preg_match_all('/<form[^>]*id="ol-form"/', $html));
        foreach (['paper_loan_number', 'loan_date', 'principal_amount', 'principal_owed', 'interest_rate_monthly', 'tenure_months', 'interest_paid_until', 'unpaid_interest', 'interest_type', 'notes', 'customer_id', 'borrower_first_name', 'borrower_mobile'] as $id) {
            $this->assertMatchesRegularExpression('/<label[^>]*for="' . $id . '"/', $html, "label for #$id");
        }
        $this->assertStringContainsString('name="borrower[first_name]"', $html);
        $this->assertStringContainsString('x-for="(item, i) in items"', $html);
        $this->assertStringContainsString('items[${i}][description]', $html);
        $this->assertMatchesRegularExpression('/<div class="fm-bar".*Bring in loan/s', $html);
        $this->assertStringContainsString('name="idempotency_key"', $html);
    }

    public function test_upload_and_review_pages_show_the_three_step_flow(): void
    {
        $upload = $this->page('/dhiran/old-loans/upload');
        foreach (['Download template', 'Upload file', 'Review'] as $step) {
            $this->assertStringContainsString($step, $upload);
        }
        $this->assertStringContainsString(route('dhiran.old-loans.template'), $upload);
        $this->assertMatchesRegularExpression('/<input type="file" name="file" id="file" accept="\.csv,\.xlsx" required/', $upload);
        $this->assertStringContainsString('enctype="multipart/form-data"', $upload);

        $header = ['ticket_number', 'loan_date', 'customer_name', 'mobile', 'address', 'pan', 'id_number', 'amount_lent', 'principal_owed', 'interest_rate_monthly', 'interest_type', 'tenure_months', 'interest_paid_up_to', 'unpaid_interest', 'item_description', 'metal', 'purity', 'gross_weight_g', 'stone_weight_g', 'rate_per_gram', 'appraised_value'];
        $d = fn (int $n) => now()->subDays($n)->toDateString();
        $rows = [
            ['T-1', $d(300), 'Asha Rao', '9810040001', 'Road', '', '', '100000', '60000', '2', 'flat', '12', $d(30), '', 'Chain', 'gold', '22', '40', '', '6000', ''],
            ['T-2', $d(100), 'Bad Figures', '9810040002', '', '', '', '10000', '15000', '2', 'flat', '6', $d(5), '', 'Coin', 'gold', '24', '5', '', '6000', ''],
        ];
        $csv = implode("\n", array_map(fn ($r) => implode(',', $r), [$header, ...$rows])) . "\n";
        $loc = $this->in(fn () => $this->post(self::HOST . '/dhiran/old-loans/upload', ['file' => UploadedFile::fake()->createWithContent('book.csv', $csv)]))->assertRedirect()->headers->get('Location');
        $review = $this->in(fn () => $this->get($loc))->assertOk()->getContent();

        $this->assertStringContainsString('class="jf-table dh-stack"', $review, 'the shared ledger table');
        $this->assertStringNotContainsString('min-w-[900px]', $review, 'no sideways scroll');
        $this->assertMatchesRegularExpression('/data-ticket="T-1"[^>]*data-ok="1".*?dh-status--success[^>]*>Ready/s', $review);
        $this->assertMatchesRegularExpression('/data-ticket="T-2"[^>]*data-ok="0".*?dh-status--danger[^>]*>Will be skipped/s', $review);
        $this->assertStringContainsString('data-low="2"', $review, 'least important column is dropped before the table scrolls');
        $this->assertStringContainsString('data-label="Ticket #"', $review, 'phone cards read their labels from the cells');
        $this->assertMatchesRegularExpression('/<div class="fm-bar".*?action="[^"]*old-loans\/upload\/[^"]*".*?Bring in 1 loan/s', $review);
        $this->assertStringContainsString('name="idempotency_key"', $review);
    }

    public function test_add_customer_form_is_labelled_and_names_a_refusal(): void
    {
        $html = $this->page('/dhiran/borrowers/create');
        foreach (['first_name', 'last_name', 'mobile', 'pan', 'id_number', 'state_code', 'address', 'notes'] as $f) {
            $this->assertMatchesRegularExpression('/<label[^>]*for="bc-' . $f . '"/', $html, "label for $f");
            $this->assertMatchesRegularExpression('/name="' . $f . '"/', $html);
        }
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<div class="fm-bar".*Save customer/s', $html);

        $this->in(fn () => $this->from(self::HOST . '/dhiran/borrowers/create')->post(self::HOST . '/dhiran/customers', ['first_name' => 'Test', 'mobile' => '12']))->assertSessionHasErrors('mobile');
        $refused = $this->page('/dhiran/borrowers/create');
        $this->assertStringContainsString('The customer was not saved', $refused);
        $this->assertStringContainsString('id="bc-err-mobile"', $refused);
        $this->assertStringContainsString('aria-describedby="bc-err-mobile"', $refused);
    }
}
