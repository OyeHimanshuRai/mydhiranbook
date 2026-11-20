<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Users with no shop go to plans (nothing paid) or create-business (paid). Users with a shop pass through. */
class EnsureShopExists
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('web')->user();
        if (!$user || $user->shop_id !== null) { return $next($request); }
        return redirect()->route($user->paidShoplessSubscriptions()->exists() ? 'dhiran.onboarding' : 'dhiran.plans');
    }
}
