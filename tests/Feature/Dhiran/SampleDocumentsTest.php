<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A new shop can see each document before it has a single loan: made-up records, never saved, with the owner's saved switches applied. */
class SampleDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';
    private $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = self::createDhiranShop(['name' => 'Fresh Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390026000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390026000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true]);
        $this->owner = $this->userWith('9390026000', 'owner', Permission::query()->pluck('id'));
    }

    private function userWith(string $mobile, string $role, $permissions): User
    {
        $r = new Role();
        $r->forceFill(['name' => $role, 'display_name' => ucfirst($role), 'shop_id' => $this->shop->id])->save();
        $r->permissions()->sync($permissions);

        return User::create(['mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => $mobile . '@example.com', 'name' => ucfirst($role),
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $r->id]);
    }

    private function sample(string $doc, ?User $as = null)
    {
        $as ??= $this->owner;

        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($as)->get(self::HOST . "/dhiran/settings/receipt/sample/{$doc}"));
    }

    public function test_every_document_has_a_sample_even_with_no_loans_and_nothing_is_saved(): void
    {
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->count());
        foreach (['pledge' => 'Pledge Receipt', 'payment' => 'Payment Receipt', 'notice' => 'Forfeiture Notice'] as $doc => $title) {
            $html = $this->sample($doc)->assertOk()->getContent();
            $this->assertStringContainsString('SAMPLE', $html, "{$doc} is marked as a sample");
            $this->assertStringContainsString('made-up details', $html);
            $this->assertStringContainsString('Sample Borrower', $html);
            $this->assertStringContainsString('DH-000001', $html, 'the loan number is always there');
            $this->assertStringContainsString($title, $html);
        }
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->count());
        $this->assertSame(0, DhiranPayment::withoutGlobalScopes()->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('dhiran_loan_events')->count(), 'a sample leaves no trace in any log');
    }

    public function test_a_sample_shows_the_saved_switches(): void
    {
        $with = fn (string $doc) => substr($this->sample($doc)->getContent(), strpos($this->sample($doc)->getContent(), '<body'));
        $this->assertStringContainsString('Signature', $this->sample('pledge')->getContent());

        DhiranSettings::getForShop($this->shop->id)->update(['hidden_receipt_fields' => ['pledge.signatures', 'payment.signatures', 'notice.warning', 'notice.total_due']]);
        $this->assertStringNotContainsString('class="rc-sigs"', $this->sample('pledge')->getContent());
        $this->assertStringNotContainsString('WARNING:', $this->sample('notice')->getContent());
        $this->assertStringNotContainsString('Total Outstanding', $this->sample('notice')->getContent());
        $this->assertStringContainsString('DH-000001', $this->sample('notice')->getContent());
    }

    public function test_the_sample_follows_the_loan_number_setting(): void
    {
        DhiranSettings::getForShop($this->shop->id)->update(['loan_number_digits' => 1, 'loan_number_prefix' => 'GR-']);
        $this->assertStringContainsString('GR-1', $this->sample('pledge')->getContent());
    }

    public function test_a_sample_has_no_editor_and_only_people_who_may_change_settings_can_open_it(): void
    {
        $this->assertStringNotContainsString('Edit notice', $this->sample('notice')->getContent());
        $cashier = $this->userWith('9390026001', 'cashier', Permission::whereIn('name', ['dhiran.view', 'dhiran.pay'])->pluck('id'));
        $this->sample('pledge', $cashier)->assertForbidden();
        TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(self::HOST . '/dhiran/settings/receipt/sample/closure'))->assertNotFound();
    }

    public function test_receipt_settings_links_a_sample_for_each_document(): void
    {
        $html = TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(self::HOST . '/dhiran/settings/receipt'))->assertOk()->getContent();
        foreach (['pledge', 'payment', 'notice'] as $doc) {
            $this->assertStringContainsString("/dhiran/settings/receipt/sample/{$doc}", $html);
        }
        $this->assertStringContainsString('See a sample', $html);
    }
}
