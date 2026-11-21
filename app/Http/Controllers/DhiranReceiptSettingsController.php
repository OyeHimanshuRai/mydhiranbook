<?php

namespace App\Http\Controllers;

use App\Models\Dhiran\DhiranSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DhiranReceiptSettingsController extends Controller
{
    /** The form posts the fields that stay printed (checked switches); everything else listed is stored as hidden. */
    public function update(Request $request)
    {
        $keys = DhiranSettings::receiptKeys();
        $data = $request->validate([
            'show'   => 'nullable|array',
            'show.*' => ['string', Rule::in($keys)],
        ]);

        DhiranSettings::getForShop(auth()->user()->shop_id)
            ->update(['hidden_receipt_fields' => array_values(array_diff($keys, $data['show'] ?? []))]);

        return redirect()->to(route('dhiran.settings') . '#receipt')->with('success', 'Receipt fields updated.');
    }
}
