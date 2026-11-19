<?php
namespace App\Http\Controllers\Admin;
use App\Models\Shop;
use App\Models\Platform\PlatformAdmin;
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
        return view('admin.dashboard',['shops'=>DB::table('shops')->leftJoin('subscriptions','shops.id','=','subscriptions.shop_id')->select('shops.*','subscriptions.plan','subscriptions.status as subscription_status','subscriptions.expires_at')->orderBy('shops.id')->paginate(20),'shopCount'=>Shop::count(),'activeCount'=>Shop::where('status','active')->count(),'subscriptionCount'=>DB::table('subscriptions')->where('status','active')->count(),'adminCount'=>PlatformAdmin::where('is_active',true)->count()]);
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
        $data=$r->validate(['plan'=>'required|in:local-trial,dhiran-basic,dhiran-pro','status'=>'required|in:trial,active,expired,cancelled','expires_at'=>'required|date_format:Y-m-d','reason'=>'required|string|max:500']);
        DB::transaction(function() use($r,$shop,$data) {
            $before=DB::table('subscriptions')->where('shop_id',$shop)->first();
            DB::table('subscriptions')->updateOrInsert(['shop_id'=>$shop],['plan'=>$data['plan'],'status'=>$data['status'],'expires_at'=>$data['expires_at'],'created_at'=>$before?->created_at ?? now(),'updated_at'=>now()]);
            $this->audit->log(Auth::guard('platform_admin')->user(),'subscription.updated','shop',$shop,$before?(array)$before:null,array_intersect_key($data,array_flip(['plan','status','expires_at'])),$data['reason'],$r);
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
