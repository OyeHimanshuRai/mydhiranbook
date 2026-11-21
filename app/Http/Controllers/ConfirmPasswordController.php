<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** The reports' password pop-up posts here (ConfirmReportPassword middleware). */
class ConfirmPasswordController extends Controller
{
    public function store(Request $r)
    {
        $r->validate(['password' => 'required|string']);
        if (! Auth::guard('web')->validate(['email' => $r->user()->email, 'password' => $r->input('password')])) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }
        $r->session()->passwordConfirmed();
        return redirect()->intended(route('dhiran.reports.index'));
    }
}
