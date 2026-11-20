<?php
namespace App\Services;

use App\Mail\EmailOtpMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Email OTP: hashed code, 10 minute expiry, 5 wrong tries, 60 s resend gap, 5 sends per hour. */
class OtpService
{
    public const EXPIRY_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;

    public function send(User $user, string $email): void
    {
        $gap = "email-otp-gap:{$user->id}"; $hourly = "email-otp-hour:{$user->id}";
        if (RateLimiter::tooManyAttempts($gap, 1) || RateLimiter::tooManyAttempts($hourly, 5)) {
            $wait = max(RateLimiter::availableIn($gap), RateLimiter::tooManyAttempts($hourly, 5) ? RateLimiter::availableIn($hourly) : 0);
            throw ValidationException::withMessages(['email' => "Please wait {$wait} seconds before requesting another code."]);
        }
        RateLimiter::hit($gap, 60); RateLimiter::hit($hourly, 3600);

        $otp = (string) random_int(100000, 999999);
        $user->forceFill(['email' => $email, 'email_verified_at' => null, 'email_verify_otp' => Hash::make($otp), 'email_otp_expires_at' => now()->addMinutes(self::EXPIRY_MINUTES), 'email_otp_attempts' => 0])->save();
        Mail::to($email)->send(new EmailOtpMail($otp, $user->name));
    }

    /** Throws a validation error on any failure; on success marks the email verified and burns the code. */
    public function verify(User $user, string $code): void
    {
        $error = DB::transaction(function () use ($user, $code) {
            $u = User::whereKey($user->id)->lockForUpdate()->first();
            if (!$u->email_verify_otp || !$u->email_otp_expires_at) { return 'No code has been sent. Please request a new one.'; }
            if (now()->isAfter($u->email_otp_expires_at)) { $this->burn($u); return 'This code has expired. Please request a new one.'; }
            if (!Hash::check($code, $u->email_verify_otp)) {
                $u->email_otp_attempts++;
                if ($u->email_otp_attempts >= self::MAX_ATTEMPTS) { $this->burn($u); return 'Too many wrong attempts. Please request a new code.'; }
                $u->save();
                return 'Invalid code. Please check and try again.';
            }
            $this->burn($u, true);
            return null;
        });
        if ($error) { throw ValidationException::withMessages(['otp' => $error]); }
        $user->refresh();
    }

    private function burn(User $u, bool $verified = false): void
    {
        $u->forceFill(['email_verify_otp' => null, 'email_otp_expires_at' => null, 'email_otp_attempts' => 0] + ($verified ? ['email_verified_at' => now()] : []))->save();
    }
}
