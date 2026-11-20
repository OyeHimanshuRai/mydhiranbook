<?php
namespace App\Http\Controllers;

use App\Models\Platform\Plan;

/** Public marketing site. Static pages are Route::view; only pricing needs data. */
class SiteController extends Controller
{
    /** Guests see the public home; signed-in users go to the app (its own auth chain applies there). */
    public function home()
    {
        return auth()->check() ? redirect()->route('dhiran.dashboard') : view('site.home');
    }

    public function pricing()
    {
        return view('site.pricing', ['plans' => Plan::where('is_active', true)->orderBy('price_monthly')->get()]);
    }
}
