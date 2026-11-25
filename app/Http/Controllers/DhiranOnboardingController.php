<?php
namespace App\Http\Controllers;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Shop;
use App\Models\User;
use App\Rules\IndianMobileRule;
use App\Services\TenantRoleService;
use App\Support\Mobile;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Onboarding: verify email -> business form -> wait for an admin to approve the shop (no payment step). */
class DhiranOnboardingController extends Controller
{
    /** The old plans step is gone: old links land on the business form. */
    public function plans()
    {
        return redirect()->route('dhiran.onboarding');
    }

    public function create()
    {
        $user = Auth::user();
        if ($user->shop_id) { return redirect()->route('dhiran.pending'); }
        return view('dhiran.onboarding.create', ['user' => $user]);
    }

    public function store(Request $request, TenantRoleService $roles)
    {
        $auth = Auth::user();
        if ($auth->shop_id) { return redirect()->route('dhiran.pending'); }
        $request->merge(['phone' => Mobile::normalize($request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'name' => 'required|string|max:150', 'phone' => ['required', new IndianMobileRule()],
            'address' => 'required|string|max:500', 'loan_number_prefix' => 'nullable|string|max:12|alpha_dash',
        ]);

        $created = DB::transaction(function () use ($auth, $data, $roles) {
            $user = User::whereKey($auth->id)->lockForUpdate()->firstOrFail();
            if ($user->shop_id) { return false; }
            // pending: the shop exists but nothing opens until an admin approves it (UseShop sends everyone to the waiting page)
            $shop = Shop::create(['name' => $data['name'], 'phone' => $data['phone'], 'address' => $data['address'], 'status' => 'pending']);
            TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true] + (($data['loan_number_prefix'] ?? '') !== '' ? ['loan_number_prefix' => $data['loan_number_prefix']] : [])));
            $owner = $roles->ensureDefaultsForShop($shop->id)['owner'];
            $user->forceFill(['shop_id' => $shop->id, 'role_id' => $owner->id])->save();
            return true;
        });
        if (!$created) { return redirect()->route('dhiran.pending'); }
        return redirect()->route('dhiran.pending');
    }

    /** The waiting page: shown until an admin approves (then straight to the dashboard) or turns the request down. */
    public function pending()
    {
        $user = Auth::user();
        if (!$user->shop_id) { return redirect()->route('dhiran.onboarding'); }
        $shop = $user->shop;
        if ($shop->status === 'active') { return redirect('/')->with('success', 'Your shop is approved. Welcome to MyDhiranBook!'); }
        abort_unless($shop->awaitsApproval(), 403);
        return view('dhiran.onboarding.pending', ['shop' => $shop, 'rejected' => $shop->status === 'rejected']);
    }
}
