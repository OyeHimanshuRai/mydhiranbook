<?php
namespace App\Http\Middleware;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class UseShop
{
    public function handle(Request $request, Closure $next)
    {
        Auth::shouldUse('web');
        $user=Auth::guard('web')->user();
        if (!$user) { return redirect()->route('login'); }
        // Every reason a person cannot use the software has a page that says why (not a bare 403): waiting, refused, suspended, deactivated.
        if ($user->is_active && $user->shop?->awaitsApproval()) { return redirect()->route('dhiran.pending'); }
        if (! $user->is_active || $user->shop?->status !== 'active') {
            return $request->expectsJson() ? abort(403) : redirect()->route('dhiran.blocked');
        }
        // The year is over (or never granted): everyone of the shop waits on the access-ended page until an admin extends it.
        if (! $user->shop->hasEdition('dhiran')) {
            return $request->expectsJson() ? abort(403, 'Access ended') : redirect()->route('dhiran.expired');
        }
        return TenantContext::runFor((int)$user->shop_id, fn()=>$next($request));
    }
}
