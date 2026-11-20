A captured Razorpay payment could not be applied to a subscription.

Payment: {{ $paymentId ?: '-' }}
Order: {{ $orderId ?: '-' }}
Reason: {{ $reason }}

@if($transient)
This looks temporary. The reconcile job retries every 10 minutes and will tell you when it is resolved.
@else
Automatic retries cannot fix this. Review the payment in Razorpay and either apply it by hand or refund it.
@endif
