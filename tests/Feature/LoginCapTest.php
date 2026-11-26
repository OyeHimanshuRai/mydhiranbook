<?php
namespace Tests\Feature;

use App\Models\Platform\PlatformAdmin;
use App\Support\LoginCap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** 30 failed sign-ins an hour per account, whatever the IP address (the per-IP route throttle and the per-account+IP minute limit stay). */
class LoginCapTest extends TestCase
{
    use RefreshDatabase;

    private function exhaust(string $scope, string $identity): void
    {
        for ($i = 0; $i < LoginCap::MAX; $i++) { LoginCap::failed($scope, $identity); }
    }

    public function test_shop_account_is_blocked_after_30_failures_even_with_the_right_password_and_a_new_ip(): void
    {
        $shop = self::createDhiranShop(['name' => 'Cap shop']);
        self::userWithRole($shop, ['name' => 'O', 'email' => 'cap@x.test', 'password' => 'Synthetic-pass-12345']);
        $this->exhaust('shop', 'cap@x.test');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/login', ['email' => 'CAP@x.test', 'password' => 'Synthetic-pass-12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_29_failures_do_not_block_and_a_failed_login_counts(): void
    {
        $shop = self::createDhiranShop(['name' => 'Cap shop 2']);
        self::userWithRole($shop, ['name' => 'O', 'email' => 'cap2@x.test', 'password' => 'Synthetic-pass-12345']);
        for ($i = 0; $i < LoginCap::MAX - 1; $i++) { LoginCap::failed('shop', 'cap2@x.test'); }
        $this->post('/login', ['email' => 'cap2@x.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertSame(LoginCap::MAX, RateLimiter::attempts(LoginCap::key('shop', 'cap2@x.test')));
        $this->post('/login', ['email' => 'cap2@x.test', 'password' => 'Synthetic-pass-12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_good_login_does_not_reset_the_account_count(): void
    {
        $shop = self::createDhiranShop(['name' => 'Cap shop 3']);
        self::userWithRole($shop, ['name' => 'O', 'email' => 'cap3@x.test', 'password' => 'Synthetic-pass-12345']);
        for ($i = 0; $i < 5; $i++) { LoginCap::failed('shop', 'cap3@x.test'); }
        $this->post('/login', ['email' => 'cap3@x.test', 'password' => 'Synthetic-pass-12345'])->assertRedirect('/');
        $this->assertSame(5, RateLimiter::attempts(LoginCap::key('shop', 'cap3@x.test')));
    }

    public function test_platform_admin_is_blocked_after_30_failures(): void
    {
        $admin = PlatformAdmin::create(['name' => 'A', 'email' => 'capadmin@example.test', 'password' => 'Admin-pass-12345', 'role' => 'super_admin']);
        $this->exhaust('admin', $admin->email);
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-pass-12345'])->assertSessionHasErrors('email');
        $this->assertGuest('platform_admin');
    }
}
