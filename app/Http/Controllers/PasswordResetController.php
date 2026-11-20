<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function forgotForm() { return view('auth.forgot-password'); }

    /** Same answer whether or not the address exists: no account enumeration. */
    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email|max:190']);
        Password::broker('users')->sendResetLink(['email' => strtolower(trim($r->input('email')))]);
        return back()->with('success', 'If that email has an account, a reset link is on its way.');
    }

    public function resetForm(Request $r, string $token) { return view('auth.reset-password', ['token' => $token, 'email' => $r->query('email')]); }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required|string', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)]]);
        $status = Password::broker('users')->reset(
            ['email' => strtolower(trim($r->input('email'))), 'token' => $r->input('token'), 'password' => $r->input('password'), 'password_confirmation' => $r->input('password_confirmation')],
            function (User $user, string $password) { $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save(); },
        );
        if ($status !== Password::PASSWORD_RESET) { throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired.']); }
        return redirect('/login')->with('success', 'Password reset. Sign in with your new password.');
    }
}
