<?php
namespace Database\Seeders;
use App\Models\Platform\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $common = ['customers' => true, 'reports' => true, 'installments' => true];
        $plans = [
            ['code' => 'dhiran_monthly', 'name' => 'Dhiran Monthly', 'price_monthly' => 1852, 'price_yearly' => null, 'grace_days' => 7, 'features' => $common + ['staff_limit' => 3, 'max_items' => 1000]],
            ['code' => 'dhiran_yearly', 'name' => 'Dhiran Yearly', 'price_monthly' => 1667, 'price_yearly' => 19999, 'grace_days' => 14, 'features' => $common + ['staff_limit' => 7, 'max_items' => 3000]],
        ];
        foreach ($plans as $p) { Plan::updateOrCreate(['code' => $p['code']], $p + ['is_active' => true]); }
    }
}
