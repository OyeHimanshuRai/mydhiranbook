<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\Dhiran\DhiranSettings;
use Illuminate\Http\Request;
class ReferenceDhiranController
{
    public function create(Request $request)
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        $customers = Customer::where('shop_id', $shopId)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'mobile', 'address', 'pan', 'id_number', 'state_code']);

        // Preselect a borrower when arriving from the borrower profile / list
        // (?customer_id=). Only honoured if that customer belongs to this shop.
        $preselectedCustomerId = null;
        if ($request->filled('customer_id')) {
            $preselectedCustomerId = $customers->firstWhere('id', (int) $request->customer_id)?->id;
        }

        // The shop's Dhiran settings seed the form (the view read $defaults, which was never passed, so it always
        // showed built-in values whatever the shop had set).
        $defaults = [
            'interest_rate_monthly'  => (float) $settings->default_interest_rate_monthly,
            'interest_type'          => $settings->default_interest_type ?: 'flat',
            'tenure_months'          => (int) $settings->default_tenure_months,
            'penalty_rate_monthly'   => (float) $settings->default_penalty_rate_monthly,
            'processing_fee_percent' => $settings->processing_fee_type === 'percent' ? (float) $settings->processing_fee_value : 0,
            'processing_fee_flat'    => $settings->processing_fee_type === 'percent' ? 0 : (float) $settings->processing_fee_value,
            'ltv_percent'            => (float) $settings->default_ltv_ratio,
            // The limits DhiranService::createLoan checks, so the form can say "Up to ₹X" before saving.
            'high_value_ltv'         => (float) $settings->high_value_ltv_ratio,
            'high_value_threshold'   => (float) $settings->high_value_threshold,
            'min_loan'               => (float) $settings->min_loan_amount,
            'max_loan'               => (float) $settings->max_loan_amount,
        ];

        return view('reference.dhiran.create', compact('settings', 'customers', 'preselectedCustomerId', 'defaults'));
    }

    /**
     * Create a borrower inline from the Dhiran New Loan page. Strictly scoped to
     * the current Dhiran shop: shop_id is NEVER taken from the request — it is set
     * by the Customer model's BelongsToShop hook from the tenant context (and we
     * pass the authenticated shop_id explicitly as belt-and-suspenders). A mobile
     * already used in THIS shop returns that existing customer instead of creating
     * a duplicate (the (shop_id, mobile) unique index also enforces this at the DB).
     */
}
