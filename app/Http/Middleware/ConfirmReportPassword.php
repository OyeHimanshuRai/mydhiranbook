<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/** Reports (incl. filtered views and CSV exports) open only within 1 hour of re-entering your password; otherwise a pop-up asks for it in place. */
class ConfirmReportPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        if (Date::now()->unix() - $confirmedAt < (int) config('auth.password_timeout', 3600)) {
            return $next($request);
        }
        $request->session()->put('url.intended', $request->fullUrl()); // back to this exact filtered view after confirming
        return response()->view('dhiran.reports.locked');
    }
}
