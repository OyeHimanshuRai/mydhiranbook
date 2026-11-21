<?php

namespace App\Http\Controllers;

use App\Models\Dhiran\DhiranSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class DhiranReceiptSettingsController extends Controller
{
    public function edit()
    {
        return view('dhiran.receipt-settings', ['settings' => DhiranSettings::getForShop(auth()->user()->shop_id)]);
    }

    /** The form posts the fields that stay printed (checked switches); everything else listed is stored as hidden. */
    public function update(Request $request)
    {
        $keys = DhiranSettings::receiptKeys();
        $data = $request->validate([
            'show'   => 'nullable|array',
            'show.*' => ['string', Rule::in($keys)],
            'receipt_terms_text' => 'nullable|string|max:5000',
            'closure_certificate_text' => 'nullable|string|max:5000',
        ]);

        DhiranSettings::getForShop(auth()->user()->shop_id)
            ->update(['hidden_receipt_fields' => array_values(array_diff($keys, $data['show'] ?? []))] + Arr::except($data, 'show')); // text fields are saved only when posted

        return redirect()->route('dhiran.settings.receipt.edit')->with('success', 'Receipt settings saved.');
    }
}
