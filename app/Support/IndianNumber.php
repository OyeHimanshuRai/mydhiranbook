<?php

namespace App\Support;

/**
 * Indian digit grouping (lakh/crore): 240000 → "2,40,000", 12345678.5 → "1,23,45,678.50". No intl extension needed.
 */
final class IndianNumber
{
    public static function format(float|int|string|null $n, int $decimals = 0): string
    {
        $n = (float) $n;
        $sign = $n < 0 ? '-' : '';
        [$int, $dec] = array_pad(explode('.', number_format(abs($n), $decimals, '.', '')), 2, null);
        if (strlen($int) > 3) {
            $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)) . ',' . substr($int, -3);
        }

        return $sign . $int . ($dec !== null ? '.' . $dec : '');
    }

    /** Rupee amount for display: sign first, then the symbol, lakh grouping: -1,40,000.00 becomes "-₹1,40,000.00". */
    public static function rupee(float|int|string|null $n, int $decimals = 2): string
    {
        $n = (float) $n;

        return ($n < 0 ? '-' : '') . '₹' . self::format(abs($n), $decimals);
    }
}
