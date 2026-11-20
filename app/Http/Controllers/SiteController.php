<?php
namespace App\Http\Controllers;

use App\Models\Platform\Plan;

/** Public marketing site. Static pages are Route::view; only pricing needs data. */
class SiteController extends Controller
{
    public function pricing()
    {
        return view('site.pricing', ['plans' => Plan::where('is_active', true)->orderBy('price_monthly')->get()]);
    }
}
