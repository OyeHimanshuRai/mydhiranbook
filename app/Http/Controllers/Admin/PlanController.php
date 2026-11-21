<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\Concerns\ChecksAdminPermission;
use App\Models\Platform\Plan;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Validation\ValidationException;

class PlanController
{
    use ChecksAdminPermission;
    public function __construct(private PlatformAuditService $audit) {}
    public function index(Request $r)
    {
        $this->permit($r,'can_manage_plans'); return view('admin.plans.index',['plans'=>Plan::orderBy('price_monthly')->get()]);
    }
    /** No create/delete: plans are seeded. Existing subscriptions on an inactive plan keep working. */
    public function update(Request $r,int $plan)
    {
        $this->permit($r,'can_manage_plans'); $plan=Plan::findOrFail($plan);
        $data=$r->validate(['price_monthly'=>'required|integer|min:0|max:10000000','price_yearly'=>'nullable|integer|min:0|max:10000000','grace_days'=>'required|integer|min:0|max:90','staff_limit'=>'required|integer|min:1|max:1000','max_items'=>'required|integer|min:1|max:1000000','is_active'=>'required|boolean','reason'=>'nullable|string|max:500']);
        DB::transaction(function() use($r,$plan,$data) {
            if (!$data['is_active'] && !Plan::where('is_active',true)->whereKeyNot($plan->id)->exists()) {
                throw ValidationException::withMessages(['is_active'=>'At least one plan must stay active, or nobody can sign up.']);
            }
            $keys=['price_monthly','price_yearly','grace_days','features','is_active'];
            $before=$plan->only($keys);
            // yearly price only on plans that already sell yearly (a monthly plan must stay monthly-only)
            $plan->update(['price_monthly'=>$data['price_monthly'],'price_yearly'=>$plan->price_yearly===null?null:($data['price_yearly']??$plan->price_yearly),'grace_days'=>$data['grace_days'],'is_active'=>$data['is_active'],'features'=>[...($plan->features??[]),'staff_limit'=>$data['staff_limit'],'max_items'=>$data['max_items']]]);
            $this->audit->log(Auth::guard('platform_admin')->user(),'plan.updated','plan',$plan->id,$before,$plan->fresh()->only($keys),$data['reason']??null,$r);
        });
        return back()->with('success','Plan updated.');
    }
}
