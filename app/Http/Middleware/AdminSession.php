<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class AdminSession
{
    private const IDLE_SECONDS=1800; // 30 min inactivity; ponytail: constant, not config
    public function handle(Request $r, Closure $next, string $stage='verified')
    {
        Auth::shouldUse('platform_admin');
        $admin=Auth::guard('platform_admin')->user();
        if (!$admin) { return redirect('/admin/login'); }
        $stamp=hash('sha256',$admin->password);
        if (!$admin->is_active || !hash_equals($stamp,(string)$r->session()->get('admin.password_stamp','')) || now()->timestamp-(int)$r->session()->get('admin.password_at',0)>7200 || now()->timestamp-(int)$r->session()->get('admin.last_activity_at',$r->session()->get('admin.password_at',0))>self::IDLE_SECONDS) {
            Auth::guard('platform_admin')->logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return redirect('/admin/login');
        }
        $r->session()->put('admin.last_activity_at',now()->timestamp);
        if ($stage==='challenge') {
            if (time()-(int)$r->session()->get('admin.password_at',0)>600 && !$r->session()->has('admin.mfa_admin_id')) { return redirect('/admin/login'); }
        } elseif ((int)$r->session()->get('admin.mfa_admin_id') !== $admin->id || !$admin->two_factor_enabled) {
            return redirect('/admin/mfa');
        }
        return $next($r);
    }
}
