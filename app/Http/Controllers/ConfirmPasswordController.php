<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** The reports' password pop-up posts here (ConfirmReportPassword middleware). */
class ConfirmPasswordController extends Controller
{
    public function store(Request $r)
    {
        $r->validate(['password' => 'required|string']);
        // check against the signed-in user's own hash, never via a provider lookup
        if (! Hash::check((string) $r->input('password'), (string) $r->user()->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }
        $r->session()->passwordConfirmed();
        $r->session()->put('auth.password_confirmed_for', $r->user()->getAuthIdentifier()); // the confirmation belongs to this user only
        return redirect()->intended(route('dhiran.reports.index'));
    }
}
