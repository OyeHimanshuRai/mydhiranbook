<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranAttachment;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Dhiran\LoanEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Dhiran\DhiranAttachmentService;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** Each loan keeps its own private, append-only activity log: every transaction, release, upload, change and print, with who and when. */
class LoanActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';
    private $shop;
    private User $owner;
    private Customer $customer;
    private DhiranLoan $loan;
    private DhiranService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00', 'Asia/Kolkata'));
        $this->svc = app(DhiranService::class);
        $this->shop = self::createDhiranShop(['name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390023000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390023000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0, 'default_min_interest_months' => 0]);
        $this->owner = $this->userWith($this->shop, '9390023000', 'owner', Permission::query()->pluck('id'));
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Log', 'last_name' => 'Holder', 'mobile' => '9815620001']));
        $this->loan = $this->t(fn () => $this->svc->createLoan($this->shop, $this->customer, [
            ['description' => 'Gold chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000],
            ['description' => 'Gold ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 8, 'rate_per_gram_at_pledge' => 6000],
        ], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 12, 'loan_date' => '2026-03-01', 'created_by' => $this->owner->id]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith($shop, string $mobile, string $roleName, $permissionIds): User
    {
        $role = new Role();
        $role->forceFill(['name' => $roleName, 'display_name' => ucfirst($roleName), 'shop_id' => $shop->id])->save();
        $role->permissions()->sync($permissionIds);

        return User::create(['mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => $mobile . '@example.com', 'name' => ucfirst($roleName) . ' ' . substr($mobile, -4),
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
    }

    private function t(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, $fn);
    }

    private function fetch(string $path, ?User $as = null)
    {
        $as ??= $this->owner;

        return TenantContext::runFor($as->shop_id, fn () => $this->actingAs($as)->get(self::HOST . $path));
    }

    private function send(string $path, array $data, ?User $as = null)
    {
        $as ??= $this->owner;

        return TenantContext::runFor($as->shop_id, fn () => $this->actingAs($as)->post(self::HOST . $path, $data));
    }

    private function events(?string $event = null)
    {
        $q = LoanEvent::withoutGlobalScopes()->where('dhiran_loan_id', $this->loan->id)->orderBy('id');

        return $event ? $q->where('event', $event)->get() : $q->get();
    }

    private function at(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when, 'Asia/Kolkata'));
    }

    public function test_creating_a_loan_writes_who_what_and_when(): void
    {
        $all = $this->events();
        $this->assertSame(1, $all->where('event', 'loan.created')->count());
        $this->assertSame(2, $all->where('event', 'item.added')->count());
        $this->assertSame(1, $all->where('event', 'payment.disbursement')->count());

        $created = $all->firstWhere('event', 'loan.created');
        $this->assertStringContainsString('₹1,00,000.00', $created->summary);
        $this->assertSame(100000.0, (float) $created->details['principal']);
        $this->assertSame('2026-03-01 10:00', $created->occurred_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame($this->shop->id, (int) $created->shop_id);
    }

    /** Live 2026-11-26: production Postgres runs in UTC, so an India time written without an offset was stored 5h30 late. */
    public function test_event_time_is_right_when_the_database_session_is_utc(): void
    {
        DB::statement("SET TIME ZONE 'UTC'");
        $this->at('2026-11-26 15:46:00');
        $event = $this->t(fn () => \App\Services\Dhiran\LoanLog::forLoan($this->loan, 'document.printed', 'print', 'Receipt opened'));
        $stored = LoanEvent::withoutGlobalScopes()->findOrFail($event->id);
        $this->assertSame('2026-11-26 15:46', $stored->occurred_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-26 10:16', DB::selectOne("select to_char(occurred_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as t from dhiran_loan_events where id = ?", [$event->id])->t);
    }

    public function test_the_time_fix_migration_corrects_old_rows_and_keeps_the_log_append_only(): void
    {
        DB::statement("SET TIME ZONE 'UTC'");
        $id = DB::table('dhiran_loan_events')->insertGetId(['shop_id' => $this->shop->id, 'dhiran_loan_id' => $this->loan->id, 'actor_name' => 'Old',
            'event' => 'document.printed', 'category' => 'print', 'summary' => 'Old row', 'occurred_at' => '2026-11-26 15:46:00', 'created_at' => '2026-11-26 15:46:00']);
        (require database_path('migrations/2026_12_08_000000_fix_loan_event_times.php'))->up();
        $row = LoanEvent::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('2026-11-26 15:46', $row->occurred_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-26 15:46', $row->created_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->expectException(QueryException::class);
        DB::table('dhiran_loan_events')->where('id', $id)->update(['summary' => 'rewritten']);
    }

    public function test_every_payment_is_a_line_with_its_split_and_who_took_it(): void
    {
        $this->at('2026-04-01 11:30');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc->recordInterestPayment($this->fresh(), 1000));
        $this->t(fn () => $this->svc->recordPayment($this->fresh(), 5000));

        $interest = $this->events('payment.interest_payment')->first();
        $this->assertStringContainsString('Interest payment ₹1,000.00 received', $interest->summary);
        $this->assertSame($this->owner->name, $interest->actor_name);
        $this->assertSame($this->owner->id, (int) $interest->user_id);
        $this->assertSame(1000.0, (float) $interest->details['interest']);

        $repay = $this->events('payment.principal_repayment')->first();
        $this->assertStringContainsString('Repayment ₹5,000.00', $repay->summary);
        $this->assertGreaterThan(0, (float) $repay->details['principal']);
        $this->assertArrayHasKey('principal_left', $repay->details);
        $this->assertSame('2026-04-01 11:30', $repay->occurred_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
    }

    public function test_interest_accrual_is_a_system_line(): void
    {
        $this->at('2026-03-11 09:00');
        $this->t(fn () => $this->svc->accrueInterest($this->fresh()));

        $line = $this->events('interest.accrued')->first();
        $this->assertSame('system', $line->category);
        $this->assertSame('System', $line->actor_name);
        $this->assertStringContainsString('for 10 days', $line->summary);
        $this->assertSame(10, (int) $line->details['days']);
    }

    public function test_releasing_an_item_records_the_item_the_note_and_the_payment(): void
    {
        $this->at('2026-03-11 12:00');
        $ring = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $this->loan->id)->where('description', 'Gold ring')->first();
        $this->actingAs($this->owner);
        $min = $this->t(fn () => $this->svc->releaseMinimum($this->fresh(), $ring));
        $this->t(fn () => $this->svc->releaseItem($this->fresh(), $ring->fresh(), $min + 1000, 'cash', 'Returned in good condition')); // the interest of the days since the sheet was opened is added on top

        $item = $this->events('item.released')->first();
        $this->assertStringContainsString('Gold ring', $item->summary);
        $this->assertStringContainsString('Returned in good condition', $item->summary);
        $this->assertSame($this->owner->name, $item->actor_name);
        $pay = $this->events('payment.principal_repayment')->first();
        $this->assertStringContainsString('to release Gold ring', $pay->summary);
    }

    public function test_an_upload_is_a_line_for_the_loan_item_or_new_loan_form(): void
    {
        $item = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $this->loan->id)->first();
        $this->t(fn () => app(DhiranAttachmentService::class)->store(UploadedFile::fake()->image('chain.jpg', 100, 100), $this->shop->id, DhiranAttachment::OWNER_ITEM, $item->id, 'item_photo', $this->owner->id));
        $this->t(fn () => app(DhiranAttachmentService::class)->store(UploadedFile::fake()->image('id.jpg', 100, 100), $this->shop->id, DhiranAttachment::OWNER_CUSTOMER, $this->customer->id, 'id_proof_front', $this->owner->id, $this->loan->id));
        $this->t(fn () => app(DhiranAttachmentService::class)->store(UploadedFile::fake()->image('x.jpg', 100, 100), $this->shop->id, DhiranAttachment::OWNER_CUSTOMER, $this->customer->id, 'id_proof_back', $this->owner->id)); // no loan: nothing to log on a loan

        $docs = $this->events('document.uploaded');
        $this->assertCount(2, $docs);
        $this->assertStringContainsString('Item photo: chain.jpg', $docs[0]->summary);
        $this->assertStringContainsString('kept on the borrower', $docs[1]->summary);
        $this->assertSame('item', $docs[0]->details['kept_on']);
    }

    public function test_opening_a_receipt_tag_or_payment_receipt_is_a_line(): void
    {
        $this->fetch("/dhiran/loans/{$this->loan->id}/receipt")->assertOk();
        $this->fetch("/dhiran/loans/{$this->loan->id}/tag")->assertOk();
        $payment = DB::table('dhiran_payments')->where('dhiran_loan_id', $this->loan->id)->first();
        $this->fetch("/dhiran/loans/{$this->loan->id}/payments/{$payment->id}/receipt")->assertOk();

        $prints = $this->events('document.printed');
        $this->assertCount(3, $prints);
        $this->assertSame(['Pledge receipt opened for printing', 'Item tag opened for printing (58 mm)'], $prints->take(2)->pluck('summary')->all());
        $this->assertSame($this->owner->name, $prints[0]->actor_name);
        $this->assertNotNull($prints[0]->ip);
    }

    public function test_closing_returns_the_items_and_says_so(): void
    {
        $this->at('2026-03-11 12:00');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc->recordPayment($this->fresh(), 100666.67)); // principal plus the 10 days of interest

        $closed = $this->events('loan.status')->last();
        $this->assertStringContainsString('Loan closed', $closed->summary);
        $items = $this->events('item.released')->first();
        $this->assertStringContainsString('2 items returned to the borrower (loan closed): Gold chain, Gold ring', $items->summary);
    }

    public function test_a_changed_private_field_is_logged_without_its_value(): void
    {
        $this->t(function () {
            $l = $this->fresh();
            $l->kyc_pan = 'ABCDE1234F';
            $l->notes = 'Customer asked to hold the chain a week';
            $l->save();
        });

        $line = $this->events('loan.changed')->last();
        $this->assertStringContainsString('PAN changed', $line->summary);
        $this->assertStringNotContainsString('ABCDE1234F', $line->summary . json_encode($line->details));
        $this->assertStringContainsString('Customer asked to hold the chain a week', $line->summary);
    }

    public function test_bookkeeping_only_changes_do_not_clutter_the_log(): void
    {
        $before = $this->events()->count();
        $this->t(function () {
            $l = $this->fresh();
            $l->outstanding_interest = 12.34;
            $l->interest_accrued_through = '2026-03-02';
            $l->save();
        });
        $this->assertSame($before, $this->events()->count());
    }

    public function test_the_log_cannot_be_edited_or_deleted(): void
    {
        $line = $this->events()->first();
        try {
            $line->summary = 'tampered';
            $line->save();
            $this->fail('editing must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        try {
            $line->delete();
            $this->fail('deleting must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        foreach ([fn () => DB::table('dhiran_loan_events')->where('id', $line->id)->update(['summary' => 'tampered']), fn () => DB::table('dhiran_loan_events')->where('id', $line->id)->delete()] as $raw) {
            try {
                DB::transaction(fn () => $raw()); // a savepoint: the refused statement must not poison the test's transaction
                $this->fail('raw SQL must be refused too');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame('Loan DH-000001 created: ₹1,00,000.00 at 2.00% a month (flat), 12 months', $this->events('loan.created')->first()->summary, 'the stored line is untouched');
    }

    public function test_the_owner_reads_the_log_by_day_with_the_time_and_full_details(): void
    {
        $this->at('2026-03-05 16:45');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc->recordInterestPayment($this->fresh(), 200));

        $html = $this->fetch("/dhiran/loans/{$this->loan->id}/activity")->assertOk()->getContent();
        $this->assertStringContainsString('Sunday, 01 Mar 2026', $html);
        $this->assertStringContainsString('Thursday, 05 Mar 2026', $html);
        $this->assertStringContainsString('10:00 AM', $html);
        $this->assertStringContainsString('04:45 PM', $html);
        $this->assertStringContainsString('Interest payment ₹200.00 received', $html);
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('Principal left', $html);
        $this->assertMatchesRegularExpression('/\d+ events?/', $html);
    }

    public function test_the_filters_narrow_the_log(): void
    {
        $this->at('2026-03-05 16:45');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc->recordInterestPayment($this->fresh(), 200));

        $money = $this->fetch("/dhiran/loans/{$this->loan->id}/activity?cat=money")->getContent();
        $this->assertStringContainsString('Interest payment ₹200.00', $money);
        $this->assertStringNotContainsString('Item pledged', $money);
        $this->assertStringNotContainsString('Sunday, 01 Mar 2026', $this->fetch("/dhiran/loans/{$this->loan->id}/activity?from=2026-03-03")->getContent());
        $this->assertStringNotContainsString('Interest payment ₹200.00', $this->fetch("/dhiran/loans/{$this->loan->id}/activity?to=2026-03-02")->getContent());
    }

    public function test_the_log_is_private_to_people_allowed_and_to_the_shop(): void
    {
        $cashier = $this->userWith($this->shop, '9390023001', 'cashier', Permission::whereIn('name', ['dhiran.view', 'dhiran.pay'])->pluck('id'));
        $this->fetch("/dhiran/loans/{$this->loan->id}/activity", $cashier)->assertForbidden();
        $this->fetch("/dhiran/loans/{$this->loan->id}/activity/export", $cashier)->assertForbidden();
        $this->assertStringNotContainsString('Activity log', $this->fetch("/dhiran/loans/{$this->loan->id}", $cashier)->getContent(), 'not even a heading on the loan page');
        $this->assertStringContainsString('Activity log', $this->fetch("/dhiran/loans/{$this->loan->id}")->getContent());

        $other = self::createDhiranShop(['name' => 'Other Co', 'shop_type' => 'dhiran', 'phone' => '9390023002', 'owner_first_name' => 'X', 'owner_last_name' => 'Y',
            'owner_mobile' => '9390023002', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($other->id)->update(['is_enabled' => true]);
        $intruder = $this->userWith($other, '9390023002', 'owner', Permission::query()->pluck('id'));
        $this->fetch("/dhiran/loans/{$this->loan->id}/activity", $intruder)->assertNotFound();
    }

    public function test_the_log_exports_to_csv_and_the_export_is_itself_logged(): void
    {
        $r = $this->fetch("/dhiran/loans/{$this->loan->id}/activity/export")->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString('Date,Time,Who,Type', $csv);
        $this->assertStringContainsString('Loan DH-000001 created', $csv);
        $this->assertCount(1, $this->events('document.exported'));
    }

    public function test_the_manager_role_does_not_get_the_log_by_default(): void
    {
        $roles = TenantContext::runFor($this->shop->id, fn () => app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($this->shop->id));
        $this->assertFalse($roles['manager']->hasPermission('dhiran.audit'));
        $this->assertTrue($roles['owner']->hasPermission('dhiran.audit'));
    }

    public function test_earlier_loans_get_their_history_rebuilt_without_duplicating(): void
    {
        $count = $this->events()->count();
        $this->artisan('dhiran:backfill-loan-log')->assertSuccessful();
        $this->assertSame($count, $this->events()->count(), 'a loan that already has a log is left alone');

        $this->artisan('dhiran:backfill-loan-log', ['--force' => true])->assertSuccessful();
        $rebuilt = $this->events()->filter(fn ($e) => ! empty($e->details['rebuilt_from_earlier_records']));
        $this->assertGreaterThanOrEqual(4, $rebuilt->count());
        $this->assertSame('Earlier record', $rebuilt->first()->actor_name);
    }

    public function test_the_accrual_inside_a_persons_payment_is_the_systems_line_not_theirs(): void
    {
        $this->at('2026-03-11 09:00');
        $this->actingAs($this->owner);
        $this->t(fn () => $this->svc->recordInterestPayment($this->fresh(), 200)); // accrual runs first, inside this person's payment

        $accrual = $this->events('interest.accrued')->first();
        $this->assertNotNull($accrual);
        $this->assertSame('System', $accrual->actor_name);
        $this->assertNull($accrual->user_id);
        $payment = $this->events('payment.interest_payment')->first();
        $this->assertSame($this->owner->name, $payment->actor_name, 'the payment itself is theirs');
    }

    public function test_the_loan_page_preview_shows_what_people_did_not_the_daily_system_lines(): void
    {
        $this->at('2026-03-11 09:00');
        $this->t(fn () => $this->svc->accrueInterest($this->fresh())); // what the daily scheduled job does
        $this->assertCount(1, $this->events('interest.accrued'));

        $page = $this->fetch("/dhiran/loans/{$this->loan->id}")->getContent();
        $preview = substr($page, strpos($page, 'dd-act-log-h'), 6000);
        $this->assertStringContainsString('Loan paid out', $preview);
        $this->assertStringNotContainsString('Interest accrued', $preview, 'system lines do not crowd out payments and uploads');
        $this->assertStringContainsString('Interest accrued', $this->fetch("/dhiran/loans/{$this->loan->id}/activity")->getContent(), 'the full log has them');
    }

    public function test_a_days_count_is_the_real_total_even_when_the_day_runs_over_a_page(): void
    {
        $rows = [];
        for ($n = 0; $n < 120; $n++) {
            $rows[] = ['shop_id' => $this->shop->id, 'dhiran_loan_id' => $this->loan->id, 'actor_name' => 'System', 'event' => 'interest.accrued', 'category' => 'system',
                'summary' => "Filler {$n}", 'occurred_at' => '2026-03-01 12:00:00+05:30', 'created_at' => now()];
        }
        DB::table('dhiran_loan_events')->insert($rows);
        $total = $this->events()->count();

        $html = $this->fetch("/dhiran/loans/{$this->loan->id}/activity")->getContent();
        $this->assertStringContainsString("{$total} events", $html, 'not the 100 rows of this page');
    }

    public function test_the_loan_page_names_the_release_and_shows_who_and_the_condition_note(): void
    {
        $this->at('2026-03-11 12:00');
        $ring = DhiranLoanItem::withoutGlobalScopes()->where('dhiran_loan_id', $this->loan->id)->where('description', 'Gold ring')->first();
        $this->actingAs($this->owner);
        $min = $this->t(fn () => $this->svc->releaseMinimum($this->fresh(), $ring));
        $this->t(fn () => $this->svc->releaseItem($this->fresh(), $ring->fresh(), $min + 1000, 'cash', 'Returned in good condition'));

        $page = $this->fetch("/dhiran/loans/{$this->loan->id}")->getContent();
        $this->assertStringContainsString('Item Release', $page, 'Payment History says what the payment was');
        $this->assertStringContainsString('Returned in good condition', $page, 'the Items table keeps the condition note');
        $this->assertStringContainsString('11 Mar 2026', $page);
    }

    public function test_print_links_are_not_prefetched_so_hovering_cannot_write_a_log_line(): void
    {
        $page = $this->fetch("/dhiran/loans/{$this->loan->id}")->getContent();
        foreach (['/receipt', '/tag', '/payments/'] as $needle) {
            $this->assertMatchesRegularExpression('#href="[^"]*' . preg_quote($needle, '#') . '[^"]*"[^>]*data-turbo-prefetch="false"|data-turbo-prefetch="false"[^>]*href="[^"]*' . preg_quote($needle, '#') . '#', $page, "{$needle} link");
        }
    }

    private function fresh(): DhiranLoan
    {
        return DhiranLoan::withoutGlobalScopes()->findOrFail($this->loan->id);
    }
}
