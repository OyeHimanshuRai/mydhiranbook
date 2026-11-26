<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A cap on FAILED sign-ins per account (30 an hour), on top of the per-IP route throttle and the per-account+IP minute limit:
 * an attacker who rotates IP addresses still cannot try more than 30 passwords an hour against one account.
 * It is not cleared by a successful sign-in, so a guess mixed with a real login does not reset it.
 */
final class LoginCap
{
    public const MAX = 30;
    public const WINDOW = 3600;

    public static function key(string $scope, string $identity): string
    {
        return "login-cap:{$scope}:" . sha1(mb_strtolower(trim($identity)));
    }

    /** @throws ValidationException when the account has used up its attempts */
    public static function check(string $scope, string $identity, string $field = 'email'): void
    {
        $key = self::key($scope, $identity);
        if (RateLimiter::tooManyAttempts($key, self::MAX)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            throw ValidationException::withMessages([$field => "Too many failed sign-ins for this account. Try again in {$minutes} minutes."]);
        }
    }

    public static function failed(string $scope, string $identity): void
    {
        RateLimiter::hit(self::key($scope, $identity), self::WINDOW);
    }
}
