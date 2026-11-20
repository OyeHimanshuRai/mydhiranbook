<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** `/` is the public home for guests; signed-in users fall through to the app's own checks. */
class GuestsSeeSite
{
    public function handle(Request $request, Closure $next)
    {
        return auth()->guest() ? response()->view('site.home') : $next($request);
    }
}
