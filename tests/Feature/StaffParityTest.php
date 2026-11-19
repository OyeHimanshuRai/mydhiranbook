<?php
namespace Tests\Feature;
use App\Models\{Shop,User,Permission,Role};
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
class StaffParityTest extends TestCase
{
    use RefreshDatabase;
    public function test_source_staff_forms_permissions_termination_recovery_and_mobile_login(): void
    {
        $shop=self::createDhiranShop(['name'=>'Synthetic staff shop']);
        $roles=app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        $owner=User::create(['shop_id'=>$shop->id,'role_id'=>$roles['owner']->id,'name'=>'Synthetic Owner','email'=>'owner@staff.test','password'=>'Synthetic-pass-12345']);
        $this->actingAs($owner)->get('/settings?tab=staff')->assertOk()->assertSee('Staff Members');
        $this->get('/settings?tab=roles')->assertOk()->assertSee('dhiran.create');
        $this->get('/staff/create')->assertOk()->assertSee('Add Staff Member')->assertSee('Used for login');
        $this->post('/staff',['name'=>'Synthetic Clerk','mobile_number'=>'9000000082','password'=>'Synthetic-pass-12345','password_confirmation'=>'Synthetic-pass-12345','role_id'=>$roles['staff']->id])->assertRedirect();
        $staff=User::where('mobile_number','9000000082')->firstOrFail();
        $this->get('/staff/'.$staff->id.'/edit')->assertOk();
        $this->delete('/staff/'.$staff->id)->assertRedirect();
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs',['shop_id'=>$shop->id,'action'=>'staff_terminated']);
        $this->patch('/staff/'.$staff->id.'/reactivate')->assertRedirect();
        $this->assertTrue($staff->fresh()->is_active);
        Auth::guard('web')->logout();
        $this->post('/login',['email'=>'9000000082','password'=>'Synthetic-pass-12345'])->assertRedirect('/');
        $this->get('/staff/create')->assertForbidden();
        $this->get('/admin')->assertRedirect('/admin/login');
        TenantContext::runFor($shop->id,fn()=> $this->assertFalse($staff->fresh()->may('staff.manage')));
    }
    public function test_role_permissions_cannot_cross_shops_or_change_owner(): void
    {
        $a=self::createDhiranShop(['name'=>'Synthetic A']); $b=self::createDhiranShop(['name'=>'Synthetic B']);
        $rolesA=app(TenantRoleService::class)->ensureDefaultsForShop($a->id);$rolesB=app(TenantRoleService::class)->ensureDefaultsForShop($b->id);
        $owner=User::create(['shop_id'=>$a->id,'role_id'=>$rolesA['owner']->id,'name'=>'A','password'=>'Synthetic-pass-12345']);
        $this->actingAs($owner)->patch('/settings/roles/'.$rolesB['staff']->id,['permissions'=>[]])->assertNotFound();
        $this->patch('/settings/roles/'.$rolesA['owner']->id,['permissions'=>[]])->assertSessionHas('error');
        $id=Permission::where('name','dhiran.view')->value('id');
        $this->patch('/settings/roles/'.$rolesA['staff']->id,['permissions'=>[$id]])->assertRedirect();
        $this->assertSame([$id],$rolesA['staff']->permissions()->pluck('permissions.id')->all());
        $this->assertDatabaseHas('audit_logs',['shop_id'=>$a->id,'action'=>'role_permissions_updated']);
        $foreignOwner=User::create(['shop_id'=>$b->id,'role_id'=>$rolesB['owner']->id,'name'=>'B','password'=>'Synthetic-pass-12345']);
        $this->get('/staff/'.$foreignOwner->id.'/edit')->assertForbidden();
    }
}
