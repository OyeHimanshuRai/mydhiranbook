@extends('site.layout') @section('title','Terms of Service')
@section('content')
<!-- DRAFT: owner must review before go-live -->
<section class="band prose"><h1>Terms of Service</h1><p class="muted">These terms are between <x-site.biz key="legal_name"/> ("we", "us") and the business or person who creates a Dhiran account ("you").</p>
<h2>1. The service</h2><p>Dhiran is online software for recording and managing pledge loans, borrowers, repayments and reports. You are responsible for the loans you make and for following the laws that apply to your lending business.</p>
<h2>2. Subscription and payment</h2><p>Dhiran is sold as a paid monthly or yearly plan. There is no free trial. Prices are shown in INR on the <a href="/pricing">pricing page</a>. Payment is made through Razorpay. A plan runs for the period you paid for and does not renew automatically; you pay again to renew. Your business space is created only after payment is received.</p>
<h2>3. After a plan ends</h2><p>After expiry there is a short grace period shown on your plan, during which you keep full access. After the grace period the account becomes collect-only: you can record repayments but not create new loans until you renew. We do not delete your data automatically.</p>
<h2>4. Your account</h2><p>Keep your password secret and make sure the staff you add follow these terms. You are responsible for activity under your account.</p>
<h2>5. Acceptable use</h2><p>Do not use Dhiran for anything unlawful, to harm others, to attack or overload the service, to access another shop's data, or to resell the service without our written permission.</p>
<h2>6. Your data</h2><p>Your shop owns the borrower, loan and other records you enter. You grant us the right to store and process them only to run the service for you. How we handle them is described in the <a href="/privacy">Privacy Policy</a>. You are responsible for having the right to enter your borrowers' information.</p>
<h2>7. Refunds and cancellation</h2><p>See the <a href="/refund">Refund &amp; Cancellation Policy</a>.</p>
<h2>8. Availability</h2><p>We work to keep Dhiran available but do not promise it will be uninterrupted or error free. Keep your own copies of records you cannot afford to lose.</p>
<h2>9. Limit of liability</h2><p>To the extent the law allows, our total liability to you for any claim is limited to the amount you paid for the plan period in which the claim arose, and we are not liable for indirect or consequential loss, including lost profit or lost business.</p>
<h2>10. Termination</h2><p>You may stop using Dhiran at any time. We may suspend or end an account that breaks these terms, after notice where reasonably possible.</p>
<h2>11. Changes</h2><p>We may update these terms. Continued use after an update means you accept it.</p>
<h2>12. Governing law</h2><p>These terms are governed by the laws of <x-site.biz key="jurisdiction"/>, and the courts there have jurisdiction.</p>
<h2>13. Contact</h2><p><x-site.biz key="legal_name"/>, <x-site.biz key="address"/>. Email <x-site.biz key="email"/>.</p></section>
@endsection
