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
        // A shop still waiting for (or refused by) the admin sees the waiting page, not a 403.
        if ($user->is_active && $user->shop?->awaitsApproval()) { return redirect()->route('dhiran.pending'); }
        abort_unless($user->is_active && $user->shop?->status === 'active', 403);
        return TenantContext::runFor((int)$user->shop_id, fn()=>$next($request));
    }
}
