<?php
namespace Tests\Feature;

use App\Models\Platform\PlatformAdmin;
use App\Support\LoginCap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
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

    /** Signs in for real, returns the known-device cookie value the response set, then signs out again. */
    private function knownShopDevice(string $email): string
    {
        $value = $this->post('/login', ['email' => $email, 'password' => 'Synthetic-pass-12345'])->assertRedirect('/')->getCookie('known_shop')?->getValue();
        $this->assertNotEmpty($value);
        Auth::guard('web')->logout();
        return $value;
    }

    public function test_a_device_that_signed_in_before_still_gets_in_after_the_cap_by_email_or_mobile(): void
    {
        $shop = self::createDhiranShop(['name' => 'Cap shop 4']);
        self::userWithRole($shop, ['name' => 'O', 'email' => 'cap4@x.test', 'mobile_number' => '9876501234', 'password' => 'Synthetic-pass-12345']);
        $cookie = $this->knownShopDevice('cap4@x.test');
        $this->exhaust('shop', 'cap4@x.test');
        $this->exhaust('shop', '9876501234');
        $this->withCookie('known_shop', $cookie)->post('/login', ['email' => 'cap4@x.test', 'password' => 'Synthetic-pass-12345'])->assertRedirect('/');
        Auth::guard('web')->logout();
        $this->withCookie('known_shop', $cookie)->post('/login', ['email' => '9876501234', 'password' => 'Synthetic-pass-12345'])->assertRedirect('/');
    }

    public function test_one_accounts_device_cookie_does_not_lift_the_cap_on_another_account_and_cannot_be_forged(): void
    {
        $shop = self::createDhiranShop(['name' => 'Cap shop 5']);
        self::userWithRole($shop, ['name' => 'Attacker', 'email' => 'mine@x.test', 'password' => 'Synthetic-pass-12345']);
        self::userWithRole($shop, ['name' => 'Victim', 'email' => 'victim@x.test', 'password' => 'Synthetic-pass-12345']);
        $attackerCookie = $this->knownShopDevice('mine@x.test');
        $victimCookie = $this->knownShopDevice('victim@x.test');
        $this->exhaust('shop', 'victim@x.test');
        $this->withCookie('known_shop', $attackerCookie)->post('/login', ['email' => 'victim@x.test', 'password' => 'Synthetic-pass-12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->withUnencryptedCookie('known_shop', $victimCookie)->post('/login', ['email' => 'victim@x.test', 'password' => 'Synthetic-pass-12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_password_reset_lifts_the_cap_so_a_new_device_can_sign_in(): void
    {
        $shop = self::createDhiranShop(['name' => 'Cap shop 6']);
        $user = self::userWithRole($shop, ['name' => 'O', 'email' => 'cap6@x.test', 'mobile_number' => '9876505678', 'password' => 'Synthetic-pass-12345']);
        $this->exhaust('shop', 'cap6@x.test');
        $this->exhaust('shop', '9876505678');
        $token = Password::broker('users')->createToken($user);
        $this->post('/reset-password', ['token' => $token, 'email' => 'cap6@x.test', 'password' => 'Brand-new-pass-12345', 'password_confirmation' => 'Brand-new-pass-12345'])->assertRedirect('/login');
        $this->assertSame(0, RateLimiter::attempts(LoginCap::key('shop', 'cap6@x.test')));
        $this->assertSame(0, RateLimiter::attempts(LoginCap::key('shop', '9876505678')));
        $this->post('/login', ['email' => 'cap6@x.test', 'password' => 'Brand-new-pass-12345'])->assertRedirect('/');
    }

    public function test_admin_device_becomes_known_only_after_mfa_and_then_skips_the_cap(): void
    {
        $admin = PlatformAdmin::create(['name' => 'A', 'email' => 'capadmin2@example.test', 'password' => 'Admin-pass-12345', 'role' => 'super_admin']);
        $this->assertNull($this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-pass-12345'])->assertRedirect('/admin/mfa')->getCookie('known_admin'));
        $this->get('/admin/mfa')->assertOk();
        $code = app(\App\Services\TotpService::class)->computeCode($admin->fresh()->two_factor_secret, (int) floor(time() / 30));
        $cookie = $this->post('/admin/mfa', ['code' => $code])->assertRedirect('/admin')->getCookie('known_admin')?->getValue();
        $this->assertNotEmpty($cookie);
        Auth::guard('platform_admin')->logout();
        $this->exhaust('admin', $admin->email);
        $this->withCookie('known_admin', $cookie)->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-pass-12345'])->assertRedirect('/admin/mfa');
    }

    public function test_a_password_change_voids_every_known_device_cookie(): void
    {
        $shop = self::createDhiranShop(['name' => 'Cap shop 7']);
        $user = self::userWithRole($shop, ['name' => 'O', 'email' => 'cap7@x.test', 'password' => 'Synthetic-pass-12345']);
        $cookie = $this->knownShopDevice('cap7@x.test');
        $user->forceFill(['password' => 'Changed-pass-123456'])->save();
        $this->exhaust('shop', 'cap7@x.test');
        $this->withCookie('known_shop', $cookie)->post('/login', ['email' => 'cap7@x.test', 'password' => 'Changed-pass-123456'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
