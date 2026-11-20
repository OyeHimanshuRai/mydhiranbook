<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\Concerns\ChecksAdminPermission;
use App\Models\Platform\SubscriptionEvent;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};

/** Captured Razorpay payments that could not be applied (Task 3 evidence rows: subscription_events 'payment.unresolved'). Ids only, never secrets. */
class UnresolvedPaymentController
{
    use ChecksAdminPermission;
    public function __construct(private PlatformAuditService $audit) {}
    public function index(Request $r)
    {
        $this->permit($r,'can_manage_subscriptions');
        return view('admin.billing.unresolved',['events'=>SubscriptionEvent::where('event_type','payment.unresolved')->orderByRaw("(after->>'resolved_at') is null desc")->orderByDesc('id')->paginate(30)]);
    }
    public function resolve(Request $r,int $event)
    {
        $this->permit($r,'can_manage_subscriptions');
        $data=$r->validate(['reason'=>'required|string|max:500']);
        DB::transaction(function() use($r,$event,$data) {
            $e=SubscriptionEvent::where('event_type','payment.unresolved')->lockForUpdate()->findOrFail($event); $admin=Auth::guard('platform_admin')->user();
            $before=$e->after ?? [];
            if (!empty($before['resolved_at'])) { return; }
            $after=array_merge($before,['resolved_at'=>now()->toIso8601String(),'resolved_by_admin_id'=>$admin->id,'resolution_note'=>$data['reason']]);
            $e->update(['after'=>$after,'reason'=>$e->reason.' [RESOLVED by admin: '.$data['reason'].']']);
            $this->audit->log($admin,'payment.unresolved_resolved','subscription_event',$e->id,$before,$after,$data['reason'],$r);
        });
        return back()->with('success','Marked resolved.');
    }
}
