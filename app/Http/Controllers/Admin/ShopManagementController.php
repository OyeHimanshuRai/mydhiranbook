<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\Concerns\ChecksAdminPermission;
use App\Models\Platform\{Plan,PlatformInvoice,SubscriptionEvent};
use App\Models\{Shop,User};
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};

class ShopManagementController
{
    use ChecksAdminPermission;
    public function __construct(private PlatformAuditService $audit) {}
    public function index(Request $r)
    {
        $this->permit($r,'can_manage_shops'); $q=trim((string)$r->query('q'));
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
