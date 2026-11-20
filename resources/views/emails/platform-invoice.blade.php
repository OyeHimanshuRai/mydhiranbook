@php
    $title = $invoice->documentTitle();
    $sellerName = config('business.legal_name', config('app.name'));
    $sellerAddress = config('business.address');
    $sellerEmail = config('business.email');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} {{ $invoice->invoice_number }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; margin: 0; padding: 24px; color: #0f172a;">
    <div style="max-width: 580px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 36px;">
        <table style="width: 100%; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0;"><tr>
            <td>
                <h2 style="margin: 0 0 4px; font-size: 22px;">{{ $sellerName }}</h2>
                <p style="margin: 0; font-size: 13px; color: #64748b;">{{ $title }}</p>
                @if($sellerAddress)<p style="margin: 4px 0 0; font-size: 12px; color: #64748b;">{{ $sellerAddress }}</p>@endif
                @if($sellerEmail)<p style="margin: 2px 0 0; font-size: 12px; color: #64748b;">{{ $sellerEmail }}</p>@endif
            </td>
            <td style="text-align: right; vertical-align: top;">
                <div style="font-size: 15px; font-weight: 700;">{{ $invoice->invoice_number }}</div>
                <div style="font-size: 12px; color: #64748b;">{{ $invoice->issued_at->format('d M Y') }}</div>
            </td>
        </tr></table>

        <p style="font-size: 14px; color: #334155;">Dear {{ $payer?->name ?? 'Customer' }},</p>
        <p style="font-size: 14px; color: #475569;">Thank you for your payment. Your receipt is below.</p>

        <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px;">
            <tr style="background: #f1f5f9;">
                <th style="padding: 10px; text-align: left;">Description</th>
                <th style="padding: 10px; text-align: left;">Period</th>
                <th style="padding: 10px; text-align: right;">Amount</th>
            </tr>
            <tr>
                <td style="padding: 10px;">{{ $plan?->name ?? 'Subscription' }} ({{ ucfirst($invoice->billing_cycle) }})</td>
                <td style="padding: 10px;">{{ $invoice->billing_period_start->format('d M Y') }} - {{ $invoice->billing_period_end->format('d M Y') }}</td>
                <td style="padding: 10px; text-align: right;">&#8377;{{ number_format($invoice->amount_before_tax, 2) }}</td>
            </tr>
            @if($invoice->gst_amount > 0)
            <tr>
                <td style="padding: 10px;" colspan="2">GST ({{ number_format($invoice->gst_rate, 0) }}%)</td>
                <td style="padding: 10px; text-align: right;">&#8377;{{ number_format($invoice->gst_amount, 2) }}</td>
            </tr>
            @endif
            <tr style="border-top: 2px solid #0f172a; font-weight: 700;">
                <td style="padding: 10px;" colspan="2">Total paid</td>
                <td style="padding: 10px; text-align: right;">&#8377;{{ number_format($invoice->total_amount, 2) }}</td>
            </tr>
        </table>

        @if($invoice->razorpay_payment_id)
        <p style="font-size: 13px; color: #166534;">Payment received. Payment ID: {{ $invoice->razorpay_payment_id }}</p>
        @endif

        <p style="text-align: center; margin-top: 24px;">
            <a href="{{ route('subscription.receipt', $invoice) }}" style="display: inline-block; padding: 10px 24px; background: #0f172a; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px;">View receipt online</a>
        </p>
        <p style="margin-top: 28px; padding-top: 16px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center;">{{ config('app.name') }} - system-generated {{ strtolower($title) }}.</p>
    </div>
</body>
</html>
