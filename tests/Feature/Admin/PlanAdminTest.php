<?php
namespace Tests\Feature\Admin;
use App\Models\Platform\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;

class PlanAdminTest extends AdminTestCase
{
    public function test_plan_limits_edit_is_audited_and_pricing_page_shows_no_plan_or_price(): void
    {
        (new PlanSeeder)->run(); $admin=$this->signIn($this->admin());
        $plan=Plan::where('code','dhiran_monthly')->firstOrFail();
        $this->get('/admin/plans')->assertOk()->assertSee('dhiran_monthly')->assertDontSee('Offered for sale')->assertDontSee('nobody can sign up');
        $this->patch('/admin/plans/'.$plan->id,['grace_days'=>5,'staff_limit'=>4,'max_items'=>1200,'reason'=>'Raise limits'])->assertRedirect();
        $plan->refresh();
        $this->assertSame([5,4,1200],[$plan->grace_days,$plan->feature('staff_limit'),$plan->feature('max_items')]);
        $this->assertSame(1852,(int)$plan->price_monthly); // prices are no longer editable
        $this->assertTrue((bool)$plan->feature('customers'));
        $this->assertDatabaseHas('platform_audit_logs',['actor_admin_id'=>$admin->id,'action'=>'plan.updated','target_id'=>$plan->id]);
        // the public pricing page shows one 1-year term, no plan list and no price, whatever the plan rows say
        \Illuminate\Support\Facades\Auth::shouldUse('web'); // one app instance serves both sessions in a test
        $this->get(route('site.pricing'))->assertOk()->assertSee('1 year of access')->assertDontSee('MyDhiranBook Monthly')->assertDontSee('MyDhiranBook Yearly')->assertDontSee('₹');
    }
    public function test_invalid_limits_are_refused_and_prices_cannot_be_changed(): void
    {
        (new PlanSeeder)->run(); $this->signIn($this->admin());
        $y=Plan::where('code','dhiran_yearly')->first();
        $this->patch('/admin/plans/'.$y->id,['grace_days'=>14,'staff_limit'=>0,'max_items'=>3000])->assertSessionHasErrors('staff_limit');
        $this->patch('/admin/plans/'.$y->id,['grace_days'=>14,'staff_limit'=>7,'max_items'=>3000,'price_monthly'=>1,'price_yearly'=>1,'is_active'=>0])->assertSessionHasNoErrors();
        $y->refresh();
        $this->assertSame([1667,19999,true],[(int)$y->price_monthly,(int)$y->price_yearly,$y->is_active]);
    }
    public function test_admin_without_permission_403(): void
    {
        (new PlanSeeder)->run(); $this->limited(['can_manage_shops','can_manage_subscriptions']);
        $m=Plan::where('code','dhiran_monthly')->first();
        $this->get('/admin/plans')->assertForbidden();
        $this->patch('/admin/plans/'.$m->id,['grace_days'=>1,'staff_limit'=>1,'max_items'=>1])->assertForbidden();
        $this->assertSame(7,$m->fresh()->grace_days);
    }
}
