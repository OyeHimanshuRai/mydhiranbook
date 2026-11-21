<?php
namespace Tests\Feature;
use App\Models\{Permission,Role,User};
use App\Models\Dhiran\{DhiranAttachment,DhiranSettings};
use App\Models\Platform\PlatformAdmin;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash,RateLimiter,Storage};
use Tests\TestCase;
class HardeningTest extends TestCase
{
    use RefreshDatabase;
    private function adminCreated(string $when, array $attrs=[]): PlatformAdmin
    {
        $a=PlatformAdmin::create(['name'=>'A','email'=>'late@example.test','password'=>'Admin-pass-12345','role'=>'super_admin']+$attrs);
        $a->forceFill(['created_at'=>$when,'updated_at'=>$when])->saveQuietly();
        return $a;
    }
    public function test_security_headers_on_public_page_and_hsts_only_when_secure(): void
    {
        $r=$this->get('/login');
        $r->assertHeader('X-Frame-Options','DENY')->assertHeader('X-Content-Type-Options','nosniff')->assertHeader('Referrer-Policy','strict-origin-when-cross-origin')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security','max-age=31536000; includeSubDomains');
    }
    public function test_attachment_response_is_sandboxed(): void
    {
        Storage::fake('local');
        $shop=self::createDhiranShop(['name'=>'Hdr shop']); DhiranSettings::getForShop($shop->id)->update(['is_enabled'=>true]);
        $owner=self::userWithRole($shop,['name'=>'O','email'=>'o@hdr.test','password'=>'Synthetic-pass-12345','email_verified_at'=>now()]);
        Storage::disk('local')->put('dhiran/x/a.png','x');
        $att=DhiranAttachment::create(['shop_id'=>$shop->id,'owner_type'=>DhiranAttachment::OWNER_LOAN,'owner_id'=>1,'document_type'=>'id_proof_front','file_disk'=>'local','file_path'=>'dhiran/x/a.png','original_name'=>'a.png','mime_type'=>'image/png','size_bytes'=>1]);
        $this->actingAs($owner)->get(route('dhiran.attachments.show',$att))->assertOk()->assertHeader('Content-Security-Policy','sandbox')->assertHeader('X-Content-Type-Options','nosniff');
    }
    public function test_pdf_attachment_is_not_sandboxed_so_browsers_can_view_it(): void
    {
        $shop=self::createDhiranShop(['name'=>'Pdf shop']);
        $owner=\App\Models\User::create(['name'=>'O','email'=>'pdf@example.test','password'=>'Local-pass-12345','shop_id'=>$shop->id,'role_id'=>app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($shop->id)['owner']->id]);
        Storage::fake('local'); Storage::disk('local')->put('dhiran/x/a.pdf','%PDF-1.4');
        $att=DhiranAttachment::create(['shop_id'=>$shop->id,'owner_type'=>DhiranAttachment::OWNER_LOAN,'owner_id'=>1,'document_type'=>'loan_document','file_disk'=>'local','file_path'=>'dhiran/x/a.pdf','original_name'=>'a.pdf','mime_type'=>'application/pdf','size_bytes'=>8]);
        $this->actingAs($owner)->get(route('dhiran.attachments.show',$att))->assertOk()->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Content-Type-Options','nosniff');
    }
    public function test_trusted_proxies_come_from_config_at_request_time(): void
    {
        $proxied=fn()=>$this->withServerVariables(['REMOTE_ADDR'=>'10.0.0.9'])->withHeaders(['X-Forwarded-Proto'=>'https'])->get('/');
        config(['app.trusted_proxies'=>'']);
        $proxied()->assertHeaderMissing('Strict-Transport-Security');
        config(['app.trusted_proxies'=>'10.0.0.9']);
        $proxied()->assertHeader('Strict-Transport-Security');
    }
    public function test_password_reset_discards_unconfirmed_mfa_secret(): void
    {
        $a=$this->adminCreated(now()->subDays(3)->toDateTimeString(),['two_factor_secret'=>app(TotpService::class)->generateSecret()]);
        $token=\Illuminate\Support\Facades\Password::broker('platform_admins')->createToken($a);
        $this->post('/admin/reset-password',['email'=>$a->email,'token'=>$token,'password'=>'New-admin-pass-123','password_confirmation'=>'New-admin-pass-123']);
        $this->assertNull($a->fresh()->two_factor_secret);
        $this->assertNotNull($a->fresh()->password_changed_at);
    }
    public function test_mfa_first_enrolment_only_inside_24h_window(): void
    {
        $old=$this->adminCreated(now()->subDays(3)->toDateTimeString());
        $this->post('/admin/login',['email'=>$old->email,'password'=>'Admin-pass-12345']);
        $this->get('/admin/mfa')->assertRedirect('/admin/login')->assertSessionHasErrors('email');
        $this->assertNull($old->fresh()->two_factor_secret);
        // a password reset reopens the window
        $old->forceFill(['password_changed_at'=>now()])->save();
        $this->post('/admin/login',['email'=>$old->email,'password'=>'Admin-pass-12345']);
        $this->get('/admin/mfa')->assertOk()->assertSee('Set up your authenticator');
        $code=app(TotpService::class)->computeCode($old->fresh()->two_factor_secret,(int)floor(time()/30));
        $this->post('/admin/mfa',['code'=>$code])->assertRedirect('/admin');
    }
    public function test_mfa_post_refused_outside_window_even_with_stored_secret(): void
    {
        $a=$this->adminCreated(now()->subDays(3)->toDateTimeString(),['two_factor_secret'=>app(TotpService::class)->generateSecret()]);
        $this->post('/admin/login',['email'=>$a->email,'password'=>'Admin-pass-12345']);
        $code=app(TotpService::class)->computeCode($a->two_factor_secret,(int)floor(time()/30));
        $this->post('/admin/mfa',['code'=>$code])->assertRedirect('/admin/login');
        $this->assertFalse($a->fresh()->two_factor_enabled);
    }
    private function staffSetup(): array
    {
        $shop=self::createDhiranShop(['name'=>'Staff guard']);
        $roles=app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        $roles['manager']->permissions()->attach(Permission::where('name','staff.manage')->first()->id);
        $special=new Role; $special->forceFill(['shop_id'=>$shop->id,'name'=>'special','display_name'=>'Special'])->save();
        $special->permissions()->sync(Permission::pluck('id'));
        $mk=fn($n,$role)=>User::create(['shop_id'=>$shop->id,'role_id'=>$role->id,'name'=>$n,'mobile_number'=>'90000'.random_int(10000,99999),'password'=>'Synthetic-pass-12345']);
        return [$mk('Mgr',$roles['manager']),$mk('Powerful',$special),$mk('Plain',$roles['staff']),$roles];
    }
    public function test_manager_cannot_manage_staff_whose_role_exceeds_theirs(): void
    {
        [$mgr,$powerful,$plain,$roles]=$this->staffSetup();
        $this->actingAs($mgr);
        $this->put('/staff/'.$powerful->id,['name'=>'X','mobile_number'=>$powerful->mobile_number,'role_id'=>$roles['staff']->id,'password'=>'Brand-New-Pass-12345','password_confirmation'=>'Brand-New-Pass-12345'])->assertForbidden();
        $this->assertTrue(Hash::check('Synthetic-pass-12345',$powerful->fresh()->password));
        $this->delete('/staff/'.$powerful->id)->assertForbidden();
        $this->assertTrue($powerful->fresh()->is_active);
        $this->delete('/staff/'.$plain->id)->assertRedirect();
    }
    public function test_staff_password_needs_12_chars(): void
    {
        [$mgr,,$plain,$roles]=$this->staffSetup();
        $this->actingAs($mgr)->put('/staff/'.$plain->id,['name'=>'X','mobile_number'=>$plain->mobile_number,'role_id'=>$roles['staff']->id,'password'=>'Short1Aa','password_confirmation'=>'Short1Aa'])->assertSessionHasErrors('password');
    }
    public function test_shop_login_is_limited_per_email_and_cleared_on_success(): void
    {
        $shop=self::createDhiranShop(['name'=>'Login lim']);
        self::userWithRole($shop,['name'=>'O','email'=>'lim@x.test','password'=>'Synthetic-pass-12345']);
        for($i=0;$i<5;$i++){ RateLimiter::hit('shop-login:lim@x.test|127.0.0.1',60); }
        $this->post('/login',['email'=>'LIM@x.test','password'=>'Synthetic-pass-12345'])->assertSessionHasErrors('email');
        $this->assertGuest();
        RateLimiter::clear('shop-login:lim@x.test|127.0.0.1');
        RateLimiter::hit('shop-login:lim@x.test|127.0.0.1',60);
        $this->post('/login',['email'=>'lim@x.test','password'=>'Synthetic-pass-12345'])->assertRedirect('/');
        $this->assertSame(0,RateLimiter::attempts('shop-login:lim@x.test|127.0.0.1'));
    }
    public function test_create_admin_command(): void
    {
        $this->artisan('dhiran:create-admin',['email'=>'Boss@Example.test','--name'=>'Boss'])->assertSuccessful()->expectsOutputToContain('Temporary password');
        $a=PlatformAdmin::where('email','boss@example.test')->firstOrFail();
        $this->assertSame('super_admin',$a->role); $this->assertFalse($a->two_factor_enabled); $this->assertNull($a->two_factor_secret);
        $this->artisan('dhiran:create-admin',['email'=>'boss@example.test'])->assertFailed();
        $this->assertSame(1,PlatformAdmin::count());
    }
    public function test_customer_pan_and_id_number_are_encrypted_at_rest(): void
    {
        $shop=self::createDhiranShop(['name'=>'Enc']);
        $c=\App\Support\TenantContext::runFor($shop->id,fn()=>\App\Models\Customer::create(['shop_id'=>$shop->id,'first_name'=>'A','mobile'=>'9813330077','pan'=>'ABCDE1234F','id_number'=>'ID-99']));
        $raw=\Illuminate\Support\Facades\DB::table('customers')->where('id',$c->id)->first();
        $this->assertNotSame('ABCDE1234F',$raw->pan); $this->assertNotSame('ID-99',$raw->id_number);
        $this->assertSame('ABCDE1234F',\Illuminate\Support\Facades\Crypt::decryptString($raw->pan));
        $this->assertSame('ABCDE1234F',\App\Support\TenantContext::runFor($shop->id,fn()=>\App\Models\Customer::find($c->id)->pan));
    }
}
