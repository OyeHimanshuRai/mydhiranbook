<?php
namespace Tests\Feature;
use App\Models\Shop;
use App\Models\User;
use App\Models\Platform\PlatformAdmin;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;
    private function admin(array $attrs=[]): PlatformAdmin
    {
        return PlatformAdmin::create(array_merge(['name'=>'Synthetic Platform Admin','email'=>'admin@example.test','password'=>'Admin-pass-12345','role'=>'super_admin','is_active'=>true,'two_factor_secret'=>app(TotpService::class)->generateSecret(),'two_factor_enabled'=>true],$attrs))->fresh();
    }
    private function signIn(PlatformAdmin $admin): void
    {
        $this->post('/admin/login',['email'=>$admin->email,'password'=>'Admin-pass-12345'])->assertRedirect('/admin/mfa');
        $this->get('/admin')->assertRedirect('/admin/mfa');
        $code=app(TotpService::class)->computeCode($admin->two_factor_secret,(int)floor(time()/30));
        $this->post('/admin/mfa',['code'=>$code])->assertRedirect('/admin');
    }
    public function test_admin_password_mfa_dashboard_and_local_shop_subscription_management(): void
    {
        $admin=$this->admin(); $shop=Shop::create(['name'=>'Synthetic Tenant']);
        (new \Database\Seeders\PlanSeeder)->run();
        $this->signIn($admin);
        $this->get('/admin')->assertOk()->assertSee('Dhiran platform');
        $this->get('/admin/shops')->assertOk()->assertSee('Synthetic Tenant');
        $this->patch('/admin/shops/'.$shop->id,['status'=>'suspended','reason'=>'Synthetic acceptance check'])->assertRedirect();
        $this->assertDatabaseHas('shops',['id'=>$shop->id,'status'=>'suspended']);
        $this->post('/admin/shops/'.$shop->id.'/subscription',['plan'=>'dhiran_yearly','billing_cycle'=>'yearly','ends_at'=>today()->addMonth()->toDateString(),'reason'=>'Local acceptance'])->assertRedirect();
        $this->assertDatabaseHas('shop_subscriptions',['shop_id'=>$shop->id,'status'=>'active']);
        $this->get('/admin/audit')->assertOk()->assertSee('shop.updated')->assertSee('subscription.updated');
        $this->assertDatabaseHas('platform_audit_logs',['actor_admin_id'=>$admin->id,'action'=>'admin.mfa_passed']);
        $this->get('/dhiran')->assertRedirect('/login');
    }
    public function test_shop_owner_and_foreign_jewelflows_cookie_grant_no_admin_access(): void
    {
        $shop=Shop::create(['name'=>'Owner shop']); $owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'Owner-pass-12345','shop_id'=>$shop->id]);
        $this->actingAs($owner,'web')->get('/admin')->assertRedirect('/admin/login');
        Auth::guard('web')->logout();
        $this->withUnencryptedCookie('jewelflows_platform_admin_session','foreign-session')->get('/admin')->assertRedirect('/admin/login');
        $this->withUnencryptedCookie('jewelflows_session','foreign-session')->get('/')->assertOk()->assertSee('Get started')->assertDontSee('Loan book'); // guest / is the public home
        $this->post('/admin/login',['email'=>$owner->email,'password'=>'Owner-pass-12345'])->assertSessionHasErrors('email');
        $this->assertGuest('platform_admin');
    }
    public function test_bad_mfa_is_refused_and_recovery_codes_are_single_use(): void
    {
        $admin=$this->admin(); $codes=$admin->generateRecoveryCodes();
        $this->post('/admin/login',['email'=>$admin->email,'password'=>'Admin-pass-12345']);
        $this->post('/admin/mfa',['code'=>'invalid'])->assertSessionHasErrors('code');
        $this->get('/admin')->assertRedirect('/admin/mfa');
        $this->post('/admin/mfa',['code'=>$codes[0]])->assertRedirect('/admin');
        $this->assertCount(7,$admin->fresh()->recovery_codes);
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->post('/admin/login',['email'=>$admin->email,'password'=>'Admin-pass-12345']);
        $this->post('/admin/mfa',['code'=>$codes[0]])->assertSessionHasErrors('code');
        $this->get('/admin')->assertRedirect('/admin/mfa');
    }
    public function test_admin_permissions_cannot_be_bypassed_with_a_post(): void
    {
        $this->admin(['email'=>'super@example.test']);
        $limited=$this->admin(['role'=>'support','permissions'=>[]]); $shop=Shop::create(['name'=>'Protected']);
        $this->signIn($limited);
        $this->patch('/admin/shops/'.$shop->id,['status'=>'suspended','reason'=>'Denied'])->assertForbidden();
        $this->post('/admin/shops/'.$shop->id.'/subscription',['plan'=>'dhiran_yearly','billing_cycle'=>'yearly','ends_at'=>today()->addMonth()->toDateString(),'reason'=>'Denied'])->assertForbidden();
        $this->get('/admin/audit')->assertForbidden();
        $this->assertSame('active',$shop->fresh()->status);
    }
    public function test_password_recovery_is_separate_single_use_and_keeps_mfa_required(): void
    {
        $admin=$this->admin(); $token=Password::broker('platform_admins')->createToken($admin);
        $this->post('/admin/reset-password',['email'=>$admin->email,'token'=>$token,'password'=>'New-admin-pass-12345','password_confirmation'=>'New-admin-pass-12345'])->assertRedirect('/admin/login');
        $this->assertTrue(Hash::check('New-admin-pass-12345',$admin->fresh()->password));
        $this->assertTrue($admin->fresh()->two_factor_enabled);
        $this->post('/admin/reset-password',['email'=>$admin->email,'token'=>$token,'password'=>'Another-pass-12345','password_confirmation'=>'Another-pass-12345'])->assertSessionHasErrors();
        $this->assertTrue(Hash::check('New-admin-pass-12345',$admin->fresh()->password));
        $this->assertDatabaseCount('platform_password_reset_tokens',0);
        $this->assertDatabaseHas('platform_audit_logs',['action'=>'admin.password_reset']);
    }
    public function test_new_admin_must_enroll_mfa_before_accessing_panel(): void
    {
        $admin=$this->admin(['two_factor_enabled'=>false,'two_factor_secret'=>null]);
        $this->post('/admin/login',['email'=>$admin->email,'password'=>'Admin-pass-12345'])->assertRedirect('/admin/mfa');
        $this->get('/admin/mfa')->assertOk()->assertSee('Set up your authenticator');
        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $code=app(TotpService::class)->computeCode($admin->two_factor_secret,(int)floor(time()/30));
        $this->post('/admin/mfa',['code'=>$code])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Recovery codes');
        $this->assertTrue($admin->fresh()->two_factor_enabled);
        $this->assertCount(8,$admin->fresh()->recovery_codes);
    }
}
