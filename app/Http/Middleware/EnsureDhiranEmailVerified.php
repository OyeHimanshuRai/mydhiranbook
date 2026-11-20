<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Owners still onboarding (no shop) must verify their email first. Staff and shop users are never gated. */
class EnsureDhiranEmailVerified
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('web')->user();
        if ($user && $user->shop_id === null && $user->email_verified_at === null) {
            if ($request->expectsJson()) { return response()->json(['message' => 'Verify your email to continue.'], 409); }
            return redirect()->route('dhiran.verify-email');
        }
        return $next($request);
    }
}
