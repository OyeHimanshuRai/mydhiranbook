<?php
namespace Tests\Feature\Onboarding;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures;

    public function test_password_reset_email_token_single_use(): void
    {
        Notification::fake();
        $u = $this->newOwner();
        $this->get('/forgot-password')->assertOk();
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHas('success');
        $token = null; Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token) { $token = $n->token; return true; });
        $this->get('/reset-password/'.$token.'?email='.urlencode($u->email))->assertOk();
        $new = ['token' => $token, 'email' => $u->email, 'password' => 'Brand-new-pass-99', 'password_confirmation' => 'Brand-new-pass-99'];
        $this->post('/reset-password', $new)->assertRedirect('/login');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Brand-new-pass-99', $u->fresh()->password));
        $this->post('/reset-password', ['password' => 'Another-pass-12345', 'password_confirmation' => 'Another-pass-12345'] + $new)->assertSessionHasErrors('email');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Brand-new-pass-99', $u->fresh()->password));
    }

    public function test_unknown_email_gets_same_response_and_no_mail(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => 'nobody@onboard.test'])->assertSessionHas('success');
        Notification::assertNothingSent();
    }

    public function test_reset_rejects_short_password(): void
    {
        Notification::fake();
        $u = $this->newOwner(); $this->post('/forgot-password', ['email' => $u->email]);
        $token = null; Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token) { $token = $n->token; return true; });
        $this->post('/reset-password', ['token' => $token, 'email' => $u->email, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    }
}
