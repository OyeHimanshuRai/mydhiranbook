@php
    $title = $invoice->documentTitle();
    $sellerName = config('business.legal_name', config('app.name'));
    $sellerAddress = config('business.address');
    $sellerEmail = config('business.email');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }} {{ $invoice->invoice_number }}</title>
    <style>
        body{font-family:system-ui,sans-serif;color:#0f172a;margin:0;padding:24px;background:#f8fafc}
        .doc{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:32px}
        .head{display:flex;justify-content:space-between;gap:16px;border-bottom:1px solid #e2e8f0;padding-bottom:16px;margin-bottom:20px}
        h1{margin:0 0 4px;font-size:22px}.muted{color:#64748b;font-size:13px;margin:2px 0}
        table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:10px;text-align:left;border-bottom:1px solid #f1f5f9}
        .r{text-align:right}.total td{font-weight:700;border-top:2px solid #0f172a}
        .actions{max-width:720px;margin:16px auto;display:flex;gap:12px}
        @media print{.actions{display:none}body{background:#fff;padding:0}.doc{border:0}}
    </style>
</head>
<body>
    <div class="doc">
        <div class="head">
            <div>
                <h1>{{ $sellerName }}</h1>
                @if($sellerAddress)<p class="muted">{{ $sellerAddress }}</p>@endif
                @if($sellerEmail)<p class="muted">{{ $sellerEmail }}</p>@endif
            </div>
            <div class="r">
                <strong>{{ $title }}</strong>
                <p class="muted">{{ $invoice->invoice_number }}</p>
                <p class="muted">{{ $invoice->issued_at->format('d M Y') }}</p>
            </div>
        </div>
        <p class="muted">Received from: {{ $invoice->subscription?->user?->name }}@if($invoice->shop) ({{ $invoice->shop->name }})@endif</p>
        <table>
            <tr><th>Description</th><th>Period</th><th class="r">Amount</th></tr>
            <tr>
                <td>{{ $invoice->plan?->name ?? 'Subscription' }} ({{ ucfirst($invoice->billing_cycle) }})</td>
                <td>{{ $invoice->billing_period_start->format('d M Y') }} - {{ $invoice->billing_period_end->format('d M Y') }}</td>
                <td class="r">&#8377;{{ number_format($invoice->amount_before_tax, 2) }}</td>
            </tr>
            @if($invoice->gst_amount > 0)
            <tr><td colspan="2">GST ({{ number_format($invoice->gst_rate, 0) }}%)</td><td class="r">&#8377;{{ number_format($invoice->gst_amount, 2) }}</td></tr>
            @endif
            <tr class="total"><td colspan="2">Total paid</td><td class="r">&#8377;{{ number_format($invoice->total_amount, 2) }}</td></tr>
        </table>
        @if($invoice->razorpay_payment_id)<p class="muted" style="margin-top:16px">Payment ID: {{ $invoice->razorpay_payment_id }}</p>@endif
    </div>
    <div class="actions"><button onclick="window.print()">Print</button><a href="{{ route('subscription.status') }}">Back to subscription</a></div>
</body>
</html>
