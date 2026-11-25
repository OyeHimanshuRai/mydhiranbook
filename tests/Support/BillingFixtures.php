<?php
namespace Tests\Support;

use App\Models\Platform\Plan;
use App\Models\Shop;
use App\Models\User;
use App\Services\TenantRoleService;
use Database\Seeders\PlanSeeder;

trait BillingFixtures
{
    protected function setUpBilling(): void
    {
        (new PlanSeeder)->run();
    }

    protected function plan(string $code = 'dhiran_yearly'): Plan { return Plan::where('code', $code)->firstOrFail(); }

    protected function shopUser(string $role = 'owner'): User
    {
        static $n = 0; $n++;
        $shop = Shop::create(['name' => "Billing shop $n"]);
        $roles = app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        return User::create(['name' => "$role $n", 'email' => "$role$n@billing.test", 'password' => 'a-long-test-password', 'shop_id' => $shop->id, 'role' => $role, 'role_id' => $roles[$role]->id]);
    }

    protected function shoplessUser(): User
    {
        static $n = 0; $n++;
        return User::create(['name' => "Buyer $n", 'email' => "buyer$n@billing.test", 'password' => 'a-long-test-password', 'role' => 'owner', 'email_verified_at' => now()]);
    }
}
