<?php
namespace Tests\Feature\Admin;
use App\Models\Platform\{PlatformAuditLog,Plan};
use App\Services\TotpService;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Route;

class AdminSecurityTest extends AdminTestCase
{
    /** Auth routes are excluded: they have their own audit events and throttling (login, mfa, logout, forgot/reset password). */
    private const AUTH_WRITES=['admin/login','admin/mfa','admin/logout','admin/forgot-password','admin/reset-password'];

    private function adminWriteUris(): array
    {
        return collect(Route::getRoutes()->getRoutes())->filter(fn($r)=>str_starts_with($r->uri(),'admin/') && array_intersect($r->methods(),['POST','PATCH','PUT','DELETE']) && !in_array($r->uri(),self::AUTH_WRITES))
            ->map(fn($r)=>collect($r->methods())->first(fn($m)=>in_array($m,['POST','PATCH','PUT','DELETE'])).' '.$r->uri())->sort()->values()->all();
    }
    public function test_every_admin_write_route_writes_audit_log(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin());
        $shop=self::createDhiranShop(['name'=>'Audit Shop']); $plan=Plan::where('code','dhiran_yearly')->first();
        $support=$this->admin(['email'=>'support@example.test','role'=>'support','permissions'=>[]]);
        $ends=today()->addYears(2)->toDateString();
        $p1=\App\Models\Shop::create(['name'=>'Waiting A','status'=>'pending']); $p2=\App\Models\Shop::create(['name'=>'Waiting B','status'=>'pending']);
        $table=[
            'POST admin/requests/{shop}/approve'=>['/admin/requests/'.$p1->id.'/approve',['plan'=>'dhiran_yearly','billing_cycle'=>'yearly','ends_at'=>$ends]],
            'POST admin/requests/{shop}/reject'=>['/admin/requests/'.$p2->id.'/reject',['reason'=>'Audit']],
            'POST admin/shops/{shop}/extend'=>['/admin/shops/'.$shop->id.'/extend',[]],
            'PATCH admin/shops/{shop}'=>['/admin/shops/'.$shop->id,['status'=>'suspended','reason'=>'Audit']],
            'POST admin/shops/{shop}/subscription'=>['/admin/shops/'.$shop->id.'/subscription',['plan'=>'dhiran_yearly','billing_cycle'=>'yearly','ends_at'=>$ends,'reason'=>'Audit']],
            'POST admin/shops/{shop}/subscription/terminate'=>['/admin/shops/'.$shop->id.'/subscription/terminate',['reason'=>'Audit']],
            'PATCH admin/plans/{plan}'=>['/admin/plans/'.$plan->id,['price_monthly'=>1667,'price_yearly'=>19999,'grace_days'=>14,'staff_limit'=>7,'max_items'=>3000,'is_active'=>1,'reason'=>'Audit']],
            'POST admin/accounts'=>['/admin/accounts',['name'=>'New Support','email'=>'new@example.test','password'=>'Long-enough-pass-1']],
            'PATCH admin/accounts/{admin}'=>['/admin/accounts/'.$support->id,['is_active'=>0]],
        ];
        // a new admin write route without a row here fails this assertion
        $this->assertSame(collect(array_keys($table))->sort()->values()->all(),$this->adminWriteUris());
        foreach ($table as $name=>[$url,$payload]) {
            $before=PlatformAuditLog::count(); [$method]=explode(' ',$name);
            $this->call($method,$url,$payload)->assertSessionHasNoErrors()->assertRedirect();
            $this->assertGreaterThan($before,PlatformAuditLog::count(),"$name wrote no audit row");
        }
    }
    public function test_admin_session_expires_after_idle_limit(): void
    {
        $this->signIn($this->admin());
        $this->travel(29)->minutes(); $this->get('/admin')->assertOk(); // activity resets the idle clock
        $this->travel(29)->minutes(); $this->get('/admin')->assertOk();
        $this->travel(31)->minutes(); $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }
    public function test_admin_routes_require_completed_mfa(): void
    {
        $admin=$this->admin(); $shop=self::createDhiranShop(['name'=>'Mfa Shop']);
        $this->post('/admin/login',['email'=>$admin->email,'password'=>'Admin-pass-12345'])->assertRedirect('/admin/mfa');
        foreach (['/admin','/admin/shops','/admin/plans','/admin/accounts','/admin/audit'] as $u) { $this->get($u)->assertRedirect('/admin/mfa'); }
        $this->patch('/admin/shops/'.$shop->id,['status'=>'suspended','reason'=>'x'])->assertRedirect('/admin/mfa');
        $this->post('/admin/accounts',['name'=>'X','email'=>'x@example.test','password'=>'Long-enough-pass-1'])->assertRedirect('/admin/mfa');
        $this->assertSame('active',$shop->fresh()->status);
        $this->assertDatabaseMissing('platform_admins',['email'=>'x@example.test']);
    }
    public function test_disabled_admin_logged_out_on_next_request(): void
    {
        $this->admin(['email'=>'other@example.test']); // model refuses to disable the last active super admin
        $admin=$this->signIn($this->admin()); $this->get('/admin')->assertOk();
        $admin->forceFill(['is_active'=>false])->save(); app('auth')->forgetGuards(); // one app instance in tests caches the guard user; real requests reload it
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/shops')->assertRedirect('/admin/login'); // session is gone, not just redirected once
    }
}
