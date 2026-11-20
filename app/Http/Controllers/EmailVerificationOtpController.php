<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmailVerificationOtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function show()
    {
        $user = Auth::user();
        if ($user->email_verified_at) { return redirect('/'); }
        return view('dhiran.verify-email', ['user' => $user]);
    }

    public function send(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate(['email' => ['required', 'email:strict', 'max:190', Rule::unique('users', 'email')->ignore($user->id)]], ['email.unique' => 'This email is already registered to another account.']);
        if ($user->email_verified_at) { throw ValidationException::withMessages(['email' => 'Email is already verified.']); }
        $email = strtolower(trim($data['email']));
        $this->otp->send($user, $email);
        return response()->json(['status' => 'sent', 'message' => "We have sent a 6-digit code to {$email}. It expires in ".OtpService::EXPIRY_MINUTES.' minutes.']);
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['otp' => ['required', 'string', 'digits:6']]);
        $this->otp->verify(Auth::user(), $data['otp']);
        return response()->json(['status' => 'verified', 'message' => 'Email verified.']);
    }

    public function resend(Request $request)
    {
        $user = Auth::user();
        $request->merge(['email' => $user->email]);
        return $this->send($request);
    }
}
