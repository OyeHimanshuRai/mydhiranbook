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
    public function test_staff_cannot_edit_own_role_permissions(): void
    {
        $shop=self::createDhiranShop(['name'=>'Self role shop']); $roles=app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        $manager=User::create(['shop_id'=>$shop->id,'role_id'=>$roles['staff']->id,'name'=>'Staff Editor','email'=>'se@staff.test','password'=>'Synthetic-pass-12345']);
        $roles['staff']->permissions()->syncWithoutDetaching([Permission::where('name','settings.edit')->firstOrFail()->id]);
        $before=$roles['staff']->permissions()->pluck('permissions.id')->sort()->values()->all();
        $this->actingAs($manager)->patch('/settings/roles/'.$roles['staff']->id,['permissions'=>[Permission::first()->id]])->assertForbidden();
        $this->assertSame($before,$roles['staff']->permissions()->pluck('permissions.id')->sort()->values()->all());
    }
    /** Cashier (view+pay) who may also edit roles and manage staff; staff role (view+create+pay) is stronger. */
    private function delegate(): array
    {
        $shop=self::createDhiranShop(['name'=>'Delegation shop']); $roles=app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        $roles['cashier']->permissions()->syncWithoutDetaching(Permission::whereIn('name',['settings.edit','staff.manage'])->pluck('id'));
        $lead=User::create(['shop_id'=>$shop->id,'role_id'=>$roles['cashier']->id,'name'=>'Lead','mobile_number'=>'9000000091','password'=>'Synthetic-pass-12345']);
        return [$shop,$roles,$lead];
    }
    public function test_staff_cannot_change_own_role(): void
    {
        [,$roles,$lead]=$this->delegate();
        $this->actingAs($lead)->put('/staff/'.$lead->id,['name'=>'Lead','mobile_number'=>'9000000091','role_id'=>$roles['manager']->id])->assertForbidden();
        $this->assertSame($roles['cashier']->id,$lead->fresh()->role_id);
    }
    public function test_non_owner_cannot_grant_permission_they_lack(): void
    {
        [,$roles,$lead]=$this->delegate(); $create=Permission::where('name','dhiran.create')->value('id');
        $this->actingAs($lead)->patch('/settings/roles/'.$roles['viewer']->id,['permissions'=>[$create]])->assertForbidden();
        $this->assertFalse($roles['viewer']->hasPermission('dhiran.create'));
        $pay=Permission::where('name','dhiran.pay')->value('id'); // held by the lead: allowed
        $this->patch('/settings/roles/'.$roles['viewer']->id,['permissions'=>[$pay]])->assertRedirect();
    }
    public function test_non_owner_cannot_assign_role_stronger_than_own(): void
    {
        [,$roles,$lead]=$this->delegate(); $clerk=User::create(['shop_id'=>$lead->shop_id,'role_id'=>$roles['viewer']->id,'name'=>'Clerk','mobile_number'=>'9000000092','password'=>'Synthetic-pass-12345']);
        $this->actingAs($lead)->put('/staff/'.$clerk->id,['name'=>'Clerk','mobile_number'=>'9000000092','role_id'=>$roles['staff']->id])->assertForbidden();
        $this->post('/staff',['name'=>'New','mobile_number'=>'9000000093','password'=>'Synthetic-pass-12345','password_confirmation'=>'Synthetic-pass-12345','role_id'=>$roles['manager']->id])->assertForbidden();
        $this->assertSame($roles['viewer']->id,$clerk->fresh()->role_id);
        $this->put('/staff/'.$clerk->id,['name'=>'Clerk','mobile_number'=>'9000000092','role_id'=>$roles['cashier']->id])->assertRedirect(); // equal role is fine
    }
    public function test_non_owner_cannot_remove_permissions_from_stronger_role(): void
    {
        [,$roles,$lead]=$this->delegate(); $this->actingAs($lead);
        $before=$roles['staff']->permissions()->pluck('permissions.id')->sort()->values()->all();
        $keep=Permission::where('name','dhiran.view')->value('id');
        $this->patch('/settings/roles/'.$roles['staff']->id,['permissions'=>[$keep]])->assertForbidden(); // staff holds dhiran.create, lead does not
        $this->assertSame($before,$roles['staff']->permissions()->pluck('permissions.id')->sort()->values()->all());
        $roles['viewer']->permissions()->sync(Permission::whereIn('name',['dhiran.view','dhiran.pay'])->pluck('id'));
        $this->patch('/settings/roles/'.$roles['viewer']->id,['permissions'=>[$keep]])->assertRedirect(); // removing a held permission from a weaker role works
        $this->assertSame([$keep],$roles['viewer']->permissions()->pluck('permissions.id')->all());
    }
    public function test_legacy_role_column_grants_nothing(): void
    {
        $shop=self::createDhiranShop(['name'=>'Legacy role shop']);
        \App\Models\Dhiran\DhiranSettings::getForShop($shop->id)->update(['is_enabled'=>true]);
        $u=User::create(['shop_id'=>$shop->id,'role'=>'owner','role_id'=>null,'name'=>'Legacy','email'=>'legacy@staff.test','password'=>'Synthetic-pass-12345']);
        $this->assertFalse($u->hasPermission('dhiran.view'));
        $this->assertFalse($u->isShopOwner()); $this->assertFalse($u->isOwner()); $this->assertFalse($u->hasRole('owner'));
        $this->actingAs($u)->post('/dhiran/customers',['first_name'=>'X','mobile'=>'9000000003'])->assertForbidden();
    }
    public function test_no_route_writes_users_role(): void
    {
        foreach (['app/Http','app/Services'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir),\FilesystemIterator::SKIP_DOTS)) as $f) {
                if (!$f->isFile() || str_contains($f->getPathname(),'Admin')) { continue; }
                $this->assertDoesNotMatchRegularExpression("/'role'\s*=>/",file_get_contents($f->getPathname()),$f->getPathname().' writes users.role');
            }
        }
    }
    public function test_owner_can_grant_any_non_owner_permission(): void
    {
        $shop=self::createDhiranShop(['name'=>'Owner grant']); $roles=app(TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        $owner=User::create(['shop_id'=>$shop->id,'role_id'=>$roles['owner']->id,'name'=>'O','password'=>'Synthetic-pass-12345']);
        $ids=Permission::pluck('id')->all();
        $this->actingAs($owner)->patch('/settings/roles/'.$roles['viewer']->id,['permissions'=>$ids])->assertRedirect();
        $this->assertCount(count($ids),$roles['viewer']->permissions);
    }
}
