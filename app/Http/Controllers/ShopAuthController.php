<?php
namespace App\Http\Controllers;
use App\Models\Shop;
use App\Models\User;
use App\Models\Dhiran\DhiranSettings;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
class ShopAuthController
{
    public function loginForm() { return view('auth.login'); }
    public function registerForm() { return view('auth.register'); }
    public function register(Request $r)
    {
        $data=$r->validate(['name'=>'required|string|max:120','shop_name'=>'required|string|max:150','email'=>'required|email:strict|lowercase|max:190|unique:users,email','password'=>['required','confirmed',Password::min(12)]]);
        $user=DB::transaction(function() use($data) {
            $shop=Shop::create(['name'=>$data['shop_name']]);
            TenantContext::runFor($shop->id,fn()=>DhiranSettings::getForShop($shop->id)->update(['is_enabled'=>true]));
            $roles=app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($shop->id);
            return User::create(['role_id'=>$roles['owner']->id,'name'=>$data['name'],'email'=>$data['email'],'password'=>$data['password'],'shop_id'=>$shop->id,'role'=>'owner']);
        });
        Auth::guard('web')->login($user); $r->session()->regenerate(); return redirect('/');
    }
    public function login(Request $r)
    {
        $input=$r->validate(['email'=>'required|string|max:190','password'=>'required|string']);
        $identity=trim($input['email']);
        $column=filter_var($identity,FILTER_VALIDATE_EMAIL) ? 'email' : 'mobile_number';
        $identity=$column === 'email' ? strtolower($identity) : \App\Support\Mobile::normalize($identity);
        if (!$identity) { throw ValidationException::withMessages(['email'=>'Enter your email address or mobile number.']); }
        $data=[$column=>$identity,'password'=>$input['password']];
        if (!Auth::guard('web')->attempt([...$data,'is_active'=>true])) { throw ValidationException::withMessages(['email'=>'These credentials could not be verified.']); }
        $r->session()->regenerate(); return redirect('/');
    }
    public function logout(Request $r)
    {
        Auth::guard('web')->logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return redirect('/login');
    }
}
