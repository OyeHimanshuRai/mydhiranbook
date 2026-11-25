@php
    // $show('key'): the owner's Settings > Receipt switches. The Loan Number has no switch and is always printed.
    $any = fn (string ...$keys) => collect($keys)->contains(fn ($k) => $show($k));
    $shop = auth()->user()?->shop;
    $shopName = $shop?->name ?: 'MyDhiranBook';
    $shopContact = collect([$shop?->mobile ?? $shop?->phone ?? auth()->user()?->mobile, auth()->user()?->email])->filter()->implode(' · ');
    $customer = $loan->customer;
    $paymentDate = $payment->payment_date ? \Illuminate\Support\Carbon::parse($payment->payment_date)->format('d M Y') : '-';
    $paymentTime = ($payment->created_at ?? now())->format('h:i A');
    $receiptNo = 'DPR-' . str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT);
    $n = fn ($v) => number_format((float) $v, 2);
    $hasFee = (float) ($payment->processing_fee_component ?? 0) > 0;
    $balance = (float) $payment->outstanding_principal_after + (float) $payment->outstanding_interest_after + (float) $payment->outstanding_penalty_after;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - {{ $loan->loan_number }}</title>
    @include('dhiran._receipt-css')
</head>
<body class="rc-body dh-doc-payment-receipt">
    @if($sample ?? false) @include('dhiran._sample-banner') @endif
    <div class="rc-bar actions">
        <button type="button" class="rc-btn btn" onclick="window.print()">Print</button>
        <a class="rc-btn rc-btn-light btn" href="{{ route('dhiran.show', $loan) }}">Back to Loan</a>
    </div>

    <div class="receipt-container">
        @if($show('shop_name') || $show('title') || ($show('shop_address') && $shop?->address) || ($show('shop_contact') && $shopContact))
        <header class="rc-head">
            @if($show('shop_name'))<div class="receipt-shop-name">{{ $shopName }}</div>@endif
            @if($show('shop_address') && $shop?->address)<div class="rc-muted">{{ $shop->address }}</div>@endif
            @if($show('shop_contact') && $shopContact)<div class="rc-muted">{{ $shopContact }}</div>@endif
            @if($show('title'))<div class="rc-title">Payment Receipt</div>@endif
        </header>
        @endif

        <section class="rc-sec">
            <dl class="rc-rows rc-cols">
                <div class="rc-row"><dt>Loan No.</dt><dd>{{ $loan->loan_number }}</dd></div>
                @if($show('payment_for'))<div class="rc-row"><dt>Payment For</dt><dd>{{ ['advance_interest' => 'Interest paid in advance', 'interest_payment' => 'Interest', 'renewal_interest' => 'Renewal interest', 'principal_repayment' => 'Principal repayment', 'pre_closure' => 'Pre-closure', 'processing_fee' => 'Processing fee', 'disbursement' => 'Loan payout', 'cancellation' => 'Cancellation'][$payment->type] ?? ucwords(str_replace('_', ' ', (string) $payment->type)) }}</dd></div>@endif
                @if($show('receipt_no'))<div class="rc-row"><dt>Receipt No</dt><dd>{{ $receiptNo }}</dd></div>@endif
                @if($show('payment_date'))<div class="rc-row"><dt>Payment Date</dt><dd>{{ $paymentDate }}, {{ $paymentTime }}</dd></div>@endif
                @if($show('method'))<div class="rc-row"><dt>Method</dt><dd>{{ \App\Models\Dhiran\DhiranPayment::methodLabel($payment->payment_method ?? 'cash') }}</dd></div>@endif
            </dl>
        </section>

        @if($any('customer_name', 'customer_mobile') || ($show('customer_address') && $customer?->address))
        <section class="rc-sec">
            <h2 class="rc-sec-title">Received From</h2>
            <dl class="rc-rows rc-cols">
                @if($show('customer_name'))<div class="rc-row"><dt>Name</dt><dd>{{ $customer?->name ?? 'Walk-in Customer' }}</dd></div>@endif
                @if($show('customer_mobile'))<div class="rc-row"><dt>Contact</dt><dd>{{ \App\Support\Mobile::forDisplay($customer?->mobile) ?: 'No mobile available' }}</dd></div>@endif
                @if($show('customer_address') && $customer?->address)<div class="rc-row"><dt>Address</dt><dd>{{ $customer->address }}</dd></div>@endif
            </dl>
        </section>
        @endif

        @if($any('principal_component', 'interest_component', 'penalty_component', 'amount_paid') || ($show('fee_component') && $hasFee))
        <section class="rc-sec">
            <h2 class="rc-sec-title">Amount</h2>
            <dl class="rc-rows">
                @if($show('principal_component'))<div class="rc-row"><dt>Principal Component</dt><dd>{{ $n($payment->principal_component) }}</dd></div>@endif
                @if($show('interest_component'))<div class="rc-row"><dt>Interest Component</dt><dd>{{ $n($payment->interest_component) }}</dd></div>@endif
                @if($show('penalty_component'))<div class="rc-row"><dt>Penalty Component</dt><dd>{{ $n($payment->penalty_component) }}</dd></div>@endif
                @if($show('fee_component') && $hasFee)<div class="rc-row"><dt>Processing Fee</dt><dd>{{ $n($payment->processing_fee_component) }}</dd></div>@endif
                @if($show('amount_paid'))<div class="rc-row rc-strong"><dt>Amount Paid</dt><dd>{{ $n($payment->amount) }}</dd></div>@endif
            </dl>
        </section>
        @endif

        @if($any('balance_principal', 'balance_interest', 'balance_penalty', 'balance_total'))
        <section class="rc-sec">
            <h2 class="rc-sec-title">Balance After Payment</h2>
            <dl class="rc-rows">
                @if($show('balance_principal'))<div class="rc-row"><dt>Outstanding Principal After</dt><dd>{{ $n($payment->outstanding_principal_after) }}</dd></div>@endif
                @if($show('balance_interest'))<div class="rc-row"><dt>Outstanding Interest After</dt><dd>{{ $n($payment->outstanding_interest_after) }}</dd></div>@endif
                @if($show('balance_penalty'))<div class="rc-row"><dt>Outstanding Penalty After</dt><dd>{{ $n($payment->outstanding_penalty_after) }}</dd></div>@endif
                @if($show('balance_total'))<div class="rc-row rc-strong"><dt>Total Balance After Payment</dt><dd>{{ $n($balance) }}</dd></div>@endif
            </dl>
        </section>
        @endif

        @if($show('signatures'))
        <div class="rc-sigs">
            <div class="rc-sig">Customer Signature</div>
            <div class="rc-sig">For {{ $shopName }}</div>
        </div>
        @endif
    </div>
</body>
</html>
