<x-subscription-page title="Payment">
<div class="ck ck-narrow">
    <h1>Payment</h1>
    <div class="ui-card">
        <h2>{{ $plan->name }}</h2>
        <p class="ck-total">₹{{ number_format($price) }} <small>/ {{ $cycle === 'yearly' ? 'year' : 'month' }}</small></p>
        <p class="lede">One payment through Razorpay. It does not renew by itself. The price is set on our server.</p>
        @if($testMode)<p class="ui-notice">Razorpay test mode: no real money moves.</p>@endif
        <p id="pay-error" class="ui-notice ui-notice--error" role="alert" hidden></p>
        <div class="ck-actions"><button type="button" id="pay-btn" class="ui-btn ui-btn--gold">Pay ₹{{ number_format($price) }}</button><a href="{{ route('subscription.status') }}" class="ui-btn ui-btn--ghost">Back</a></div>
        <form id="pay-return" method="post" action="{{ route('subscription.payment.callback') }}" data-turbo="false" hidden>@csrf
            <input name="razorpay_order_id"><input name="razorpay_payment_id"><input name="razorpay_signature">
        </form>
    </div>
</div>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
    var btn = document.getElementById('pay-btn'), err = document.getElementById('pay-error');
    var fail = function (m) { err.textContent = m; err.hidden = false; btn.disabled = false; };
    btn.addEventListener('click', function () {
        btn.disabled = true; err.hidden = true;
        fetch('{{ route('subscription.payment.initiate') }}', {method: 'POST', headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json'}})
            .then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.error || 'Payment could not start.'); } return d; }); })
            .then(function (o) {
                var rz = new Razorpay({key: o.key_id, amount: o.amount, currency: o.currency, order_id: o.order_id, name: 'Dhiran', description: o.plan_name,
                    prefill: {name: o.user_name, email: o.user_email, contact: o.user_contact},
                    handler: function (resp) { var f = document.getElementById('pay-return'); f.razorpay_order_id.value = resp.razorpay_order_id; f.razorpay_payment_id.value = resp.razorpay_payment_id; f.razorpay_signature.value = resp.razorpay_signature; f.submit(); },
                    modal: {ondismiss: function () { btn.disabled = false; }}});
                rz.on('payment.failed', function (e) { fail((e.error && e.error.description) || 'Payment failed. Please try again.'); });
                rz.open();
            })
            .catch(function (e) { fail(e.message); });
    });
})();
</script>
</x-subscription-page>
