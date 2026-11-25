<?php
namespace App\Http\Controllers\Admin;
use App\Models\Shop;
use App\Http\Controllers\Admin\Concerns\ChecksAdminPermission;
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
    use ChecksAdminPermission;
    public function __construct(private PlatformAuditService $audit) {}
    public function dashboard()
    {
        $entitled=DB::table('shop_subscriptions')->whereNotNull('shop_id')->whereIn('status',['active','grace'])->whereRaw('greatest(ends_at,grace_ends_at) >= ?',[today()->toDateString()]);
        return view('admin.dashboard',['stats'=>[
            'shops'=>Shop::count(),'active'=>Shop::where('status','active')->count(),'suspended'=>Shop::where('status','suspended')->count(),
            'entitled'=>$entitled->distinct()->count('shop_id'),
            'pending'=>Shop::where('status','pending')->count(),
        ],'adminCount'=>PlatformAdmin::where('is_active',true)->count(),'ending'=>$this->ending()]);
    }
    /** Approved shops whose access term ends within 30 days, has ended, or was never granted: the admin extends them from here. */
    private function ending()
    {
        $ends="(select max(s.ends_at) from shop_subscriptions s where s.shop_id=shops.id and s.status!='cancelled')";
        return Shop::where('status','active')->selectRaw("shops.*, $ends as access_ends")
            ->whereRaw("coalesce($ends,'1900-01-01') <= ?",[today()->addDays(30)->toDateString()])
            ->orderByRaw('access_ends asc nulls first')->limit(50)->get();
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
