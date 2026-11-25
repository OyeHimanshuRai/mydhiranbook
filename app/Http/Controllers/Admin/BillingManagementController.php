<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\Concerns\ChecksAdminPermission;
use App\Models\Platform\{Plan,ShopSubscription,SubscriptionEvent};
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Validation\ValidationException;

class BillingManagementController
{
    use ChecksAdminPermission;
    public function __construct(private PlatformAuditService $audit) {}
    private const TRACK = ['plan_id','billing_cycle','status','ends_at','grace_ends_at'];

    /** Admin grant/extend: no payment, no receipt (price_paid stays as it was / null). One live subscription per shop. */
    public function updateShopSubscription(Request $r,int $shop)
    {
        $this->permit($r,'can_manage_subscriptions'); $shop=Shop::findOrFail($shop);
        $data=$r->validate(['plan'=>'required|exists:plans,code','billing_cycle'=>'required|in:monthly,yearly','ends_at'=>'required|date_format:Y-m-d','reason'=>'required|string|max:500']);
        $plan=Plan::where('code',$data['plan'])->firstOrFail();
        if (!$plan->sellsCycle($data['billing_cycle'])) { throw ValidationException::withMessages(['billing_cycle'=>'This plan does not sell that billing cycle.']); }
        DB::transaction(function() use($r,$shop,$plan,$data) {
            $admin=Auth::guard('platform_admin')->user();
            $live=ShopSubscription::where('shop_id',$shop->id)->where('status','!=','cancelled')->orderByRaw('ends_at desc nulls last')->orderByDesc('id')->lockForUpdate()->get();
            // the latest-ending row of an early renewer is the QUEUED term: rewriting it would leave the started term on its old plan and dates
            $queued=$live->first(fn($l)=>$l->starts_at && $l->starts_at->gt(today()));
            if ($queued) { throw ValidationException::withMessages(['ends_at'=>'A renewal is already queued (starts '.$queued->starts_at->format('d M Y').'). Terminate it first or wait until it starts.']); }
            $sub=$live->first();
            // extend / plan-switch only: shortening or revoking is terminate (an early renewer has several live rows)
            $floor=collect([today(),$sub?->starts_at,...$live->pluck('ends_at')])->filter()->max();
            if ($data['ends_at'] < $floor->toDateString()) { throw ValidationException::withMessages(['ends_at'=>'To shorten or revoke access, terminate the subscription.']); }
            $before=$sub?->only(self::TRACK);
            $values=['plan_id'=>$plan->id,'billing_cycle'=>$data['billing_cycle'],'status'=>'active','ends_at'=>$data['ends_at'],'grace_ends_at'=>Carbon::parse($data['ends_at'])->addDays($plan->grace_days),'cancelled_at'=>null,'updated_by_admin_id'=>$admin->id,'actor_type'=>'admin'];
            if ($sub) { $sub->update($values); } else { $sub=ShopSubscription::create($values+['shop_id'=>$shop->id,'starts_at'=>today()]); }
            $after=$sub->fresh()->only(self::TRACK);
            SubscriptionEvent::create(['shop_subscription_id'=>$sub->id,'shop_id'=>$shop->id,'admin_id'=>$admin->id,'event_type'=>$before?'admin_updated':'admin_granted','before'=>$before,'after'=>$after,'reason'=>$data['reason']]);
            $this->audit->log($admin,'subscription.updated','shop',$shop->id,$before,$after,$data['reason'],$r);
        });
        return back()->with('success','Subscription updated.');
    }
    public function terminateShopSubscription(Request $r,int $shop)
    {
        $this->permit($r,'can_manage_subscriptions'); $shop=Shop::findOrFail($shop);
        $data=$r->validate(['reason'=>'required|string|max:500']);
        DB::transaction(function() use($r,$shop,$data) {
            $admin=Auth::guard('platform_admin')->user();
            $sub=ShopSubscription::where('shop_id',$shop->id)->where('status','!=','cancelled')->lockForUpdate()->get();
            if ($sub->isEmpty()) { throw ValidationException::withMessages(['reason'=>'This shop has no live subscription.']); }
            foreach ($sub as $s) {
                $before=$s->only(self::TRACK); $s->update(['status'=>'cancelled','cancelled_at'=>now(),'updated_by_admin_id'=>$admin->id,'actor_type'=>'admin']);
                SubscriptionEvent::create(['shop_subscription_id'=>$s->id,'shop_id'=>$shop->id,'admin_id'=>$admin->id,'event_type'=>'admin_terminated','before'=>$before,'after'=>$s->only(self::TRACK),'reason'=>$data['reason']]);
                $this->audit->log($admin,'subscription.terminated','shop',$shop->id,$before,$s->only(self::TRACK),$data['reason'],$r);
            }
        });
        return back()->with('success','Subscription terminated.');
    }
}
