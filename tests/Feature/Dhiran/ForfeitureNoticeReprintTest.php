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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** A forfeiture notice is a legal letter: printing it again later must not change the day it was issued or the deadline it gave. */
class ForfeitureNoticeReprintTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function noticeLoan(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00', 'Asia/Kolkata'));
        $shop = self::createDhiranShop(['name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390024000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390024000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0, 'forfeiture_notice_days' => 30]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create(['mobile_number' => '9390024000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'o@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
        $svc = app(DhiranService::class);
        $customer = TenantContext::runFor($shop->id, fn () => Customer::create(['shop_id' => $shop->id, 'first_name' => 'Late', 'last_name' => 'Payer', 'mobile' => '9815630001']));
        $loan = TenantContext::runFor($shop->id, fn () => $svc->createLoan($shop, $customer, [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000]],
            ['principal_amount' => 50000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 1, 'grace_period_days' => 5, 'loan_date' => '2026-01-01', 'created_by' => $owner->id]));

        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00', 'Asia/Kolkata'));   // long past maturity and grace
        $this->actingAs($owner);
        TenantContext::runFor($shop->id, fn () => $svc->sendForfeitureNotice(DhiranLoan::withoutGlobalScopes()->findOrFail($loan->id)));

        return [$shop, $owner, $loan];
    }

    private function page($shop, $user, $loan, string $qs = '')
    {
        return TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->get("http://127.0.0.1:8787/dhiran/loans/{$loan->id}/forfeiture-notice{$qs}"));
    }

    private function save($shop, $user, $loan, array $data)
    {
        return TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->patch("http://127.0.0.1:8787/dhiran/loans/{$loan->id}/forfeiture-notice", $data));
    }

    public function test_a_reprint_keeps_the_issue_date_and_the_deadline(): void
    {
        [$shop, $owner, $loan] = $this->noticeLoan();
        Carbon::setTestNow(Carbon::parse('2026-03-11 16:00', 'Asia/Kolkata')); // printed again ten days later
        $html = $this->page($shop, $owner, $loan)->assertOk()->getContent();

        $this->assertStringContainsString('Date: 01 Mar 2026', $html, 'the day the notice was issued, not the day it was printed');
        $this->assertStringNotContainsString('Date: 11 Mar 2026', $html);
        $this->assertStringContainsString('by 31 Mar 2026', $html, '30 days from the notice, not from the reprint');
    }

    public function test_the_owner_can_edit_the_notice_print_the_system_one_and_go_back_to_it(): void
    {
        [$shop, $owner, $loan] = $this->noticeLoan();
        $system = $this->page($shop, $owner, $loan)->getContent();
        $this->assertStringContainsString('Edit notice', $system);
        $this->assertStringContainsString('Print system notice', $system);

        $this->save($shop, $owner, $loan, ['body' => "Dear borrower, please visit the shop this week.\n\nWe will hold the ornaments until then.", 'warning' => 'FINAL REMINDER: settle by 31 Mar 2026.'])->assertRedirect()->assertSessionHas('success');
        $edited = $this->page($shop, $owner, $loan)->getContent();
        $this->assertStringContainsString('please visit the shop this week', $edited);
        $this->assertStringContainsString('FINAL REMINDER: settle by 31 Mar 2026.', $edited);
        $this->assertStringContainsString('Print edited notice', $edited);
        $this->assertStringContainsString('View system notice', $edited);

        $original = $this->page($shop, $owner, $loan, '?original=1')->getContent();
        $letter = fn (string $html) => substr($html, strpos($html, 'class="notice-body"'), 3000); // the printed letter, not the editor box that holds the saved wording
        $this->assertStringNotContainsString('please visit the shop this week', $letter($original), 'the system text prints as generated even after an edit');
        $this->assertStringContainsString('please visit the shop this week', $letter($edited));
        $this->assertStringContainsString('Print system notice', $original);
        $this->assertStringContainsString('WARNING: If the outstanding amount', $original);

        $this->save($shop, $owner, $loan, ['reset' => 1])->assertRedirect();
        $this->assertStringNotContainsString('please visit the shop this week', $this->page($shop, $owner, $loan)->getContent());
        $this->assertNull(DhiranLoan::withoutGlobalScopes()->find($loan->id)->forfeiture_notice_edit);
    }

    public function test_every_edit_and_reset_is_a_line_in_the_loans_log_with_the_wording(): void
    {
        [$shop, $owner, $loan] = $this->noticeLoan();
        $this->save($shop, $owner, $loan, ['body' => 'New wording of the letter.', 'warning' => 'New warning.']);
        $this->save($shop, $owner, $loan, ['reset' => 1]);

        $lines = \App\Models\Dhiran\LoanEvent::withoutGlobalScopes()->where('dhiran_loan_id', $loan->id)->where('event', 'document.edited')->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('Forfeiture notice wording edited', $lines[0]->summary);
        $this->assertSame('New wording of the letter.', $lines[0]->details['after']['body']);
        $this->assertSame('Forfeiture notice put back to the system-generated text', $lines[1]->summary);
        $this->assertSame('New wording of the letter.', $lines[1]->details['before']['body'], 'what was removed is still on record');
    }

    public function test_only_people_who_may_forfeit_can_edit_and_the_text_must_not_be_empty_or_huge(): void
    {
        [$shop, $owner, $loan] = $this->noticeLoan();
        $role = new Role();
        $role->forceFill(['name' => 'cashier', 'display_name' => 'Cashier', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::whereIn('name', ['dhiran.view', 'dhiran.pay'])->pluck('id'));
        $cashier = User::create(['mobile_number' => '9390024001', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'c@example.com', 'name' => 'Cashier',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->save($shop, $cashier, $loan, ['body' => 'x', 'warning' => 'y'])->assertForbidden();
        $this->assertStringNotContainsString('Edit notice', $this->page($shop, $cashier, $loan)->getContent(), 'they can still print the notice, not change it');
        $this->save($shop, $owner, $loan, ['body' => '', 'warning' => 'y'])->assertSessionHasErrors('body');
        $this->save($shop, $owner, $loan, ['body' => str_repeat('a', 5001), 'warning' => 'y'])->assertSessionHasErrors('body');
        $this->assertNull(DhiranLoan::withoutGlobalScopes()->find($loan->id)->forfeiture_notice_edit);
    }

    public function test_save_and_print_opens_the_print_dialog_and_a_closed_loan_cannot_be_edited(): void
    {
        [$shop, $owner, $loan] = $this->noticeLoan();
        $this->save($shop, $owner, $loan, ['body' => 'Letter.', 'warning' => 'Warn.', 'then' => 'print'])->assertRedirect(route('dhiran.forfeiture-notice', ['loan' => $loan, 'print' => 1]));
        $this->assertStringContainsString('window.print()', $this->page($shop, $owner, $loan, '?print=1')->getContent());
        $this->assertStringNotContainsString('addEventListener(\'load\', () => window.print())', $this->page($shop, $owner, $loan)->getContent(), 'a plain visit does not print by itself');

        DhiranLoan::withoutGlobalScopes()->whereKey($loan->id)->update(['status' => 'closed']);
        $this->save($shop, $owner, $loan, ['body' => 'Late edit.', 'warning' => 'Warn.'])->assertStatus(422);
    }
}
