<?php
namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;

/** Read-only "Your plan" page: no payment, no plan choice. An admin grants and extends access. */
class SubscriptionController extends Controller
{
    public function status()
    {
        $user = Auth::user();
        abort_unless($user && $user->is_active, 403);
        $data = ['subscription' => $user->shop?->currentSubscription(), 'hasShop' => (bool) $user->shop_id];
        // the shop layout reads tenant-scoped data
        $html = $user->shop_id ? TenantContext::runFor((int) $user->shop_id, fn () => view('subscription.status', $data)->render()) : view('subscription.status', $data)->render();
        return response($html);
    }
}
