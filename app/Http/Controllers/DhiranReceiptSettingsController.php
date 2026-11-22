<?php

namespace App\Http\Controllers;

use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class DhiranReceiptSettingsController extends Controller
{
    /** One plain-words line per switch ("receipt.key"), from what the receipt views really print. */
    private const HINTS = [
        'pledge.shop_name' => 'Your shop name at the top', 'pledge.shop_address' => 'Your shop address under the name',
        'pledge.shop_phone' => 'Your shop phone number', 'pledge.title' => 'The words "Pledge Receipt / Girvi Parchi"',
        'pledge.loan_date' => 'The day the loan was given', 'pledge.principal_amount' => 'The loan amount',
        'pledge.interest_rate' => 'Percent per month, flat or compound', 'pledge.tenure' => 'How many months the loan runs',
        'pledge.maturity_date' => 'The day the loan falls due',
        'pledge.customer_name' => "The borrower's name", 'pledge.customer_mobile' => "The borrower's phone number",
        'pledge.kyc_aadhaar' => 'Printed only if the loan has one', 'pledge.kyc_pan' => 'Printed only if the loan has one',
        'pledge.item_description' => 'What the item is, one line per item', 'pledge.item_huid' => 'The hallmark ID, small, under the description',
        'pledge.item_metal' => 'Gold or silver', 'pledge.item_gross' => 'Weight with stones and other parts',
        'pledge.item_net' => 'Weight of the metal alone', 'pledge.item_purity' => 'Carat or fineness',
        'pledge.item_fine' => 'Pure metal in the item', 'pledge.item_value' => 'Value of each item',
        'pledge.item_totals' => 'A last row adding up the weights and value',
        'pledge.summary_market_value' => 'All items together at today\'s rate', 'pledge.summary_ltv' => 'Loan as a percent of that value',
        'pledge.summary_principal' => 'The amount handed to the borrower', 'pledge.summary_fee' => 'Printed only if a fee was charged',
        'pledge.terms' => 'The terms you write below', 'pledge.signatures' => 'Two lines to sign: borrower and shop',
        'pledge.footer_note' => '"Computer-generated" line with the date',
        'payment.shop_name' => 'Your shop name at the top', 'payment.shop_address' => 'Your shop address under the name',
        'payment.shop_contact' => 'Shop phone and email, when saved', 'payment.title' => 'The words "Payment Receipt"',
        'payment.receipt_no' => 'The number of this receipt', 'payment.payment_date' => 'When the money was received',
        'payment.method' => 'Cash, UPI, bank and so on',
        'payment.customer_name' => 'Who paid', 'payment.customer_mobile' => "The payer's phone number", 'payment.customer_address' => "The payer's address",
        'payment.principal_component' => 'How much went to the loan amount', 'payment.interest_component' => 'How much went to interest',
        'payment.penalty_component' => 'How much went to penalty', 'payment.fee_component' => 'How much went to fees',
        'payment.amount_paid' => 'The total received',
        'payment.balance_principal' => 'Loan amount still to pay', 'payment.balance_interest' => 'Interest still to pay',
        'payment.balance_penalty' => 'Penalty still to pay', 'payment.balance_total' => 'Everything still to pay',
        'payment.signatures' => 'A line for the signature',
    ];

    public function edit()
    {
        $settings = DhiranSettings::getForShop(auth()->user()->shop_id);
        $keys = DhiranSettings::receiptKeys();

        return view('dhiran.receipt-settings', [
            'settings' => $settings,
            'hints' => self::HINTS,
            // A refused save (text too long) comes back with what was posted: show those switches, not the stored ones.
            'printed' => session()->hasOldInput() ? (array) old('show', []) : array_values(array_diff($keys, $settings->hidden_receipt_fields ?? [])),
            'sampleLoan' => DhiranLoan::query()->latest('id')->first(), // BelongsToShop scopes it to this shop
        ]);
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
