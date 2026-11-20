<?php
namespace App\Http\Controllers;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Services\Billing\SubscriptionPaymentService;
use App\Services\Billing\SubscriptionWebhookService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Razorpay\Api\Errors\SignatureVerificationError;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionPaymentService $payments, private readonly SubscriptionWebhookService $webhooks) {}

    /** Buying is the shop owner's alone; a user with no shop yet may buy (pay-before-shop). Staff get 403. */
    private function authorizeBuyer(): void
    {
        $user = Auth::user();
        abort_unless($user && $user->is_active && ($user->shop_id === null || $user->isShopOwner()), 403);
    }

    /** Render inside the shop's tenant context (the shop layout reads tenant-scoped data). */
    private function render(string $view, array $data)
    {
        $shopId = Auth::user()->shop_id;
        $html = $shopId ? TenantContext::runFor((int) $shopId, fn () => view($view, $data)->render()) : view($view, $data)->render();
        return response($html);
    }

    private function cycleOf(Plan $plan): string { return $plan->price_yearly !== null ? 'yearly' : 'monthly'; }

    public function choosePlan(Request $request)
    {
        $this->authorizeBuyer();
        $data = $request->validate(['plan_id' => 'required|integer', 'billing_cycle' => 'required|in:monthly,yearly']);
        $plan = Plan::where('is_active', true)->find($data['plan_id']);
        if (!$plan) { throw ValidationException::withMessages(['plan_id' => 'That plan is no longer available.']); }
        if (!$plan->sellsCycle($data['billing_cycle'])) {
            throw ValidationException::withMessages(['billing_cycle' => "{$plan->name} is not sold on a {$data['billing_cycle']} cycle."]);
        }
        session(['pending_plan_id' => $plan->id, 'pending_billing_cycle' => $data['billing_cycle']]);
        return redirect()->route('subscription.payment');
    }

    /** The plan and cycle the user chose, re-validated; the request body is never trusted for price. */
    private function pendingChoice(): ?array
    {
        $plan = Plan::where('is_active', true)->find(session('pending_plan_id'));
        $cycle = session('pending_billing_cycle');
        return $plan && $cycle && $plan->sellsCycle($cycle) ? [$plan, $cycle] : null;
    }

    public function payment()
    {
        $this->authorizeBuyer();
        $choice = $this->pendingChoice();
        if (!$choice) { return redirect()->route('subscription.status')->with('error', 'Please choose a plan first.'); }
        [$plan, $cycle] = $choice;
        return $this->render('subscription.payment', [
            'plan' => $plan, 'cycle' => $cycle, 'price' => $plan->priceFor($cycle),
            'testMode' => str_starts_with((string) config('services.razorpay.key'), 'rzp_test_'),
        ]);
    }

    public function initiatePayment()
    {
        $this->authorizeBuyer();
        $choice = $this->pendingChoice();
        if (!$choice) { return response()->json(['error' => 'Session expired. Please choose a plan again.', 'redirect' => route('subscription.status')], 422); }
        [$plan, $cycle] = $choice;
        $user = Auth::user();
        try {
            $order = $this->payments->createRazorpayOrder($plan, $cycle);
        } catch (\Throwable $e) {
            Log::error('Razorpay order creation failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Payment initiation failed. Please try again.'], 500);
        }
        return response()->json([
            'order_id' => $order->id, 'amount' => $plan->priceFor($cycle) * 100, 'currency' => 'INR', 'key_id' => config('services.razorpay.key'),
            'plan_name' => $plan->name, 'user_name' => $user->name, 'user_email' => $user->email ?? '', 'user_contact' => $user->mobile_number ?? '',
        ]);
    }

    public function paymentCallback(Request $request)
    {
        $this->authorizeBuyer();
        $paymentId = (string) $request->input('razorpay_payment_id');
        $orderId = (string) $request->input('razorpay_order_id');
        $signature = (string) $request->input('razorpay_signature');
        $back = fn (string $message) => redirect()->route('subscription.payment')->with('error', $message.' Contact support with ref: '.$paymentId);
        if ($paymentId === '' || $orderId === '' || $signature === '') { return redirect()->route('subscription.payment')->with('error', 'Payment verification failed. Please try again.'); }

        try { $this->payments->verifyPaymentSignature($orderId, $paymentId, $signature); }
        catch (SignatureVerificationError) {
            Log::warning('Razorpay callback signature invalid', ['payment_id' => $paymentId, 'user_id' => Auth::id()]);
            return $back('Payment signature invalid.');
        }

        // The signature proves the payment is genuine, not who is holding the session: bind it to the order's payer.
        try { $order = $this->payments->fetchAndValidateOrder($orderId)['order']; }
        catch (\Throwable $e) {
            Log::error('Callback: order fetch failed; webhook/reconcile will retry', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return $back('Could not verify payment yet; it will be applied automatically if it was captured.');
        }
        $payer = ((array) ($order->notes ?? []))['user_id'] ?? null;
        if ($payer === null || (int) $payer !== (int) Auth::id()) {
            Log::warning('Callback: order user mismatch', ['payment_id' => $paymentId, 'order_user_id' => $payer, 'auth_user_id' => Auth::id()]);
            return $back('This payment does not match your account.');
        }

        $result = $this->payments->applyCapturedPayment($orderId, $paymentId);
        if ($result['subscription'] === null) { return $back('Your payment could not be applied yet.'); }

        session()->forget(['pending_plan_id', 'pending_billing_cycle']);
        return redirect()->route(Auth::user()->shop_id ? 'subscription.status' : 'dhiran.onboarding')->with('success', 'Payment received. Your subscription is active.');
    }

    public function webhook(Request $request)
    {
        $body = $request->getContent();
        try { $this->webhooks->verifySignature($body, $request->header('X-Razorpay-Signature')); }
        catch (SignatureVerificationError) { Log::warning('Razorpay webhook invalid signature'); return response()->json(['status' => 'rejected'], 401); }
        catch (\RuntimeException) { return response()->json(['status' => 'rejected', 'reason' => 'webhook secret not configured'], 500); }

        // Razorpay delivers at least once; the event id is what lets a redelivery collapse to one mutation.
        $eventId = trim((string) $request->header('X-Razorpay-Event-Id', ''));
        if ($eventId === '') { return response()->json(['status' => 'rejected', 'reason' => 'missing event id'], 400); }

        $payload = json_decode($body, true) ?: [];
        $applied = SubscriptionPaymentService::OUTCOME_APPLIED;
        $outcome = match ($payload['event'] ?? '') {
            'payment.captured' => $this->webhooks->handlePaymentCaptured($payload),
            'refund.created' => $this->webhooks->handleRefundCreated($payload, $eventId),
            'payment.failed' => (function () use ($payload, $eventId, $applied) { $this->webhooks->handlePaymentFailed($payload, $eventId); return $applied; })(),
            default => $applied,
        };

        // Permanent maps to 200: evidence is already recorded and a retry would only repeat it. Only transient asks Razorpay to retry.
        return match ($outcome) {
            SubscriptionPaymentService::OUTCOME_TRANSIENT => response()->json(['status' => 'retry'], 500),
            SubscriptionPaymentService::OUTCOME_PERMANENT => response()->json(['status' => 'not_applied']),
            default => response()->json(['status' => 'ok']),
        };
    }

    public function status()
    {
        $this->authorizeBuyer();
        $user = Auth::user();
        $subscription = ShopSubscription::with('plan')
            ->when($user->shop_id, fn ($q) => $q->where('shop_id', $user->shop_id), fn ($q) => $q->whereNull('shop_id')->where('user_id', $user->id))
            ->orderByRaw('ends_at desc nulls last')->orderByDesc('id')->first();
        return $this->render('subscription.status', [
            'subscription' => $subscription,
            'plans' => Plan::where('is_active', true)->orderBy('price_monthly')->get(),
            'hasShop' => (bool) $user->shop_id,
            'receipts' => PlatformInvoice::where('status', 'issued')
                ->where(fn ($q) => $q->where('shop_id', $user->shop_id ?? 0)->orWhere(fn ($q) => $q->whereNull('shop_id')->whereHas('subscription', fn ($s) => $s->where('user_id', $user->id))))
                ->orderByDesc('id')->get(),
        ]);
    }

    /** Own shop's receipts, or the owner's own shopless receipts; everything else is a 404 (no existence leak). */
    public function receipt(PlatformInvoice $invoice)
    {
        $this->authorizeBuyer();
        $user = Auth::user();
        $invoice->load(['subscription.user', 'plan', 'shop']);
        $own = $invoice->shop_id !== null
            ? (int) $invoice->shop_id === (int) $user->shop_id
            : (int) $invoice->subscription?->user_id === (int) $user->id;
        abort_unless($own, 404);
        return response(view('subscription.receipt', ['invoice' => $invoice])->render());
    }
}
