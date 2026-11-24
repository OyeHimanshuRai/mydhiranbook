<?php
// Seller details shown on the public site, legal pages and payment receipts. All from .env (BUSINESS_*).
return [
    'name' => env('BUSINESS_NAME', env('APP_NAME', 'MyDhiranBook')),
    'legal_name' => env('BUSINESS_LEGAL_NAME'),
    'email' => env('BUSINESS_EMAIL'),
    'phone' => env('BUSINESS_PHONE'),
    'address' => env('BUSINESS_ADDRESS'),
    'jurisdiction' => env('BUSINESS_JURISDICTION', 'India'),
];
