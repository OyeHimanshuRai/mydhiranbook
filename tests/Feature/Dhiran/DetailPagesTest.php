<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
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
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Phase 3B (owner 2026-11-22): loan detail, customer profile and the activation screen. What a clerk must always find:
 * the next action for the loan's state, destructive actions with words, labelled amount fields, no sideways table scrolling.
 */
class DetailPagesTest extends TestCase
{
    use RefreshDatabase;

    private DhiranService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DhiranService::class);
    }

    /** @return array{0:Shop,1:User} */
    private function shopWithOwner(string $mobile): array
    {
        $shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => $mobile,
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => $mobile,
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        return [$shop, $owner];
    }

    private function loan(Shop $shop, User $owner, string $status = 'active'): DhiranLoan
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $owner, $status) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'Asha', 'last_name' => 'Borrower', 'mobile' => '98' . fake()->unique()->numerify('########')]);

            return $this->service->createLoan($shop, $c, [[
                'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
            ]], ['principal_amount' => 200000, 'gold_rate_on_date' => 6000, 'created_by' => $owner->id, 'status' => $status]);
        });
    }

    private function show(Shop $shop, User $owner, int $loanId): string
    {
        return TenantContext::runFor($shop->id, function () use ($owner, $loanId) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $loan = DhiranLoan::with(['customer', 'items', 'payments'])->findOrFail($loanId);

            return view('dhiran.show', ['loan' => $loan, 'attachments' => collect(), 'evidence' => $this->service->evidenceStatus($loan)])->render();
        });
    }

    public function test_active_loan_keeps_its_main_actions_in_view_and_the_rest_under_more_actions(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390002001');
        $html = $this->show($shop, $owner, $this->loan($shop, $owner)->id);

        $main = strpos($html, 'dd-act-main');
        $more = strpos($html, 'dd-more-toggle');
        $this->assertNotFalse($main);
        $this->assertGreaterThan($main, $more, 'the more menu comes after the primary actions');
        $primary = substr($html, $main, $more - $main);
        $this->assertStringContainsString('Pay Interest', $primary);
        $this->assertStringContainsString('Repay', $primary);
        foreach (['Release Item', 'Renew', 'Pre-Close'] as $secondary) {
            $this->assertStringNotContainsString($secondary, $primary, "{$secondary} is not a primary action");
            $this->assertStringContainsString($secondary, substr($html, $more, 6000), "{$secondary} stays reachable under More actions");
        }
        $this->assertStringContainsString('More actions', $html);
    }

    public function test_pending_loan_offers_activation_and_cancel_with_words(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390002002');
        $html = $this->show($shop, $owner, $this->loan($shop, $owner, 'pending_evidence')->id);

        $this->assertStringContainsString('Evidence required before activation', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>Activate Loan<\/button>/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*btn-danger[^>]*>Cancel loan<\/button>/', $html, 'a destructive action keeps its words');
        $this->assertStringNotContainsString('Pay Interest</button>', substr($html, 0, strpos($html, 'id="payInterestModal"')), 'no payment actions before activation');
    }

    public function test_destructive_actions_are_never_icon_only(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390002003');
        $html = $this->show($shop, $owner, $this->loan($shop, $owner)->id);

        $this->assertMatchesRegularExpression('/btn-danger[^>]*>\s*<svg[^>]*>.*?<\/svg>Pre-Close<\/button>/s', $html);
        // The icon-collapse pattern is for print and back only: its text stays in the page for screen readers.
        preg_match_all('/<(?:a|button)[^>]*btn-collapse[^>]*>.*?<\/(?:a|button)>/s', $html, $collapse);
        $this->assertNotEmpty($collapse[0]);
        foreach ($collapse[0] as $button) {
            $this->assertStringContainsString('<span class="btn-text">', $button);
            $this->assertDoesNotMatchRegularExpression('/Pre-Close|Execute Forfeit|Cancel loan|Release|Renew/', $button);
        }
        $this->assertStringContainsString('<span class="btn-text">Pledge Receipt</span>', $html);
        $this->assertStringContainsString('<span class="btn-text">All Loans</span>', $html);
    }

    public function test_every_dialog_has_labelled_amount_fields_and_a_busy_submit(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390002004');
        $html = $this->show($shop, $owner, $this->loan($shop, $owner)->id);

        foreach (['payInterestModal', 'repayModal', 'releaseItemModal', 'precloseModal', 'renewModal'] as $id) {
            $this->assertStringContainsString('<dialog id="' . $id . '" class="dh-dialog dd-sheet"', $html);
        }
        preg_match_all('/<input type="number"[^>]*name="(amount|payment_amount)"[^>]*>/', $html, $amounts);
        $this->assertCount(3, $amounts[0], 'pay interest, repay and release each take an amount');
        foreach ($amounts[0] as $input) {
            $this->assertStringContainsString('inputmode="decimal"', $input);
            preg_match('/ id="([^"]+)"/', $input, $id);
            $this->assertStringContainsString('<label for="' . $id[1] . '">', $html, 'the amount field is labelled');
        }
        $this->assertGreaterThanOrEqual(5, substr_count($html, 'data-turbo-submits-with'), 'a submit is disabled while it sends');
        $this->assertGreaterThanOrEqual(5, substr_count($html, 'name="idempotency_key"'), 'every money form still carries its key');
        $this->assertStringContainsString('₹2,00,000.00', $html, 'lakh grouping, nothing rounded away');
    }

    public function test_tables_never_scroll_sideways_and_turn_into_cards_by_css(): void
    {
        foreach (['dhiran/show', 'dhiran/borrowers/show'] as $view) {
            $source = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringNotContainsString('overflow-x-auto', $source, $view);
            $this->assertDoesNotMatchRegularExpression('/min-w-\[\d+px\]/', $source, $view);
        }
        $this->assertStringContainsString('@container dd (max-width: 859.98px)', file_get_contents(resource_path('css/dhiran-detail.css')));
    }

    public function test_customer_profile_renders_its_sections(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390002005');
        $loan = $this->loan($shop, $owner);
        $html = TenantContext::runFor($shop->id, function () use ($owner, $loan) {
            $this->actingAs($owner);
            $this->app->instance('request', Request::create('/dhiran/borrowers/' . $loan->customer_id, 'GET'));
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return app(DhiranController::class)->borrowerProfile(Customer::findOrFail($loan->customer_id))->render();
        });

        foreach (['id="loans" data-profile-section', 'id="items" data-profile-section', 'id="documents"', 'Customer details'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringContainsString('href="#loans" class="dd-jump-link"', $html);
        $this->assertStringContainsString('href="#documents" class="dd-jump-link"', $html);
        $this->assertStringContainsString($loan->loan_number, $html);
        $this->assertStringContainsString('View loan', $html);
        $this->assertStringContainsString('class="dd-table dd-t-rec"', $html);
    }

    public function test_activation_screen_has_one_primary_action_and_says_what_happens_next(): void
    {
        [$shop, $owner] = $this->shopWithOwner('9390002006');
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => false]);
        $html = TenantContext::runFor($shop->id, function () use ($owner) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return view('dhiran.activate')->render();
        });

        $this->assertSame(1, substr_count($html, route('dhiran.activate')), 'one form posts the activation');
        $this->assertStringContainsString('What happens next', $html);
        $this->assertStringContainsString('Activate Dhiran Module', $html);
        $this->assertStringNotContainsString('da-feature-card', $html, 'no feature grid');
    }
}
