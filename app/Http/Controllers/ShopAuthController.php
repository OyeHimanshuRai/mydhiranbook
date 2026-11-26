<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
class ShopAuthController
{
    public function loginForm() { return view('auth.login'); }
    public function registerForm(Request $r)
    {
        if ($ref=\App\Models\Shop::cleanReferralCode($r->query('ref'))) { $r->session()->put('referral_ref',$ref); } // remembered until the business form
        return view('auth.register');
    }
    public function register(Request $r)
    {
        $email=$r->input('email'); $mobile=$r->input('mobile'); // arrays/non-strings are left for validation to reject
        $r->merge(['email'=>is_string($email) ? strtolower(trim($email)) : $email,'mobile'=>is_string($mobile) ? (\App\Support\Mobile::normalize($mobile) ?? $mobile) : $mobile]);
        $data=$r->validate(['name'=>'required|string|max:120','email'=>'bail|required|string|email:strict|lowercase|max:190|unique:users,email','mobile'=>['bail','required','string',new \App\Rules\IndianMobileRule(),'unique:users,mobile_number'],'password'=>['required','confirmed',Password::min(12)],'terms'=>'accepted'],['terms.accepted'=>'You must accept the Terms of Service and Privacy Policy.','mobile.unique'=>'This mobile number is already registered.']);
        $user=User::create(['name'=>$data['name'],'email'=>$data['email'],'mobile_number'=>$data['mobile'],'password'=>$data['password'],'terms_accepted_at'=>now()]);
        Auth::guard('web')->login($user); $r->session()->regenerate(); return redirect('/');
    }
    public function login(Request $r)
    {
        $input=$r->validate(['email'=>'required|string|max:190','password'=>'required|string']);
        $identity=trim($input['email']);
        $column=filter_var($identity,FILTER_VALIDATE_EMAIL) ? 'email' : 'mobile_number';
        $identity=$column === 'email' ? strtolower($identity) : \App\Support\Mobile::normalize($identity);
        if (!$identity) { throw ValidationException::withMessages(['email'=>'Enter your email address or mobile number.']); }
        $key='shop-login:'.$identity.'|'.$r->ip(); // per account+IP: one attacker can't lock a known account out for everyone
        if (RateLimiter::tooManyAttempts($key,5)) { throw ValidationException::withMessages(['email'=>'Too many sign-in attempts. Try again in a minute.']); }
        \App\Support\LoginCap::check($r,'shop',$identity); // 30 failed tries an hour per account, whatever the IP
        $data=[$column=>$identity,'password'=>$input['password']];
        if (!Auth::guard('web')->attempt([...$data,'is_active'=>true])) { RateLimiter::hit($key,60); \App\Support\LoginCap::failed('shop',$identity); throw ValidationException::withMessages(['email'=>'These credentials could not be verified.']); }
        RateLimiter::clear($key); $u=Auth::guard('web')->user(); \App\Support\LoginCap::remember($r,'shop',$u->email,$u->mobile_number); $r->session()->regenerate(); return redirect('/');
    }
    public function logout(Request $r)
    {
        Auth::guard('web')->logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return redirect('/login');
    }
}
