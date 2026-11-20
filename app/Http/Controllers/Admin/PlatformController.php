<?php
namespace App\Http\Controllers\Admin;
use App\Models\Shop;
use App\Models\Platform\{Plan,PlatformAdmin,ShopSubscription,SubscriptionEvent};
use Illuminate\Support\Carbon;
use App\Models\Platform\PlatformAuditLog;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
class PlatformController
{
    public function __construct(private PlatformAuditService $audit) {}
    private function permit(Request $r,string $permission): void
    {
        if (!Auth::guard('platform_admin')->user()->hasPermission($permission)) {
            $this->audit->log(Auth::guard('platform_admin')->user(),'admin.permission_denied',reason:$permission,request:$r); abort(403);
        }
    }
    public function dashboard()
    {
        $current=DB::table('shop_subscriptions')->whereNotNull('shop_id')->where('status','!=','cancelled')->selectRaw('distinct on (shop_id) shop_id,plan_id,status,ends_at')->orderBy('shop_id')->orderByRaw('ends_at desc nulls last')->orderByDesc('id');
        return view('admin.dashboard',['shops'=>DB::table('shops')->leftJoinSub($current,'cs','shops.id','=','cs.shop_id')->leftJoin('plans','plans.id','=','cs.plan_id')->select('shops.*','plans.code as plan','cs.status as subscription_status','cs.ends_at as expires_at')->orderBy('shops.id')->paginate(20),'shopCount'=>Shop::count(),'activeCount'=>Shop::where('status','active')->count(),'subscriptionCount'=>DB::table('shop_subscriptions')->where('status','active')->count(),'adminCount'=>PlatformAdmin::where('is_active',true)->count()]);
    }
    public function updateShop(Request $r,int $shop)
    {
        $this->permit($r,'can_manage_shops'); $shop=Shop::findOrFail($shop);
        $data=$r->validate(['name'=>'required|string|max:150','status'=>'required|in:active,suspended','reason'=>'required|string|max:500']);
        DB::transaction(function() use($r,$shop,$data) {
            $before=$shop->only('name','status'); $shop->update(['name'=>$data['name'],'status'=>$data['status']]);
            $this->audit->log(Auth::guard('platform_admin')->user(),'shop.updated','shop',$shop->id,$before,$shop->only('name','status'),$data['reason'],$r);
        });
        return back()->with('success','Shop updated.');
    }
    public function subscription(Request $r,int $shop)
    {
        $this->permit($r,'can_manage_subscriptions'); Shop::findOrFail($shop);
        $data=$r->validate(['plan'=>'required|exists:plans,code','status'=>'required|in:'.implode(',',ShopSubscription::STATUSES),'expires_at'=>'required|date_format:Y-m-d','reason'=>'required|string|max:500']);
        DB::transaction(function() use($r,$shop,$data) {
            $admin=Auth::guard('platform_admin')->user(); $plan=Plan::where('code',$data['plan'])->firstOrFail();
            $sub=Shop::findOrFail($shop)->currentSubscription(); $before=$sub?->only('plan_id','status','ends_at');
            $values=['plan_id'=>$plan->id,'status'=>$data['status'],'ends_at'=>$data['expires_at'],'grace_ends_at'=>Carbon::parse($data['expires_at'])->addDays($plan->grace_days),'cancelled_at'=>$data['status']==='cancelled'?now():null,'updated_by_admin_id'=>$admin->id,'actor_type'=>'admin'];
            if ($sub) { $sub->update($values); $type='admin_updated'; }
            else { $cycle=$plan->code==='dhiran_yearly'?'yearly':'monthly'; $sub=ShopSubscription::create($values+['shop_id'=>$shop,'billing_cycle'=>$cycle,'starts_at'=>today()]); $type='admin_granted'; }
            SubscriptionEvent::create(['shop_subscription_id'=>$sub->id,'shop_id'=>$shop,'admin_id'=>$admin->id,'event_type'=>$type,'before'=>$before,'after'=>$sub->only('plan_id','status','ends_at'),'reason'=>$data['reason']]);
            $this->audit->log($admin,'subscription.updated','shop',$shop,$before,array_intersect_key($data,array_flip(['plan','status','expires_at'])),$data['reason'],$r);
        }); return back()->with('success','Subscription updated.');
    }
    public function audit(Request $r)
    {
        $this->permit($r,'can_view_audit_logs'); return view('admin.audit',['logs'=>PlatformAuditLog::with('actor')->latest('id')->paginate(30)]);
    }
    public function admins(Request $r)
    {
        $this->permit($r,'can_manage_admins'); return view('admin.accounts',['admins'=>PlatformAdmin::orderBy('id')->get()]);
    }
    public function storeAdmin(Request $r)
    {
        $this->permit($r,'can_manage_admins');
        $data=$r->validate(['name'=>'required|string|max:120','email'=>'required|email|lowercase|unique:platform_admins,email','password'=>['required',Password::min(12)],'permissions'=>'nullable|array','permissions.*'=>['string',Rule::in(PlatformAdmin::PERMISSIONS)]]);
        DB::transaction(function() use($r,$data) {
            $admin=PlatformAdmin::create([...$data,'role'=>'support','is_active'=>true,'two_factor_enabled'=>false]);
            $this->audit->log(Auth::guard('platform_admin')->user(),'admin.created','platform_admin',$admin->id,after:$admin->only('name','email','permissions'),request:$r);
        }); return back()->with('success','Admin created. MFA enrollment is required at first sign in.');
    }
    public function updateAdmin(Request $r,int $admin)
    {
        $this->permit($r,'can_manage_admins'); $admin=PlatformAdmin::findOrFail($admin);
        abort_if($admin->isSuperAdmin(),403,'Super admin changes require local administration.');
        $data=$r->validate(['is_active'=>'required|boolean','permissions'=>'nullable|array','permissions.*'=>['string',Rule::in(PlatformAdmin::PERMISSIONS)]]);
        DB::transaction(function() use($r,$admin,$data) {
            $before=$admin->only('is_active','permissions'); $admin->update([...$data,'permissions'=>$data['permissions']??[]]);
            $this->audit->log(Auth::guard('platform_admin')->user(),'admin.access_updated','platform_admin',$admin->id,$before,$admin->only('is_active','permissions'),request:$r);
        }); return back()->with('success','Admin access updated.');
    }
}
