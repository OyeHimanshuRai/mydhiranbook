<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\Concerns\ChecksAdminPermission;
use App\Models\Platform\Plan;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};

class PlanController
{
    use ChecksAdminPermission;
    public function __construct(private PlatformAuditService $audit) {}
    public function index(Request $r)
    {
        $this->permit($r,'can_manage_plans'); return view('admin.plans.index',['plans'=>Plan::orderBy('id')->get()]);
    }
    /** No create/delete: plans are seeded. Only the limits are editable (access is granted by an admin, nothing is sold). */
    public function update(Request $r,int $plan)
    {
        $this->permit($r,'can_manage_plans'); $plan=Plan::findOrFail($plan);
        $data=$r->validate(['grace_days'=>'required|integer|min:0|max:90','staff_limit'=>'required|integer|min:1|max:1000','max_items'=>'required|integer|min:1|max:1000000','reason'=>'nullable|string|max:500']);
        DB::transaction(function() use($r,$plan,$data) {
            $keys=['grace_days','features'];
            $before=$plan->only($keys);
            $plan->update(['grace_days'=>$data['grace_days'],'features'=>[...($plan->features??[]),'staff_limit'=>$data['staff_limit'],'max_items'=>$data['max_items']]]);
            $this->audit->log(Auth::guard('platform_admin')->user(),'plan.updated','plan',$plan->id,$before,$plan->fresh()->only($keys),$data['reason']??null,$r);
        });
        return back()->with('success','Plan updated.');
    }
}
