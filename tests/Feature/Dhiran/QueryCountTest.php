<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Customer;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Speed guard: the list and report pages run the same number of queries for 2 rows as for 8 (no query per row). Shop isolation has its own tests. */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390003000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390003000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create([
            'mobile_number' => '9390003000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id,
        ]);
    }

    private function addLoans(int $n, int $from): void
    {
        TenantContext::runFor($this->shop->id, function () use ($n, $from) {
            for ($i = $from; $i < $from + $n; $i++) {
                $c = Customer::create(['shop_id' => $this->shop->id, 'first_name' => "Cust{$i}", 'last_name' => 'K', 'mobile' => '98111' . str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
                app(DhiranService::class)->createLoan($this->shop, $c, [[
                    'description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000,
                ]], ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $this->owner->id, 'status' => 'active']);
            }
        });
    }

    /** @return int queries run while the page renders */
    private function queriesFor(string $action, array $query = []): int
    {
        return TenantContext::runFor($this->shop->id, function () use ($action, $query) {
            $this->actingAs($this->owner);
            $req = Request::create('http://127.0.0.1:8787/dhiran/x', 'GET', $query);
            $this->app->instance('request', $req);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            \App\Services\Dhiran\Notices::forget();   // the bell memo is per request: start each measurement cold
            $log = [];
            DB::listen(function ($q) use (&$log) { $log[] = $q->sql; });
            app(DhiranController::class)->{$action}($req)->render();
            return count($log);
        });
    }

    public static function pages(): array
    {
        return [
            'loans' => ['loans', []],
            'customers' => ['borrowers', []],
            'report active' => ['reportActive', []],
            'report overdue' => ['reportOverdue', []],
            'report interest' => ['reportInterest', ['period' => 'this_fy']],
            'report forfeiture' => ['reportForfeiture', []],
            'report cashbook' => ['reportCashbook', ['period' => 'this_fy']],
            'report profitability' => ['reportProfitability', ['period' => 'this_fy']],
        ];
    }

    #[DataProvider('pages')]
    public function test_queries_do_not_grow_with_the_number_of_rows(string $action, array $query): void
    {
        // Each count is taken on a second render: the first one also fills per-process caches (permissions, settings),
        // which would otherwise show up as a difference that has nothing to do with the number of rows.
        $this->addLoans(2, 1);
        $this->queriesFor($action, $query);
        $small = $this->queriesFor($action, $query);
        $this->addLoans(6, 3);
        $this->queriesFor($action, $query);
        $large = $this->queriesFor($action, $query);
        $this->assertSame($small, $large, "{$action}: {$small} queries for 2 rows but {$large} for 8 (a query per row)");
    }

    public function test_list_and_search_indexes_exist(): void
    {
        $have = collect(DB::select("select indexname from pg_indexes where schemaname = current_schema()"))->pluck('indexname')->all();
        foreach (['dhiran_loans_shop_created_index', 'dhiran_loans_shop_loan_date_index', 'customers_shop_name_index', 'dhiran_payments_shop_date_index'] as $name) {
            $this->assertContains($name, $have);
        }
    }
}
