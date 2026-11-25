<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\Concerns\ChecksAdminPermission;
use App\Models\Platform\{Plan,ShopSubscription,SubscriptionEvent};
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
            'plans'=>Plan::orderBy('price_monthly')->get(),
        ]);
    }

    /** New shops waiting for a decision (owner 2026-11-25: no payment step, an admin approves each shop). */
    public function requests(Request $r)
    {
        $this->permit($r,'can_manage_shops');
        $shops=Shop::where('status','pending')->orderBy('id')->get();
        $owners=User::whereIn('shop_id',$shops->pluck('id'))->get()->groupBy('shop_id');
        return view('admin.requests',['shops'=>$shops,'owners'=>$owners,'rejected'=>Shop::where('status','rejected')->latest('reviewed_at')->limit(10)->get()]);
    }
    /** Every approval and every extension is exactly one year (owner 2026-11-25: no plan buying, only the access term is tracked). */
    public const TERM_PLAN = 'dhiran_yearly';

    /** Approve: the shop opens and gets one year of access from today. Needs both the shops and the subscriptions permission. */
    public function approve(Request $r,int $shop)
    {
        $this->permit($r,'can_manage_shops'); $this->permit($r,'can_manage_subscriptions');
        $plan=Plan::where('code',self::TERM_PLAN)->firstOrFail();
        $approved=DB::transaction(function() use($r,$shop,$plan) {
            $shop=Shop::whereKey($shop)->lockForUpdate()->firstOrFail();
            if ($shop->status!=='pending') { return null; }
            $admin=Auth::guard('platform_admin')->user();
            $ends=today()->addYear();
            $shop->update(['status'=>'active','reviewed_at'=>now(),'review_note'=>null]);
            // an admin may have granted a row to the waiting shop already: that row becomes the one year (never two live rows)
            $values=['plan_id'=>$plan->id,'billing_cycle'=>'yearly','status'=>'active','starts_at'=>today(),'ends_at'=>$ends,'grace_ends_at'=>$ends,'cancelled_at'=>null,'updated_by_admin_id'=>$admin->id,'actor_type'=>'admin'];
            $live=ShopSubscription::where('shop_id',$shop->id)->where('status','!=','cancelled')->orderByDesc('id')->lockForUpdate()->get();
            $sub=$live->shift();
            if ($sub) { $sub->update($values); $live->each(fn($l)=>$l->update(['status'=>'cancelled','cancelled_at'=>now(),'updated_by_admin_id'=>$admin->id,'actor_type'=>'admin'])); } else { $sub=ShopSubscription::create($values+['shop_id'=>$shop->id]); }
            SubscriptionEvent::create(['shop_subscription_id'=>$sub->id,'shop_id'=>$shop->id,'admin_id'=>$admin->id,'event_type'=>'admin_granted','before'=>null,'after'=>$sub->only('plan_id','billing_cycle','status','ends_at','grace_ends_at'),'reason'=>'Shop approved: 1 year']);
            $this->audit->log($admin,'shop.approved','shop',$shop->id,['status'=>'pending'],['status'=>'active','ends_at'=>$ends->toDateString()],'Shop approved: 1 year',$r);
            return $shop;
        });
        if (!$approved) { return back()->with('error','That request was already decided.'); }
        $this->tellOwner($approved,true);
        return back()->with('success',$approved->name.' approved for one year. The owner can open the dashboard now.');
    }
    /** Extend by one more year: counted from the end of the current term, or from today when the term already ended. */
    public function extend(Request $r,int $shop)
    {
        $this->permit($r,'can_manage_subscriptions');
        $plan=Plan::where('code',self::TERM_PLAN)->firstOrFail();
        $new=DB::transaction(function() use($r,$shop,$plan) {
            $shop=Shop::whereKey($shop)->lockForUpdate()->firstOrFail();
            if ($shop->status!=='active') { return null; }
            $admin=Auth::guard('platform_admin')->user();
            $live=ShopSubscription::where('shop_id',$shop->id)->where('status','!=','cancelled')->orderByRaw('ends_at desc nulls last')->orderByDesc('id')->lockForUpdate()->first();
            $from=collect([today(),$live?->ends_at])->filter()->max();
            $ends=Carbon::parse($from)->addYear();
            $before=$live?->only('plan_id','billing_cycle','status','ends_at','grace_ends_at');
            $values=['plan_id'=>$plan->id,'billing_cycle'=>'yearly','status'=>'active','ends_at'=>$ends,'grace_ends_at'=>$ends,'cancelled_at'=>null,'updated_by_admin_id'=>$admin->id,'actor_type'=>'admin'];
            if ($live?->starts_at && $live->starts_at->gt(today())) { $values['starts_at']=today(); } // a term that has not begun would still block today
            if ($live) { $live->update($values); $sub=$live; } else { $sub=ShopSubscription::create($values+['shop_id'=>$shop->id,'starts_at'=>today()]); }
            SubscriptionEvent::create(['shop_subscription_id'=>$sub->id,'shop_id'=>$shop->id,'admin_id'=>$admin->id,'event_type'=>$before?'admin_updated':'admin_granted','before'=>$before,'after'=>$sub->fresh()->only('plan_id','billing_cycle','status','ends_at','grace_ends_at'),'reason'=>'Extended by one year']);
            $this->audit->log($admin,'subscription.extended','shop',$shop->id,$before,['ends_at'=>$ends->toDateString()],'Extended by one year',$r);
            return $ends;
        });
        if (!$new) { return back()->with('error','Only an approved shop can be extended.'); }
        return back()->with('success','Access extended until '.$new->format('d M Y').'.');
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
        catch (\Throwable $e) { Log::warning('Shop decision email not sent',['shop_id'=>$shop->id,'error'=>get_class($e)]); }
    }
    /** Suspend / re-activate. UseShop blocks every user of a non-active shop. */
    public function update(Request $r,int $shop)
    {
        $this->permit($r,'can_suspend_shop'); $shop=Shop::findOrFail($shop);
        $data=$r->validate(['status'=>'required|in:active,suspended','reason'=>'required|string|max:500']);
        DB::transaction(function() use($r,$shop,$data) {
            // Only an approved shop can be suspended and only a suspended one re-activated: a waiting or turned-down shop
            // opens through Approve (which also grants the year), never through this switch.
            $shop=Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $from=$data['status']==='suspended' ? 'active' : 'suspended';
            if ($shop->status!==$from) { throw ValidationException::withMessages(['status'=>'This shop is '.$shop->status.'. '.($shop->awaitsApproval() ? 'Approve or reject it on the New shop requests page.' : 'Only an active shop can be suspended and only a suspended one re-activated.')]); }
            $before=$shop->only('status'); $shop->update(['status'=>$data['status']]);
            $this->audit->log(Auth::guard('platform_admin')->user(),'shop.updated','shop',$shop->id,$before,$shop->only('status'),$data['reason'],$r);
        });
        return back()->with('success','Shop '.($data['status']==='active'?'activated':'suspended').'.');
    }
}
