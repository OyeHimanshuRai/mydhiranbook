<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Dhiran\LoanEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bulk actions on the Loans list: row checkboxes and action bar, CSV export of the ticked loans, one item tag per ticked loan. Nothing destructive. */
class BulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';
    private $shop;
    private $other;
    private User $owner;
    /** @var list<DhiranLoan> */
    private array $loans = [];
    private DhiranLoan $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = $this->shopWith('Bulk Co', '9390025000');
        $this->other = $this->shopWith('Other Co', '9390025001');
        $this->owner = $this->ownerOf($this->shop, '9390025000', 'o@example.com');
        $customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Bulk', 'last_name' => 'Holder', 'mobile' => '9815620001']));
        foreach ([21000, 22000, 23000, 24000] as $principal) {
            $this->loans[] = $this->makeLoan($this->shop, $customer, $this->owner, $principal);
        }
        $theirOwner = $this->ownerOf($this->other, '9390025001', 'x@example.com');
        $theirs = TenantContext::runFor($this->other->id, fn () => Customer::create(['shop_id' => $this->other->id, 'first_name' => 'Foreign', 'last_name' => 'Person', 'mobile' => '9815620002']));
        DhiranSettings::getForShop($this->other->id)->update(['loan_number_prefix' => 'XF-']); // numbers are per shop: keep the foreign one distinguishable
        $this->foreign = $this->makeLoan($this->other, $theirs, $theirOwner, 25000);
    }

    private function shopWith(string $name, string $phone)
    {
        $shop = self::createDhiranShop(['name' => $name, 'shop_type' => 'dhiran', 'phone' => $phone, 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => $phone, 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);

        return $shop;
    }

    private function ownerOf($shop, string $mobile, string $email): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));

        return User::create(['mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => $email,
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
    }

    private function makeLoan($shop, $customer, User $by, int $principal): DhiranLoan
    {
        $this->actingAs($by);

        return TenantContext::runFor($shop->id, fn () => app(DhiranService::class)->createLoan($shop, $customer, [
            ['description' => 'Gold chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10.5, 'stone_weight' => 0.5, 'rate_per_gram_at_pledge' => 6000],
        ], ['principal_amount' => $principal, 'gold_rate_on_date' => 6000, 'created_by' => $by->id]));
    }

    private function fetch(string $path, ?User $as = null)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($as ?? $this->owner)->get(self::HOST . $path));
    }

    /** @param list<int> $ids */
    private function idsQuery(array $ids): string
    {
        return http_build_query(['ids' => $ids]);
    }

    /** CSV loan numbers of a download response. @return list<string> */
    private function numbersIn($res): array
    {
        $body = ltrim(TenantContext::runFor($this->shop->id, fn () => $res->streamedContent()), "\xEF\xBB\xBF"); // the rows are read lazily: inside the shop context
        $h = fopen('php://memory', 'r+');
        fwrite($h, $body);
        rewind($h);
        $header = fgetcsv($h, 0, ',', '"', '');
        $col = array_search('Loan number', $header, true);
        $out = [];
        while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($row !== [null]) {
                $out[] = $row[$col];
            }
        }

        return $out;
    }

    public function test_the_loans_page_has_row_checkboxes_and_the_action_bar(): void
    {
        $html = $this->fetch('/dhiran/loans')->assertOk()->getContent();

        foreach ($this->loans as $loan) {
            $this->assertStringContainsString('aria-label="Select loan ' . $loan->loan_number . '"', $html);
            $this->assertStringContainsString('data-bulk-id="' . $loan->id . '"', $html);
        }
        $this->assertStringNotContainsString('data-bulk-id="' . $this->foreign->id . '"', $html, 'another shop\'s loan is never listed');
        foreach (['aria-label="Select all on this page"', 'data-bulk-bar', 'aria-live="polite"', 'Export CSV', 'Print item tags', 'data-bulk-clear', '/dhiran/loans/tags', 'rel="noopener"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertDoesNotMatchRegularExpression('/data-bulk[^>]*(close|forfeit|delete)/i', $html, 'nothing destructive in bulk');
    }

    public function test_csv_with_ids_holds_exactly_those_loans(): void
    {
        $pick = [$this->loans[0]->id, $this->loans[2]->id];
        $res = $this->fetch('/dhiran/loans?export=csv&' . $this->idsQuery($pick))->assertOk();

        $this->assertEqualsCanonicalizing([$this->loans[0]->loan_number, $this->loans[2]->loan_number], $this->numbersIn($res));
    }

    public function test_csv_without_ids_still_exports_every_loan_of_the_shop(): void
    {
        $numbers = $this->numbersIn($this->fetch('/dhiran/loans?export=csv')->assertOk());

        $this->assertCount(4, $numbers);
        $this->assertNotContains($this->foreign->loan_number, $numbers);
    }

    public function test_csv_ignores_another_shops_ids(): void
    {
        $res = $this->fetch('/dhiran/loans?export=csv&' . $this->idsQuery([$this->loans[1]->id, $this->foreign->id]))->assertOk();

        $this->assertSame([$this->loans[1]->loan_number], $this->numbersIn($res));
    }

    public function test_csv_ids_still_obey_the_filters(): void
    {
        DhiranLoan::withoutGlobalScopes()->whereKey($this->loans[0]->id)->update(['status' => 'closed']);
        $res = $this->fetch('/dhiran/loans?export=csv&status=closed&' . $this->idsQuery([$this->loans[0]->id, $this->loans[1]->id]))->assertOk();

        $this->assertSame([$this->loans[0]->loan_number], $this->numbersIn($res));
    }

    public function test_csv_rejects_too_many_or_non_numeric_ids_without_a_500(): void
    {
        $tooMany = $this->fetch('/dhiran/loans?export=csv&' . $this->idsQuery(range(1, 101)));
        $this->assertContains($tooMany->getStatusCode(), [302, 422]);
        $tooMany->assertSessionHasErrors('ids');

        foreach (['ids[]=abc', 'ids[]=1.5', 'ids[]=0', 'ids[]=-3', 'ids[]=99999999999999999999', 'ids=7', 'ids[]=', 'ids[a][b]=1'] as $bad) {
            $res = $this->fetch('/dhiran/loans?export=csv&' . $bad);
            $this->assertContains($res->getStatusCode(), [302, 422], $bad);
            $this->assertStringNotContainsString('text/csv', (string) $res->headers->get('Content-Type'), $bad);
        }

        $this->assertCount(4, $this->numbersIn($this->fetch('/dhiran/loans?export=csv&' . $this->idsQuery(array_merge(array_map(fn ($l) => $l->id, $this->loans), range(900001, 900096))))->assertOk()), 'exactly 100 ids are accepted');
    }

    public function test_bulk_tags_render_one_slip_per_own_loan_and_log_each_print_once(): void
    {
        $pick = [$this->loans[0]->id, $this->loans[3]->id, $this->foreign->id];
        $html = $this->fetch('/dhiran/loans/tags?' . $this->idsQuery($pick))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="tag"'), 'two own loans, the foreign id is skipped');
        $this->assertStringContainsString($this->loans[0]->loan_number, $html);
        $this->assertStringContainsString($this->loans[3]->loan_number, $html);
        $this->assertStringNotContainsString($this->foreign->loan_number, $html);
        $this->assertStringNotContainsString('Foreign Person', $html);
        $this->assertStringContainsString('size: 58mm auto', $html);
        $this->assertStringContainsString('page-break-after: always', $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringContainsString('size: 80mm auto', $this->fetch('/dhiran/loans/tags?w=80&' . $this->idsQuery([$this->loans[0]->id]))->getContent());

        $printed = fn (int $id) => LoanEvent::withoutGlobalScopes()->where('dhiran_loan_id', $id)->where('event', 'document.printed')->count();
        $this->assertSame(2, $printed($this->loans[0]->id), 'once per page view (the 80 mm view is a second print)');
        $this->assertSame(1, $printed($this->loans[3]->id));
        $this->assertSame(0, $printed($this->foreign->id));
        $this->assertSame(0, $printed($this->loans[1]->id));
    }

    public function test_bulk_tags_need_at_least_one_own_loan_and_valid_ids(): void
    {
        $this->fetch('/dhiran/loans/tags?' . $this->idsQuery([$this->foreign->id]))->assertNotFound();
        $this->fetch('/dhiran/loans/tags?' . $this->idsQuery([987654321]))->assertNotFound();
        $this->fetch('/dhiran/loans/tags')->assertStatus(422);
        $this->fetch('/dhiran/loans/tags?ids[]=abc')->assertStatus(422);
        $this->fetch('/dhiran/loans/tags?ids=5')->assertStatus(422);
        $this->fetch('/dhiran/loans/tags?' . $this->idsQuery(range(1, 101)))->assertStatus(422);
    }

    public function test_bulk_tags_need_the_view_permission(): void
    {
        $role = new Role();
        $role->forceFill(['name' => 'nobody', 'display_name' => 'Nobody', 'shop_id' => $this->shop->id])->save();
        $staff = User::create(['mobile_number' => '9390025009', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 's@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);

        $this->fetch('/dhiran/loans/tags?' . $this->idsQuery([$this->loans[0]->id]), $staff)->assertForbidden();
    }

    public function test_the_single_tag_page_is_unchanged(): void
    {
        $html = $this->fetch("/dhiran/loans/{$this->loans[0]->id}/tag")->assertOk()->getContent();

        foreach ([$this->loans[0]->loan_number, 'Bulk Holder', 'Gold chain', 'Gold 22K', '10.500', '10.000', 'Locker / rack no.', 'Packed by', 'Print tag', 'Use 80 mm', '1 item', 'size: 58mm auto'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertSame(1, substr_count($html, 'class="tag"'));
        $this->assertStringNotContainsString('9815620001', $html);
    }
}
