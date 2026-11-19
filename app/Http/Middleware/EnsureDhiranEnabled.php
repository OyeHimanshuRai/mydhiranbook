<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class EnsureDhiranEnabled
{
    public function handle(Request $request,Closure $next)
    {
        if (!\App\Models\Dhiran\DhiranSettings::getForShop((int)$request->user()->shop_id)->is_enabled) { return redirect()->route('dhiran.dashboard'); }
        return $next($request);
    }
}
