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
        $prefix = $shop->status === 'rejected' ? (string) (TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->loan_number_prefix) ?? '') : '';
        return view('dhiran.onboarding.pending', ['shop' => $shop, 'rejected' => $shop->status === 'rejected', 'prefix' => $prefix]);
    }

    /** A rejected shop's owner fixes the details and sends them again: the shop waits in the admin's requests once more. */
    public function resubmit(Request $request)
    {
        $auth = Auth::user();
        abort_unless($auth->shop_id && $auth->isShopOwner(), 403);
        $request->merge(['phone' => Mobile::normalize($request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'name' => 'required|string|max:150', 'phone' => ['required', new IndianMobileRule()],
            'address' => 'required|string|max:500', 'loan_number_prefix' => 'nullable|string|max:12|alpha_dash',
        ]);
        $sent = DB::transaction(function () use ($auth, $data, $request) {
            $shop = Shop::whereKey($auth->shop_id)->lockForUpdate()->firstOrFail();
            if ($shop->status !== 'rejected') { return false; }
            $before = $shop->only('name', 'phone', 'address', 'status');
            $shop->update([
                'name' => $data['name'], 'phone' => $data['phone'], 'address' => $data['address'], 'status' => 'pending',
                'previous_review_note' => $shop->review_note, 'review_note' => null, 'reviewed_at' => null,
                'submissions' => $shop->submissions + 1, 'resubmitted_at' => now(),
            ]);
            if (($data['loan_number_prefix'] ?? '') !== '') { TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['loan_number_prefix' => $data['loan_number_prefix']])); }
            app(\App\Services\PlatformAuditService::class)->log(null, 'shop.resubmitted', 'shop', $shop->id, $before, $shop->only('name', 'phone', 'address', 'status') + ['submissions' => $shop->submissions], 'Owner fixed the details and sent them again', $request);
            return true;
        });
        return redirect()->route('dhiran.pending')->with($sent ? 'success' : 'error', $sent ? 'Sent again. We will check it and email you.' : 'Your shop is not waiting for changes.');
    }

    /** Shown to every user of a shop whose one-year access has ended, until an admin extends it. */
    public function ended()
    {
        $user = Auth::user();
        if (!$user->shop_id) { return redirect()->route('dhiran.onboarding'); }
        $shop = $user->shop;
        if ($shop->awaitsApproval()) { return redirect()->route('dhiran.pending'); }
        abort_unless($shop->status === 'active', 403);
        if ($shop->hasEdition('dhiran')) { return redirect('/'); }
        return view('dhiran.onboarding.ended', ['shop' => $shop, 'endedOn' => $shop->subscriptions()->where('status', '!=', 'cancelled')->max('ends_at'), 'owner' => $user->isShopOwner()]);
    }

    /** Why this person cannot use the software: their own account was switched off, or the whole shop was suspended. */
    public function blocked()
    {
        $user = Auth::user();
        $shop = $user->shop;
        if ($user->is_active && (!$shop || $shop->status === 'active')) { return redirect('/'); }
        if ($user->is_active && $shop->awaitsApproval()) { return redirect()->route('dhiran.pending'); }
        return view('dhiran.onboarding.blocked', ['shop' => $shop, 'ownAccount' => !$user->is_active]);
    }
}
