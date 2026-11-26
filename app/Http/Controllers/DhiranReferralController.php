<?php

namespace App\Http\Controllers;

use App\Models\Shop;

/** "Refer a jeweller": the shop's own share link and the shops that came through it (name, date, state only). */
class DhiranReferralController extends Controller
{
    public function index()
    {
        $shop = Shop::findOrFail(auth()->user()->shop_id);
        // only these columns leave the database: a referred shop's phone, address and owner are never read here
        $referred = $shop->referrals()->orderByDesc('id')->get(['id', 'name', 'status', 'created_at']);
        return view('dhiran.referrals', [
            'code' => $shop->referral_code,
            'link' => url('/register?ref='.$shop->referral_code),
            'referred' => $referred,
            'approved' => $shop->approvedReferralCount(),
        ]);
    }
}
