<?php
namespace Tests\Support;

use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;

trait OnboardingFixtures
{
    protected function newOwner(array $over = []): User
    {
        static $n = 0; $n++;
        return User::create($over + ['name' => "Owner $n", 'email' => "owner$n@onboard.test", 'mobile_number' => '9000000'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'password' => 'a-long-test-password', 'role' => 'owner', 'terms_accepted_at' => now(), 'email_verified_at' => now()]);
    }

    /** A captured-payment subscription with no shop yet. */
    protected function paidShopless(User $user, string $status = 'active'): ShopSubscription
    {
        (new PlanSeeder)->run();
        $plan = Plan::where('code', 'dhiran_yearly')->firstOrFail();
        return ShopSubscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => $status, 'billing_cycle' => 'yearly', 'starts_at' => today(), 'ends_at' => today()->addYear(), 'grace_ends_at' => today()->addYear()->addDays(14), 'price_paid' => $plan->price_yearly, 'actor_type' => 'system']);
    }
}
