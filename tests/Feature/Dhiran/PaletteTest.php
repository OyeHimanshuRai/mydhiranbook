<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Command palette: the search endpoint is this shop's only, safe for any typing, and the page lists only links the user may open. */
class PaletteTest extends TestCase
{
    use RefreshDatabase;

    private $shop;
    private $other;
    private User $owner;
    private User $otherOwner;
    private Customer $ram;
    private Customer $otherRam;
    private DhiranLoan $loan;
    private DhiranLoan $otherLoan;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->shop, $this->owner] = $this->makeShop('9390025100');
        [$this->other, $this->otherOwner] = $this->makeShop('9390025200');
        $this->ram = $this->person($this->shop, 'Ramesh', 'Palette', '9815625100');
        $this->otherRam = $this->person($this->other, 'Ramesh', 'Outsider', '9815625200');
        $this->loan = $this->loanFor($this->shop, $this->ram, $this->owner);
        $this->otherLoan = $this->loanFor($this->other, $this->otherRam, $this->otherOwner);
    }

    /** @param list<string>|null $permissions null = every permission */
    private function makeShop(string $mobile, ?array $permissions = null): array
    {
        $shop = self::createDhiranShop(['name' => 'Shop ' . $mobile, 'shop_type' => 'dhiran', 'phone' => $mobile, 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => $mobile, 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);

        return [$shop, $this->makeUser($shop, $mobile, 'owner', $permissions)];
    }

    private function makeUser($shop, string $mobile, string $roleName, ?array $permissions = null): User
    {
        $role = new Role();
        $role->forceFill(['name' => $roleName, 'display_name' => $roleName, 'shop_id' => $shop->id])->save();
        $role->permissions()->sync($permissions === null ? Permission::query()->pluck('id') : Permission::whereIn('name', $permissions)->pluck('id'));

        return User::create(['mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => $mobile . '@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
    }

    private function person($shop, string $first, string $last, string $mobile): Customer
    {
        return TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => $first, 'last_name' => $last, 'mobile' => $mobile]));
    }

    private function loanFor($shop, Customer $customer, User $by): DhiranLoan
    {
        $this->actingAs($by);

        return TenantContext::runFor($shop->id, fn () => app(DhiranService::class)->createLoan($shop, $customer, [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000]],
            ['principal_amount' => 50000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 12, 'grace_period_days' => 5, 'loan_date' => now()->toDateString(), 'created_by' => $by->id]));
    }

    private function find(string $q, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->getJson(route('dhiran.palette', ['q' => $q]));
    }

    public function test_finds_this_shops_loan_by_number_and_by_borrower_name_and_nothing_else(): void
    {
        $byNumber = $this->find($this->loan->loan_number)->assertOk()->json();
        $loans = array_values(array_filter($byNumber, fn ($r) => $r['type'] === 'loan'));
        $this->assertCount(1, $loans);
        $this->assertSame($this->loan->loan_number, $loans[0]['label']);
        $this->assertSame(route('dhiran.show', $this->loan, false), $loans[0]['url']);
        $this->assertStringContainsString('Ramesh Palette', $loans[0]['sub']);

        $byName = $this->find('ramesh pal')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['loan', 'customer'], array_column($byName, 'type'));
        $customer = array_values(array_filter($byName, fn ($r) => $r['type'] === 'customer'))[0];
        $this->assertSame('Ramesh Palette', $customer['label']);
        $this->assertSame(route('dhiran.borrowers.show', $this->ram, false), $customer['url']);

        $this->assertNotEmpty($this->find('9815625100')->json(), 'mobile finds the customer');
    }

    public function test_never_returns_another_shops_rows(): void
    {
        foreach (['Ramesh', 'Outsider', '9815625200', $this->otherLoan->loan_number] as $q) {
            foreach ($this->find($q)->assertOk()->json() as $row) {
                $this->assertStringNotContainsString('Outsider', $row['label'] . $row['sub'], "leak for q=$q");
                $this->assertNotSame(route('dhiran.show', $this->otherLoan, false), $row['url'], "leak for q=$q");
                $this->assertNotSame(route('dhiran.borrowers.show', $this->otherRam, false), $row['url'], "leak for q=$q");
            }
        }
        $this->assertSame([], $this->find('Outsider')->json());
        $this->assertSame([], $this->find('9815625200')->json());
        // and the other way round
        $this->assertSame([], $this->find('Palette', $this->otherOwner)->json());
    }

    public function test_a_user_without_view_permission_is_refused(): void
    {
        $nobody = $this->makeUser($this->shop, '9390025300', 'nobody', []);
        $this->find('Ramesh', $nobody)->assertForbidden();
    }

    public function test_short_long_and_hostile_input_never_errors_and_never_matches_everything(): void
    {
        foreach (['', ' ', 'r', '%', '_', str_repeat('a', 61), str_repeat('x', 10000)] as $q) {
            $this->find($q)->assertStatus(422);
        }
        $this->actingAs($this->owner)->getJson(route('dhiran.palette') . '?q[]=Ramesh')->assertStatus(422);
        $this->actingAs($this->owner)->getJson(route('dhiran.palette') . '?q=ab%00cd')->assertStatus(422);
        $this->actingAs($this->owner)->getJson(route('dhiran.palette') . '?q=%FF%FEab')->assertStatus(422);
        $this->actingAs($this->owner)->getJson(route('dhiran.palette'))->assertStatus(422);

        foreach (['%%', '__', '%_', '\\\\', "' OR '1'='1", "Ramesh'; DROP TABLE customers;--", '" OR ""="', '<script>alert(1)</script>', 'ab%', '%ab', str_repeat('a', 60)] as $q) {
            $res = $this->find($q);
            $res->assertOk();
            $this->assertSame([], $res->json(), "q=$q must match nothing");
        }
        // % and _ are literal, not wildcards: "R_mesh" must not find "Ramesh"
        $this->assertSame([], $this->find('R_mesh')->json());
        $this->assertSame([], $this->find('R%h')->json());
    }

    public function test_results_carry_only_the_documented_keys_and_stay_within_six_each(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->person($this->shop, 'Ramesh', 'Many' . $i, '98156252' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $rows = $this->find('Ramesh')->assertOk()->json();
        foreach ($rows as $row) {
            $this->assertSame(['label', 'sub', 'type', 'url'], collect($row)->keys()->sort()->values()->all());
            $this->assertContains($row['type'], ['loan', 'customer']);
            $this->assertStringStartsWith('/', $row['url']);
        }
        $this->assertSame(6, count(array_filter($rows, fn ($r) => $r['type'] === 'customer')));
    }

    private function paletteLabels($response): array
    {
        $this->assertMatchesRegularExpression('/id="dhPalette"[^>]*data-items="([^"]*)"/', $html = $response->getContent());
        preg_match('/id="dhPalette"[^>]*data-items="([^"]*)"/', $html, $m);

        return array_column(json_decode(html_entity_decode($m[1]), true), 'label');
    }

    public function test_the_page_carries_the_palette_and_go_to_links_follow_permissions(): void
    {
        $page = $this->actingAs($this->owner)->get(route('dhiran.loans'))->assertOk()
            ->assertSee('data-palette-open', false)->assertSee('role="combobox"', false)->assertSee('id="dhShortcuts"', false);
        // Live test 2026-11-26: the floating phone button covered form labels and the Save bar; phones get a header search button instead.
        $this->assertMatchesRegularExpression('/class="dh-bell dh-search-btn" data-palette-open/', $page->getContent());
        $all = $this->paletteLabels($page);
        foreach (['Loans', 'New loan', 'Customers', 'Old loans', 'Reports hub', 'Report: Active', 'Report: Cash book', 'Settings: Loan', 'Settings: Receipt', 'Settings: Notifications'] as $label) {
            $this->assertContains($label, $all);
        }

        $viewer = $this->makeUser($this->shop, '9390025400', 'viewer', ['dhiran.view']);
        $labels = $this->paletteLabels($this->actingAs($viewer)->get(route('dhiran.loans'))->assertOk());
        $this->assertEqualsCanonicalizing(['Loans', 'Customers'], $labels);
        // the help window lists only shortcuts that work for this user
        $html = $this->actingAs($viewer)->get(route('dhiran.loans'))->getContent();
        $help = substr($html, (int) strpos($html, 'id="dhShortcuts"'));
        $help = substr($help, 0, (int) strpos($help, '</dialog>'));
        $this->assertStringContainsString('Customers', $help);
        foreach (['New loan', 'Settings', 'Reports'] as $absent) {
            $this->assertStringNotContainsString($absent, $help);
        }
    }
}
