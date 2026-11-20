<?php
return [
    'gst_registered' => (bool) env('BILLING_GST_REGISTERED', false),
    'gst_rate' => 18.0,
    'receipt_prefix' => 'DHR',
    'alert_email' => env('BILLING_ALERT_EMAIL'),
];
