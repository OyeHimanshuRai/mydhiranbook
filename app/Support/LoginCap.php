<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A cap on FAILED sign-ins per account (30 an hour), on top of the per-IP route throttle and the per-account+IP minute limit:
 * an attacker who rotates IP addresses still cannot try more than 30 passwords an hour against one account.
 * It is not cleared by a successful sign-in, so a guess mixed with a real login does not reset it.
 *
 * So that a stranger cannot lock the owner out by burning the 30 tries, a browser that has fully signed in to that account
 * before carries a known-device cookie (an HMAC of this account's key, per account, max 5) and skips the cap; the per-IP
 * limits still apply to it. A new device stays capped until the hour ends or the password is reset (reset clears the cap).
 */
final class LoginCap
{
    public const MAX = 30;
    public const WINDOW = 3600;
    private const DEVICES = 5;

    public static function key(string $scope, string $identity): string
    {
        return "login-cap:{$scope}:" . sha1(mb_strtolower(trim($identity)));
    }

    /** @throws ValidationException when the account has used up its attempts and this browser has not signed in to it before */
    public static function check(Request $r, string $scope, string $identity, string $field = 'email'): void
    {
        $key = self::key($scope, $identity);
        if (RateLimiter::tooManyAttempts($key, self::MAX) && !in_array(self::token($key), self::tokens($r, $scope), true)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            throw ValidationException::withMessages([$field => "Too many failed sign-ins for this account. Try again in {$minutes} minutes, sign in from a device you have used before, or reset your password."]);
        }
    }

    public static function failed(string $scope, string $identity): void
    {
        RateLimiter::hit(self::key($scope, $identity), self::WINDOW);
    }

    /** After a complete sign-in: mark this browser as known for each identity of the account (email and mobile both sign in). */
    public static function remember(Request $r, string $scope, ?string ...$identities): void
    {
        $new = array_map(fn ($i) => self::token(self::key($scope, $i)), array_filter($identities));
        $tokens = array_slice(array_values(array_unique([...$new, ...self::tokens($r, $scope)])), 0, self::DEVICES);
        Cookie::queue(Cookie::make("known_{$scope}", implode(',', $tokens), 60 * 24 * 365, null, null, null, true, false, 'lax'));
    }

    /** A password reset proves the owner holds the email: lift the cap so they can sign in from a new device straight away. */
    public static function clear(string $scope, ?string ...$identities): void
    {
        foreach (array_filter($identities) as $identity) { RateLimiter::clear(self::key($scope, $identity)); }
    }

    // HMAC with the app key, so the cookie cannot be forged even if cookie encryption were ever turned off for it.
    private static function token(string $key): string
    {
        return substr(hash_hmac('sha256', $key, (string) config('app.key')), 0, 32);
    }

    private static function tokens(Request $r, string $scope): array
    {
        $raw = $r->cookie("known_{$scope}");
        return is_string($raw) ? array_slice(explode(',', $raw), 0, self::DEVICES) : [];
    }
}
