<?php
namespace App\Http\Controllers\Admin;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
class AuthController
{
    public function __construct(private PlatformAuditService $audit, private TotpService $totp) {}
    public function form() { return view('admin.login'); }
    public function login(Request $r)
    {
        $data=$r->validate(['email'=>'required|email','password'=>'required|string']);
        \App\Support\LoginCap::check($r,'admin',$data['email']); // 30 failed tries an hour per account, whatever the IP
        if (!Auth::guard('platform_admin')->attempt([...$data,'is_active'=>true])) {
            \App\Support\LoginCap::failed('admin',$data['email']);
            $this->audit->log(null,'admin.login_failed',reason:'Credentials refused',request:$r);
            throw ValidationException::withMessages(['email'=>'These platform credentials could not be verified.']);
        }
        $r->session()->regenerate(); $r->session()->forget('admin.mfa_admin_id');
        $admin=Auth::guard('platform_admin')->user();
        $r->session()->put(['admin.password_at'=>time(),'admin.password_stamp'=>hash('sha256',$admin->password)]);
        $this->audit->log($admin,'admin.password_passed',targetId:$admin->id,request:$r);
        return redirect('/admin/mfa');
    }
    private function enrolmentClosed(Request $r, PlatformAdmin $admin)
    {
        if ($admin->mfaEnrolmentOpen()) { return null; }
        $this->audit->log($admin,'admin.mfa_enrolment_refused',targetId:$admin->id,request:$r);
        Auth::guard('platform_admin')->logout(); $r->session()->invalidate(); $r->session()->regenerateToken();
        return redirect('/admin/login')->withErrors(['email'=>'Authenticator setup window has closed. Use "Forgot password" to reset your password, which reopens it for 24 hours.']);
    }
    public function mfaForm(Request $r)
    {
        $admin=Auth::guard('platform_admin')->user();
        if ($closed=$this->enrolmentClosed($r,$admin)) { return $closed; }
        if (!$admin->two_factor_enabled && !$admin->two_factor_secret) { $admin->forceFill(['two_factor_secret'=>$this->totp->generateSecret()])->save(); }
        return view('admin.mfa',['admin'=>$admin]);
    }
    public function mfa(Request $r)
    {
        $data=$r->validate(['code'=>'required|string|max:30']); $admin=Auth::guard('platform_admin')->user();
        if ($closed=$this->enrolmentClosed($r,$admin)) { return $closed; }
        $result=DB::transaction(function() use($admin,$data) {
            $admin=PlatformAdmin::lockForUpdate()->findOrFail($admin->id);
            $valid=false; $codes=[];
            if ($admin->two_factor_secret && preg_match('/^[0-9]{6}$/',$data['code'])) {
                $current=(int)floor(time()/30);
                for ($window=$current-1;$window<=$current+1;$window++) {
                    if ($window > $admin->last_totp_window && hash_equals($this->totp->computeCode($admin->two_factor_secret,$window),$data['code'])) {
                        $admin->last_totp_window=$window; $valid=true; break;
                    }
                }
            } elseif ($admin->two_factor_enabled) { $valid=$admin->useRecoveryCode(strtoupper($data['code'])); }
            if ($valid) {
                if (!$admin->two_factor_enabled) { $codes=$admin->generateRecoveryCodes(); }
                $admin->forceFill(['two_factor_enabled'=>true,'last_login_at'=>now()])->save();
            }
            return ['valid'=>$valid,'codes'=>$codes];
        });
        if (!$result['valid']) {
            $this->audit->log($admin,'admin.mfa_failed',targetId:$admin->id,request:$r);
            throw ValidationException::withMessages(['code'=>'Code could not be verified. Use a fresh authenticator code or an unused recovery code.']);
        }
        Auth::guard('platform_admin')->setUser($admin->fresh());
        $r->session()->regenerate(); $r->session()->put('admin.mfa_admin_id',$admin->id); \App\Support\LoginCap::remember($r,'admin',$admin->email); // known device only after MFA
        if ($result['codes']) { $r->session()->flash('recovery_codes',$result['codes']); }
        $this->audit->log($admin,'admin.mfa_passed',targetId:$admin->id,request:$r);
        return redirect('/admin');
    }
    public function logout(Request $r)
    {
        $this->audit->log(Auth::guard('platform_admin')->user(),'admin.logout',request:$r);
        Auth::guard('platform_admin')->logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return redirect('/admin/login');
    }
    public function forgotForm() { return view('admin.forgot'); }
    public function forgot(Request $r)
    {
        $r->validate(['email'=>'required|email']);
        Password::broker('platform_admins')->sendResetLink($r->only('email'));
        $this->audit->log(null,'admin.recovery_requested',request:$r);
        return back()->with('success','If this admin account exists, a recovery link has been delivered to the local mail log.');
    }
    public function resetForm(Request $r,string $token) { return view('admin.reset',['token'=>$token,'email'=>$r->query('email')]); }
    public function reset(Request $r)
    {
        $r->validate(['email'=>'required|email','token'=>'required|string','password'=>['required','confirmed',PasswordRule::min(12)]]);
        $result=Password::broker('platform_admins')->reset($r->only('email','token','password','password_confirmation'),function(PlatformAdmin $admin,string $password) use($r) {
            $admin->forceFill(['password'=>Hash::make($password),'remember_token'=>Str::random(60),'password_changed_at'=>now()] + ($admin->two_factor_enabled ? [] : ['two_factor_secret'=>null]))->save(); // a reopened window never reuses a secret shown before
            $this->audit->log($admin,'admin.password_reset',targetId:$admin->id,request:$r); \App\Support\LoginCap::clear('admin',$admin->email);
        });
        if ($result !== Password::PASSWORD_RESET) { throw ValidationException::withMessages(['email'=>'This reset link is invalid or has expired.']); }
        Auth::guard('platform_admin')->logout(); $r->session()->invalidate(); $r->session()->regenerateToken();
        return redirect('/admin/login')->with('success','Password reset. Sign in and complete MFA.');
    }
}
