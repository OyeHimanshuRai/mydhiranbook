@extends('site.layout') @section('title','Privacy Policy')
@section('content')
<!-- DRAFT: owner must review before go-live -->
<section class="band prose"><h1>Privacy Policy</h1><p class="muted"><x-site.biz key="legal_name"/> runs MyDhiranBook. This page explains what we collect and why.</p>
<h2>1. What we collect</h2><ul><li>Account details: your name, email address, mobile number and password (stored hashed).</li><li>Business details you enter: shop name, address and staff accounts.</li><li>Records you enter into MyDhiranBook: borrowers, their KYC details and documents, pledged items, loans and payments. You, the shop, decide what to enter.</li><li>Billing details: plan, amount and payment status. Card, UPI and bank details are handled by Razorpay; we do not see or store them.</li><li>Basic technical data such as IP address and session cookies, used to keep you signed in and keep the service secure.</li></ul>
<h2>2. Why we use it</h2><p>To provide and secure the service, take payments, send you account and billing emails, and answer your requests. We do not sell your data and do not use borrower records for advertising.</p>
<h2>3. Borrower data</h2><p>Borrower and KYC information belongs to the shop that entered it. We process it only on your behalf to run MyDhiranBook. Each shop's records are kept separate and are visible only to that shop's users.</p>
<h2>4. Who we share it with</h2><p>Only service providers needed to run MyDhiranBook, such as Razorpay for payments and our hosting and email providers, and authorities where the law requires it.</p>
<h2>5. Storage and security</h2><p>Data is stored on secured servers with access controls, hashed passwords and encrypted connections. No system is perfectly secure, so please keep your password safe.</p>
<h2>6. Retention</h2><p>We keep your records while your account exists. We do not delete data automatically when a plan ends. Ask us to delete or export your data and we will do so, except where we must keep billing records by law.</p>
<h2>7. Your choices</h2><p>You can ask to see, correct, export or delete your data by contacting us below.</p>
<h2>8. Contact</h2><p><x-site.biz key="legal_name"/>, <x-site.biz key="address"/>. Email <x-site.biz key="email"/>, phone <x-site.biz key="phone"/>.</p></section>
@endsection
