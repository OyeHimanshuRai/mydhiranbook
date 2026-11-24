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
        'pledge.summary_principal' => 'The loan amount written on the ticket', 'pledge.summary_fee' => 'Printed only if a fee was charged',
        'pledge.summary_advance' => 'Printed only if the first months\' interest was taken now', 'pledge.summary_cash' => 'Loan less advance interest and fee; printed only if advance interest was taken',
        'pledge.terms' => 'The terms you write below', 'pledge.signatures' => 'Two lines to sign: borrower and shop',
        'pledge.footer_note' => '"Computer-generated" line with the date',
        'payment.shop_name' => 'Your shop name at the top', 'payment.shop_address' => 'Your shop address under the name',
        'payment.shop_contact' => 'Shop phone and email, when saved', 'payment.title' => 'The words "Payment Receipt"',
        'payment.receipt_no' => 'The number of this receipt', 'payment.payment_for' => 'Interest, repayment, installment and so on', 'payment.payment_date' => 'When the money was received',
        'payment.method' => 'Cash, UPI, bank and so on',
        'payment.customer_name' => 'Who paid', 'payment.customer_mobile' => "The payer's phone number", 'payment.customer_address' => "The payer's address",
        'payment.principal_component' => 'How much went to the loan amount', 'payment.interest_component' => 'How much went to interest',
        'payment.penalty_component' => 'How much went to penalty', 'payment.fee_component' => 'How much went to fees',
        'payment.amount_paid' => 'The total received',
        'payment.balance_principal' => 'Loan amount still to pay', 'payment.balance_interest' => 'Interest still to pay',
        'payment.balance_penalty' => 'Penalty still to pay', 'payment.balance_total' => 'Everything still to pay',
        'payment.signatures' => 'A line for the signature',
        'closure.shop_name' => 'Your shop name at the top', 'closure.shop_address' => 'Your shop address under the name', 'closure.shop_contact' => 'Shop phone and email, when saved',
        'closure.shop_gstin' => 'Printed only if the shop has one', 'closure.title' => 'The words "Closure Certificate"', 'closure.generated_on' => 'The day the certificate was printed',
        'closure.lender' => 'A box naming the shop', 'closure.borrower_name' => "The borrower's name", 'closure.borrower_contact' => "The borrower's phone and address",
        'closure.loan_date' => 'The day the loan was given', 'closure.principal' => 'The loan amount', 'closure.closed_on' => 'The day the loan was closed',
        'closure.statement' => '"This is to certify that the loan has been settled..."', 'closure.items_table' => 'Each item that was returned, with its weights',
        'closure.amount_principal' => 'The loan amount', 'closure.amount_interest' => 'Interest paid over the loan', 'closure.amount_penalty' => 'Penalty paid, if any',
        'closure.amount_fee' => 'The processing fee, if any', 'closure.amount_total' => 'Everything paid in all',
        'closure.footer_text' => 'The closing text you write below', 'closure.closure_notes' => 'Printed only if notes were written', 'closure.signatures' => 'Borrower, date and shop signature lines',
        'notice.shop_name' => 'Your shop name at the top', 'notice.shop_address' => 'Your shop address under the name', 'notice.shop_contact' => 'Shop phone and email, when saved',
        'notice.shop_gstin' => 'Printed only if the shop has one', 'notice.title' => 'The words "Forfeiture Notice"', 'notice.notice_date' => 'The day the notice was issued',
        'notice.from' => 'A box naming the shop', 'notice.to_name' => "The borrower's name", 'notice.to_contact' => "The borrower's phone and address",
        'notice.loan_date' => 'The day the loan was given', 'notice.maturity_date' => 'The day the loan fell due', 'notice.days_overdue' => 'How late the loan was when the notice was issued',
        'notice.principal_due' => 'Loan amount still to pay', 'notice.interest_due' => 'Interest still to pay', 'notice.penalty_due' => 'Penalty still to pay', 'notice.total_due' => 'Everything still to pay',
        'notice.letter' => 'The letter to the borrower (you can edit its wording on the notice)', 'notice.warning' => 'The warning with the last date', 'notice.signatures' => 'Date and shop signature lines',
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

    /**
     * A sample of one document, built from made-up records that are never saved, so a shop with no loans yet can see what it prints.
     * It is the real view with the owner's saved switches applied; the sample banner and mark say it is not real.
     */
    public function sample(string $doc)
    {
        $user = auth()->user();
        $shop = $user->shop;
        $settings = \App\Models\Dhiran\DhiranSettings::getForShop((int) $shop->id);
        $today = now();
        $number = ($settings->loan_number_prefix ?: 'DH-') . str_pad('1', max(1, (int) ($settings->loan_number_digits ?: 6)), '0', STR_PAD_LEFT);

        $customer = new \App\Models\Customer(['first_name' => 'Sample', 'last_name' => 'Borrower', 'mobile' => '9876543210', 'address' => '12 Sample Street, Pune']);
        $overdue = $doc === 'notice';
        $loan = (new \App\Models\Dhiran\DhiranLoan())->forceFill([
            'shop_id' => $shop->id, 'loan_number' => $number, 'status' => 'active', 'interest_type' => 'flat', 'interest_rate_monthly' => 2, 'tenure_months' => 6,
            'loan_date' => $overdue ? $today->copy()->subMonths(7)->toDateString() : $today->toDateString(),
            'maturity_date' => $overdue ? $today->copy()->subMonth()->toDateString() : $today->copy()->addMonths(6)->toDateString(),
            'principal_amount' => 50000, 'processing_fee' => 250, 'ltv_ratio_applied' => 75, 'grace_period_days' => (int) ($settings->grace_period_days ?: 5),
            'outstanding_principal' => 50000, 'outstanding_interest' => $overdue ? 2166.67 : 0, 'outstanding_penalty' => $overdue ? 250 : 0, 'advance_interest_months' => 0,
            'kyc_pan' => 'ABCDE1234F', 'kyc_aadhaar' => 'XXXX XXXX 1234', 'terms_text' => $settings->receipt_terms_text ?: 'Interest is charged per month. Items not redeemed by the maturity date and grace period may be forfeited and sold.',
            'forfeiture_notice_sent_at' => $overdue ? $today->copy()->subDay() : null, 'forfeiture_deadline' => $overdue ? $today->copy()->addDays(29)->toDateString() : null,
        ]);
        if ($doc === 'closure') { // a loan settled in full: everything paid, items back with the borrower
            $loan->forceFill(['status' => 'closed', 'closed_at' => $today, 'outstanding_principal' => 0, 'total_principal_collected' => 50000, 'total_interest_collected' => 6000, 'total_penalty_collected' => 0,
                'loan_date' => $today->copy()->subMonths(6)->toDateString(), 'maturity_date' => $today->toDateString(), 'closure_notes' => 'Settled in full; all items returned.']);
        }
        $loan->id = 0; // not a real record: only so links on the page can be built
        $loan->setRelation('customer', $customer);
        $loan->setRelation('items', collect([
            (new \App\Models\Dhiran\DhiranLoanItem())->forceFill(['description' => 'Gold chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 12.5, 'stone_weight' => 0.5, 'quantity' => 1, 'net_metal_weight' => 12, 'fine_weight' => 11, 'market_value' => 66000, 'loan_value' => 49500, 'huid' => 'AB12CD', 'status' => 'pledged']),
            (new \App\Models\Dhiran\DhiranLoanItem())->forceFill(['description' => 'Silver anklet', 'metal_type' => 'silver', 'purity' => 925, 'gross_weight' => 40, 'stone_weight' => 0, 'quantity' => 1, 'net_metal_weight' => 40, 'fine_weight' => 37, 'market_value' => 3400, 'loan_value' => 2550, 'huid' => null, 'status' => 'pledged']),
        ]));
        $sample = true;

        if ($doc === 'pledge') {
            $show = \App\Models\Dhiran\DhiranSettings::receiptVisibility((int) $shop->id, 'pledge');

            return view('dhiran.receipt', compact('loan', 'shop', 'show', 'sample'));
        }
        if ($doc === 'payment') {
            $payment = (new \App\Models\Dhiran\DhiranPayment())->forceFill([
                'type' => 'interest_payment', 'direction' => 'in', 'amount' => 1000, 'payment_method' => 'upi', 'payment_date' => $today->toDateString(),
                'interest_component' => 1000, 'principal_component' => 0, 'penalty_component' => 0, 'processing_fee_component' => 0,
                'outstanding_principal_after' => 50000, 'outstanding_interest_after' => 0, 'outstanding_penalty_after' => 0,
            ]);
            $payment->id = 1;
            $payment->created_at = $today;
            $show = \App\Models\Dhiran\DhiranSettings::receiptVisibility((int) $shop->id, 'payment');

            return view('dhiran.payment-receipt', compact('loan', 'payment', 'show', 'sample'));
        }

        if ($doc === 'closure') {
            return view('dhiran.closure-certificate', compact('loan', 'sample'));
        }

        return view('dhiran.forfeiture-notice', compact('loan', 'sample'));
    }
}
