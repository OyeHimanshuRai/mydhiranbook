<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Money ledger rows are append-only in the database itself, below the model guard. */
class AppendOnlyTriggerTest extends TestCase
{
    use RefreshDatabase;

    private int $loanId;
    private int $shopId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $shop = self::createDhiranShop(['name' => 'Pawn Co', 'shop_type' => 'dhiran']);
        $owner = self::userWithRole($shop, ['name' => 'O', 'email' => 'o@example.test', 'password' => 'Local-pass-12345']);
        $this->shopId = $shop->id;
        $this->userId = $owner->id;
        $service = app(DhiranService::class);
        $loan = TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $c = Customer::create(['first_name' => 'Synthetic', 'last_name' => 'Borrower', 'mobile' => '9000000001']);
            $loan = $service->createLoan($shop, $c, [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 24, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000]],
                ['principal_amount' => 50000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2, 'tenure_months' => 12, 'min_interest_months' => 0, 'created_by' => $owner->id]);
            $service->recordPayment($loan, 5000);

            return $loan;
        });
        $this->loanId = $loan->id;
    }

    public static function tables(): array
    {
        return ['payments' => ['dhiran_payments', 'notes'], 'cash entries' => ['dhiran_cash_entries', 'description'], 'ledger entries' => ['dhiran_ledger_entries', 'note']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function test_update_and_delete_raise_but_insert_works(string $table, string $col): void
    {
        $id = DB::table($table)->where('dhiran_loan_id', $this->loanId)->value('id');
        $this->assertNotNull($id);

        foreach ([fn () => DB::table($table)->where('id', $id)->update([$col => 'edited']), fn () => DB::table($table)->where('id', $id)->delete()] as $attempt) {
            try { DB::transaction($attempt); $this->fail("$table row was changed"); } catch (QueryException $e) { $this->assertStringContainsString('append-only', $e->getMessage()); }
        }
        $this->assertNotSame('edited', DB::table($table)->where('id', $id)->value($col));

        $row = (array) DB::table($table)->where('id', $id)->first();
        unset($row['id']);
        if (array_key_exists('dhiran_payment_id', $row)) { $row['dhiran_payment_id'] = null; } // UNIQUE on cash entries
        $this->assertTrue(DB::table($table)->insert($row));
    }

    public function test_deleting_a_user_still_works_because_only_created_by_is_nulled(): void
    {
        DB::table('users')->where('id', $this->userId)->update(['shop_id' => null, 'role_id' => null]);
        DB::table('users')->where('id', $this->userId)->delete();
        $this->assertNull(DB::table('dhiran_payments')->where('dhiran_loan_id', $this->loanId)->value('created_by'));
    }
}
