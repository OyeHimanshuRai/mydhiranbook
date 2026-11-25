<?php

namespace App\Http\Middleware;

use App\Models\Dhiran\DhiranSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dhiran access (owner 2026-11-25): the shop's one-year term is checked in UseShop (an ended term goes to the access-ended page
 * for everybody, nothing is collect-only any more). Here: Dhiran must have been set up for the shop, else the dashboard offers activation.
 */
class EnsureDhiranEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->shop_id) {
            abort(403, 'No shop assigned.');
        }

        if (! DhiranSettings::getForShop((int) $user->shop_id)->is_enabled) {
            return redirect()->route('dhiran.dashboard');
        }

        return $next($request);
    }
}
