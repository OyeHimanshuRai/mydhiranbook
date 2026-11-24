<?php

namespace App\Support;

use App\Services\Dhiran\Notices;

/** What the bell and the daily card show for the signed-in user. Null for anyone who cannot see loans. */
final class NoticeView
{
    private static function items(): ?array
    {
        $u = auth()->user();
        if (! $u || ! $u->shop_id || ! $u->can('dhiran.view')) {
            return null;
        }

        return Notices::forShop((int) $u->shop_id);
    }

    public static function count(): ?int
    {
        $items = self::items();

        return $items === null ? null : count($items);
    }

    /** @return array{items: array, auto: bool}|null */
    public static function card(): ?array
    {
        $items = self::items();
        if ($items === null) {
            return null;
        }
        $u = auth()->user();
        $r = request();
        // Opens by itself until the page tells us (POST notices/seen) that the user really saw it today: a prefetch, a frame
        // refresh or a render nobody looked at never uses the day's card up. Not on a frame refresh or a prefetch.
        $auto = $items !== [] && Notices::options((int) $u->shop_id)['popup']
            && ! $u->notices_seen_on?->isToday()
            && $r->isMethod('GET') && ! $r->headers->has('Turbo-Frame')
            && ! in_array('prefetch', [$r->header('Sec-Purpose'), $r->header('X-Sec-Purpose'), $r->header('Purpose')], true);

        return ['items' => $items, 'auto' => $auto];
    }
}
