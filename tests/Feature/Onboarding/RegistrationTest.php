<?php
namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $over = []): array
    {
        return $over + ['name' => 'New Owner', 'email' => 'new@onboard.test', 'mobile' => '98123 00099', 'password' => 'Local-pass-12345', 'password_confirmation' => 'Local-pass-12345', 'terms' => '1'];
    }

    public function test_register_requires_terms_consent(): void
    {
        $this->post('/register', $this->data(['terms' => null]))->assertSessionHasErrors('terms');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_creates_user_without_shop_or_subscription(): void
    {
        $this->post('/register', $this->data())->assertRedirect('/');
        $u = User::sole();
        $this->assertNull($u->shop_id); $this->assertNull($u->role_id); $this->assertNotNull($u->terms_accepted_at);
        $this->assertSame('9812300099', $u->mobile_number); $this->assertNull($u->email_verified_at);
        $this->assertDatabaseCount('shops', 0); $this->assertDatabaseCount('shop_subscriptions', 0);
        $this->assertAuthenticatedAs($u);
    }

    public function test_register_validates_mobile_email_password_uniqueness(): void
    {
        $this->post('/register', $this->data())->assertRedirect('/');
        auth()->logout();
        $this->post('/register', $this->data(['mobile' => '12345']))->assertSessionHasErrors('mobile');
        $this->post('/register', $this->data(['email' => 'other@onboard.test']))->assertSessionHasErrors('mobile');
        $this->post('/register', $this->data(['mobile' => '9812300088']))->assertSessionHasErrors('email');
        $this->post('/register', $this->data(['mobile' => '9812300088', 'email' => 'o2@onboard.test', 'password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
    }

    public function test_login_by_mobile_still_works(): void
    {
        $this->post('/register', $this->data()); auth()->logout();
        $this->post('/login', ['email' => '9812300099', 'password' => 'Local-pass-12345'])->assertRedirect('/');
        $this->assertAuthenticated();
    }
}
