<?php
namespace Tests\Feature\Admin;
use App\Models\Platform\PlatformAdmin;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;
    protected function admin(array $attrs=[]): PlatformAdmin
    {
        return PlatformAdmin::create(array_merge(['name'=>'Synthetic Platform Admin','email'=>'admin@example.test','password'=>'Admin-pass-12345','role'=>'super_admin','is_active'=>true,'two_factor_secret'=>app(TotpService::class)->generateSecret(),'two_factor_enabled'=>true],$attrs))->fresh();
    }
    protected function signIn(PlatformAdmin $admin): PlatformAdmin
    {
        $this->post('/admin/login',['email'=>$admin->email,'password'=>'Admin-pass-12345'])->assertRedirect('/admin/mfa');
        $code=app(TotpService::class)->computeCode($admin->two_factor_secret,(int)floor(time()/30));
        $this->post('/admin/mfa',['code'=>$code])->assertRedirect('/admin');
        return $admin;
    }
    /** Signed-in admin holding only the given permissions. */
    protected function limited(array $permissions): PlatformAdmin
    {
        $this->admin(['email'=>'super@example.test']);
        return $this->signIn($this->admin(['role'=>'support','permissions'=>$permissions]));
    }
}
