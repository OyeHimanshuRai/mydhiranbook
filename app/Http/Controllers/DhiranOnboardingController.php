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
        return view('dhiran.onboarding.create', ['user' => $user, 'ref' => session('referral_ref')]);
    }

    public function store(Request $request, TenantRoleService $roles)
    {
        $auth = Auth::user();
        if ($auth->shop_id) { return redirect()->route('dhiran.pending'); }
        if (is_string($request->input('phone'))) { $request->merge(['phone' => Mobile::normalize($request->input('phone')) ?? $request->input('phone')]); }
        $data = $request->validate([
            'name' => 'required|string|max:150', 'phone' => ['required', 'string', new IndianMobileRule()],
            'address' => 'required|string|max:500', 'loan_number_prefix' => 'nullable|string|max:10|alpha_dash',
            'referred_by' => 'nullable|string|max:80',
        ]);

        DB::transaction(function () use ($auth, $data, $roles) {
            $user = User::whereKey($auth->id)->lockForUpdate()->firstOrFail();
            if ($user->shop_id) { return false; }
            // pending: the shop exists but nothing opens until an admin approves it (UseShop sends everyone to the waiting page)
            $shop = Shop::create(['name' => $data['name'], 'phone' => $data['phone'], 'address' => $data['address'], 'status' => 'pending']);
            $this->rememberReferrer($shop, trim((string) ($data['referred_by'] ?? '')));
            TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true] + (($data['loan_number_prefix'] ?? '') !== '' ? ['loan_number_prefix' => strtoupper($data['loan_number_prefix'])] : [])));
            $owner = $roles->ensureDefaultsForShop($shop->id)['owner'];
            $user->forceFill(['shop_id' => $shop->id, 'role_id' => $owner->id])->save();
            return true;
        });
        session()->forget('referral_ref');
        return redirect()->route('dhiran.pending');
    }

    /** Who told this shop about us: a matching shop code (never the shop's own) links that shop, anything else is kept as the typed text. */
    private function rememberReferrer(Shop $shop, string $ref): void
    {
        if ($ref === '' || strcasecmp($ref, (string) $shop->referral_code) === 0) { return; }
        $referrer = Shop::cleanReferralCode($ref) ? Shop::where('referral_code', strtoupper($ref))->whereKeyNot($shop->id)->value('id') : null;
        $shop->update($referrer ? ['referred_by_shop_id' => $referrer] : ['referred_by_text' => mb_substr($ref, 0, 80)]);
    }

    /** The waiting page: shown until an admin approves (then straight to the dashboard) or turns the request down. */
    public function pending()
    {
        $user = Auth::user();
        if (!$user->shop_id) { return redirect()->route('dhiran.onboarding'); }
        $shop = $user->shop;
        if ($shop->status === 'active') { return redirect('/')->with('success', 'Your shop is approved. Welcome to MyDhiranBook!'); }
        if (!$user->is_active || $shop->status === 'suspended') { return redirect()->route('dhiran.blocked'); }
        abort_unless($shop->awaitsApproval(), 403);
        $prefix = $shop->status === 'rejected' ? (string) (TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->loan_number_prefix) ?? '') : '';
        return view('dhiran.onboarding.pending', ['shop' => $shop, 'rejected' => $shop->status === 'rejected', 'prefix' => $prefix]);
    }

    /** A rejected shop's owner fixes the details and sends them again: the shop waits in the admin's requests once more. */
    public function resubmit(Request $request)
    {
        $auth = Auth::user();
        abort_unless($auth->shop_id && $auth->is_active && $auth->isShopOwner(), 403);
        if (is_string($request->input('phone'))) { $request->merge(['phone' => Mobile::normalize($request->input('phone')) ?? $request->input('phone')]); }
        $data = $request->validate([
            'name' => 'required|string|max:150', 'phone' => ['required', 'string', new IndianMobileRule()],
            'address' => 'required|string|max:500', 'loan_number_prefix' => 'nullable|string|max:10|alpha_dash',
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
            if (($data['loan_number_prefix'] ?? '') !== '') { TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['loan_number_prefix' => strtoupper($data['loan_number_prefix'])])); }
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
        if (!$user->is_active || $shop->status !== 'active') { return redirect()->route('dhiran.blocked'); }
        if ($shop->hasEdition('dhiran')) { return redirect('/'); }
        return view('dhiran.onboarding.ended', ['shop' => $shop, 'endedOn' => $shop->accessUntil(true), 'owner' => $user->isShopOwner()]);
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
