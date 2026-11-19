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

        return view('reference.dhiran.create', compact('settings', 'customers', 'preselectedCustomerId'));
    }

}
