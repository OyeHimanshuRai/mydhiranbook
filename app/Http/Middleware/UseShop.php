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
        abort_unless($user->is_active && $user->shop?->status === 'active', 403);
        return TenantContext::runFor((int)$user->shop_id, fn()=>$next($request));
    }
}
