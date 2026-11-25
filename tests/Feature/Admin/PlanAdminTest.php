<?php
namespace Tests\Feature\Admin;
use App\Models\Platform\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;

class PlanAdminTest extends AdminTestCase
{
    public function test_plan_edit_and_inactive_plan_hidden_from_signup(): void
    {
        (new PlanSeeder)->run(); $admin=$this->signIn($this->admin());
        $plan=Plan::where('code','dhiran_monthly')->firstOrFail();
        $this->get('/admin/plans')->assertOk()->assertSee('dhiran_monthly');
        $this->patch('/admin/plans/'.$plan->id,['price_monthly'=>2000,'grace_days'=>5,'staff_limit'=>4,'max_items'=>1200,'is_active'=>0,'reason'=>'Repricing'])->assertRedirect();
        $plan->refresh();
        $this->assertSame([2000,5,4,1200,false],[$plan->priceFor('monthly'),$plan->grace_days,$plan->feature('staff_limit'),$plan->feature('max_items'),$plan->is_active]);
        $this->assertTrue((bool)$plan->feature('customers'));
        $this->assertDatabaseHas('platform_audit_logs',['actor_admin_id'=>$admin->id,'action'=>'plan.updated','target_id'=>$plan->id]);
        // the public pricing page shows one 1-year term, no plan list and no price, whatever the plan rows say
        \Illuminate\Support\Facades\Auth::shouldUse('web'); // one app instance serves both sessions in a test
        $this->get(route('site.pricing'))->assertOk()->assertSee('1 year of access')->assertDontSee('MyDhiranBook Monthly')->assertDontSee('MyDhiranBook Yearly')->assertDontSee('₹');
    }
    public function test_cannot_deactivate_every_plan_or_set_invalid_values(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin());
        $m=Plan::where('code','dhiran_monthly')->first(); $y=Plan::where('code','dhiran_yearly')->first();
        $this->patch('/admin/plans/'.$m->id,['price_monthly'=>1852,'grace_days'=>7,'staff_limit'=>3,'max_items'=>1000,'is_active'=>0])->assertRedirect();
        $this->patch('/admin/plans/'.$y->id,['price_monthly'=>1667,'price_yearly'=>19999,'grace_days'=>14,'staff_limit'=>7,'max_items'=>3000,'is_active'=>0])->assertSessionHasErrors('is_active');
        $this->patch('/admin/plans/'.$y->id,['price_monthly'=>-1,'price_yearly'=>19999,'grace_days'=>14,'staff_limit'=>7,'max_items'=>3000,'is_active'=>1])->assertSessionHasErrors('price_monthly');
        $this->assertTrue($y->fresh()->is_active);
    }
    public function test_admin_without_permission_403(): void
    {
        (new PlanSeeder)->run(); $this->limited(['can_manage_shops','can_manage_subscriptions']);
        $m=Plan::where('code','dhiran_monthly')->first();
        $this->get('/admin/plans')->assertForbidden();
        $this->patch('/admin/plans/'.$m->id,['price_monthly'=>1,'grace_days'=>1,'staff_limit'=>1,'max_items'=>1,'is_active'=>1])->assertForbidden();
        $this->assertSame(1852,$m->fresh()->priceFor('monthly'));
    }
    public function test_prices_must_be_whole_rupees(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin()); $plan=Plan::where('code','dhiran_monthly')->firstOrFail();
        $this->patch('/admin/plans/'.$plan->id,['price_monthly'=>499.50,'grace_days'=>5,'staff_limit'=>4,'max_items'=>1200,'is_active'=>1])->assertSessionHasErrors('price_monthly');
        $this->assertSame(1852,$plan->fresh()->priceFor('monthly'));
    }
}
