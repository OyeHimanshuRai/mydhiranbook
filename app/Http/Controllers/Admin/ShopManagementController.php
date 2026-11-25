<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\Concerns\ChecksAdminPermission;
use App\Models\Platform\{Plan,PlatformInvoice,ShopSubscription,SubscriptionEvent};
use App\Models\{Shop,User};
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Auth,DB,Log,Mail};
use Illuminate\Validation\ValidationException;

class ShopManagementController
{
    use ChecksAdminPermission;
    public function __construct(private PlatformAuditService $audit) {}
    public function index(Request $r)
    {
        $this->permit($r,'can_manage_shops'); $q=is_string($r->query("q")) ? trim($r->query("q")) : "";
        $shops=Shop::query()->when($q!=='',fn($s)=>$s->where('name','ilike','%'.addcslashes($q,'%_\\').'%'))->orderBy('id')->paginate(20)->withQueryString();
        $shops->getCollection()->each(fn($s)=>$s->setRelation('current',$s->currentSubscription()));
        return view('admin.shops.index',['shops'=>$shops,'q'=>$q]);
    }
    public function show(Request $r,int $shop)
    {
        $this->permit($r,'can_manage_shops'); $shop=Shop::findOrFail($shop);
        return view('admin.shops.show',[
            'shop'=>$shop,'current'=>$shop->currentSubscription(),'staff'=>$shop->currentStaffCount(),
            'owner'=>User::where('shop_id',$shop->id)->whereIn('role_id',\App\Models\Role::withoutTenant()->where('shop_id',$shop->id)->where('name','owner')->select('id'))->orderBy('id')->first() ?? User::where('shop_id',$shop->id)->orderBy('id')->first(),
            'events'=>SubscriptionEvent::where('shop_id',$shop->id)->latest('id')->limit(50)->get(),
            'receipts'=>PlatformInvoice::where('shop_id',$shop->id)->latest('id')->get(),
            'plans'=>Plan::orderBy('price_monthly')->get(),
        ]);
    }

    /** New shops waiting for a decision (owner 2026-11-25: no payment step, an admin approves each shop). */
    public function requests(Request $r)
    {
        $this->permit($r,'can_manage_shops');
        $shops=Shop::where('status','pending')->orderBy('id')->get();
        $owners=User::whereIn('shop_id',$shops->pluck('id'))->get()->groupBy('shop_id');
        return view('admin.requests',['shops'=>$shops,'owners'=>$owners,'plans'=>Plan::where('is_active',true)->orderBy('price_monthly')->get(),'rejected'=>Shop::where('status','rejected')->latest('reviewed_at')->limit(10)->get()]);
    }
    /** Approve: the shop opens, and access is granted with the plan and end date the admin picks (same grant as Shops > Subscription). */
    public function approve(Request $r,int $shop)
    {
        $this->permit($r,'can_manage_shops');
        $data=$r->validate(['plan'=>'required|exists:plans,code','billing_cycle'=>'required|in:monthly,yearly','ends_at'=>'required|date_format:Y-m-d|after_or_equal:today']);
        $plan=Plan::where('code',$data['plan'])->firstOrFail();
        if (!$plan->sellsCycle($data['billing_cycle'])) { throw ValidationException::withMessages(['billing_cycle'=>'This plan does not sell that billing cycle.']); }
        $approved=DB::transaction(function() use($r,$shop,$plan,$data) {
            $shop=Shop::whereKey($shop)->lockForUpdate()->firstOrFail();
            if ($shop->status!=='pending') { return null; }
            $admin=Auth::guard('platform_admin')->user();
            $shop->update(['status'=>'active','reviewed_at'=>now(),'review_note'=>null]);
            $sub=ShopSubscription::create(['shop_id'=>$shop->id,'plan_id'=>$plan->id,'billing_cycle'=>$data['billing_cycle'],'status'=>'active','starts_at'=>today(),'ends_at'=>$data['ends_at'],'grace_ends_at'=>Carbon::parse($data['ends_at'])->addDays($plan->grace_days),'updated_by_admin_id'=>$admin->id,'actor_type'=>'admin']);
            SubscriptionEvent::create(['shop_subscription_id'=>$sub->id,'shop_id'=>$shop->id,'admin_id'=>$admin->id,'event_type'=>'admin_granted','before'=>null,'after'=>$sub->only('plan_id','billing_cycle','status','ends_at','grace_ends_at'),'reason'=>'Shop approved']);
            $this->audit->log($admin,'shop.approved','shop',$shop->id,['status'=>'pending'],['status'=>'active','plan'=>$plan->code,'ends_at'=>$data['ends_at']],'Shop approved',$r);
            return $shop;
        });
        if (!$approved) { return back()->with('error','That request was already decided.'); }
        $this->tellOwner($approved,true);
        return back()->with('success',$approved->name.' approved. The owner can open the dashboard now.');
    }
    public function reject(Request $r,int $shop)
    {
        $this->permit($r,'can_manage_shops');
        $data=$r->validate(['reason'=>'required|string|max:500']);
        $rejected=DB::transaction(function() use($r,$shop,$data) {
            $shop=Shop::whereKey($shop)->lockForUpdate()->firstOrFail();
            if ($shop->status!=='pending') { return null; }
            $shop->update(['status'=>'rejected','reviewed_at'=>now(),'review_note'=>$data['reason']]);
            $this->audit->log(Auth::guard('platform_admin')->user(),'shop.rejected','shop',$shop->id,['status'=>'pending'],['status'=>'rejected'],$data['reason'],$r);
            return $shop;
        });
        if (!$rejected) { return back()->with('error','That request was already decided.'); }
        $this->tellOwner($rejected,false);
        return back()->with('success',$rejected->name.' was turned down.');
    }
    /** The decision email never blocks the decision: a mail error is logged, the admin still sees success. */
    private function tellOwner(Shop $shop,bool $approved): void
    {
        $owner=User::where('shop_id',$shop->id)->orderBy('id')->first();
        if (!$owner?->email) { return; }
        try { Mail::to($owner->email)->send(new \App\Mail\ShopDecisionMail($shop->name,$approved,$shop->review_note)); }
        catch (\Throwable $e) { Log::warning('Shop decision email not sent',['shop_id'=>$shop->id,'error'=>$e->getMessage()]); }
    }
    /** Suspend / re-activate. UseShop blocks every user of a non-active shop. */
    public function update(Request $r,int $shop)
    {
        $this->permit($r,'can_suspend_shop'); $shop=Shop::findOrFail($shop);
        $data=$r->validate(['status'=>'required|in:active,suspended','reason'=>'required|string|max:500']);
        DB::transaction(function() use($r,$shop,$data) {
            $before=$shop->only('status'); $shop->update(['status'=>$data['status']]);
            $this->audit->log(Auth::guard('platform_admin')->user(),'shop.updated','shop',$shop->id,$before,$shop->only('status'),$data['reason'],$r);
        });
        return back()->with('success','Shop '.($data['status']==='active'?'activated':'suspended').'.');
    }
}
