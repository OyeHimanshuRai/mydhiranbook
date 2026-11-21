<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/** Reports (incl. filtered views and CSV exports) open only within 1 hour of re-entering your password; otherwise a pop-up asks for it in place. */
class ConfirmReportPassword
{
    /** `report.password:export` = lock only CSV exports (list pages stay open) and require the reports permission for them. */
    public function handle(Request $request, Closure $next, ?string $only = null): Response
    {
        if ($only === 'export') {
            if ($request->query('export') === null) { return $next($request); }
            abort_unless($request->user()?->can('dhiran.reports'), 403, 'Exports need the reports permission.');
        }
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $sameUser = $request->session()->get('auth.password_confirmed_for') === $request->user()?->getAuthIdentifier();
        if ($sameUser && Date::now()->unix() - $confirmedAt < (int) config('auth.password_timeout', 3600)) {
            return $next($request);
        }
        $request->session()->put('url.intended', $request->fullUrl()); // back to this exact filtered view after confirming
        return response()->view('dhiran.reports.locked');
    }
}
