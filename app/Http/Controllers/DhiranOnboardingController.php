<?php
namespace App\Http\Controllers;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Rules\IndianMobileRule;
use App\Services\TenantRoleService;
use App\Support\Mobile;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Pay-first onboarding: plans -> payment (SubscriptionController) -> create business. */
class DhiranOnboardingController extends Controller
{
    public function plans()
    {
        $user = Auth::user();
        if ($user->shop_id) { return redirect('/'); }
        if ($user->paidShoplessSubscriptions()->exists()) { return redirect()->route('dhiran.onboarding'); }
        return view('dhiran.onboarding.plans', ['plans' => Plan::where('is_active', true)->orderBy('price_monthly')->get()]);
    }

    public function create()
    {
        $user = Auth::user();
        if ($user->shop_id) { return redirect('/'); }
        if (!$user->paidShoplessSubscriptions()->exists()) { return redirect()->route('dhiran.plans')->with('error', 'Please choose and pay for a plan first.'); }
        return view('dhiran.onboarding.create', ['user' => $user]);
    }

    public function store(Request $request, TenantRoleService $roles)
    {
        $auth = Auth::user();
        if ($auth->shop_id) { return redirect('/')->with('error', 'Your business is already set up.'); }
        if (!$auth->paidShoplessSubscriptions()->exists()) { return redirect()->route('dhiran.plans')->with('error', 'Please complete payment before creating your business.'); }
        $request->merge(['phone' => Mobile::normalize($request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'name' => 'required|string|max:150', 'phone' => ['required', new IndianMobileRule()],
            'address' => 'required|string|max:500', 'loan_number_prefix' => 'nullable|string|max:12|alpha_dash',
        ]);

        $created = DB::transaction(function () use ($auth, $data, $roles) {
            $user = User::whereKey($auth->id)->lockForUpdate()->firstOrFail();
            if ($user->shop_id || !$user->paidShoplessSubscriptions()->exists()) { return false; }
            $shop = Shop::create(['name' => $data['name'], 'phone' => $data['phone'], 'address' => $data['address'], 'status' => 'active']);
            TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true] + (($data['loan_number_prefix'] ?? '') !== '' ? ['loan_number_prefix' => $data['loan_number_prefix']] : [])));
            $owner = $roles->ensureDefaultsForShop($shop->id)['owner'];
            $user->forceFill(['shop_id' => $shop->id, 'role_id' => $owner->id, 'role' => 'owner'])->save();
            ShopSubscription::where('user_id', $user->id)->whereNull('shop_id')->where('status', '!=', 'cancelled')->update(['shop_id' => $shop->id]);
            return true;
        });
        if (!$created) { return redirect('/')->with('error', 'Your business could not be created.'); }
        return redirect('/')->with('success', 'Welcome to Dhiran! Your business is ready.');
    }
}
