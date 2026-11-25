<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Users with no shop go to the business form. Users with a shop pass through. */
class EnsureShopExists
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('web')->user();
        if (!$user || $user->shop_id !== null) { return $next($request); }
        return redirect()->route('dhiran.onboarding');
    }
}
