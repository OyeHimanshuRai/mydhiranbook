<?php
namespace Tests\Feature\Onboarding;

use App\Mail\EmailOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

class EmailOtpTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures;

    private function sendCode(User $u): string
    {
        Mail::fake();
        $this->actingAs($u)->postJson(route('dhiran.verify-email.send'), ['email' => $u->email])->assertOk();
        $code = null; Mail::assertSent(EmailOtpMail::class, function ($m) use (&$code) { $code = $m->otp; return true; });
        return $code;
    }

    public function test_unverified_user_redirected_to_verify_email(): void
    {
        $u = $this->newOwner(['email_verified_at' => null]);
        $this->actingAs($u)->get('/')->assertRedirect(route('dhiran.verify-email'));
        $this->actingAs($u)->get(route('dhiran.verify-email'))->assertOk();
        $this->actingAs($u)->get('/subscription/status')->assertRedirect(route('dhiran.verify-email'));
    }

    public function test_otp_verifies_and_is_single_use_and_expires(): void
    {
        $u = $this->newOwner(['email_verified_at' => null]);
        $code = $this->sendCode($u);
        $this->assertNotSame($code, $u->fresh()->email_verify_otp);
        $this->actingAs($u)->postJson(route('dhiran.verify-email.verify'), ['otp' => $code])->assertOk();
        $this->assertNotNull($u->fresh()->email_verified_at);
        $this->actingAs($u)->postJson(route('dhiran.verify-email.verify'), ['otp' => $code])->assertStatus(422);

        $v = $this->newOwner(['email_verified_at' => null]);
        $code = $this->sendCode($v);
        $this->travel(11)->minutes();
        $this->actingAs($v)->postJson(route('dhiran.verify-email.verify'), ['otp' => $code])->assertStatus(422);
        $this->assertNull($v->fresh()->email_verified_at);
    }

    public function test_wrong_codes_lock_the_code_after_five_attempts(): void
    {
        $u = $this->newOwner(['email_verified_at' => null]);
        $code = $this->sendCode($u); $bad = $code === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 5; $i++) { $this->actingAs($u)->postJson(route('dhiran.verify-email.verify'), ['otp' => $bad])->assertStatus(422); }
        $this->actingAs($u)->postJson(route('dhiran.verify-email.verify'), ['otp' => $code])->assertStatus(422);
        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_resend_is_throttled(): void
    {
        $u = $this->newOwner(['email_verified_at' => null]);
        $this->sendCode($u);
        $this->actingAs($u)->postJson(route('dhiran.verify-email.resend'))->assertStatus(422);
    }

    public function test_email_cannot_be_changed_to_one_used_by_another_account(): void
    {
        $other = $this->newOwner(); $u = $this->newOwner(['email_verified_at' => null]);
        $this->actingAs($u)->postJson(route('dhiran.verify-email.send'), ['email' => $other->email])->assertStatus(422);
    }
}
