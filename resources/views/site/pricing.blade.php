@extends('site.layout') @section('title','Pricing')
@section('content')
<section class="band"><h1>Pricing</h1><p class="band-lead">No free trial. Prices in INR. Pay once through Razorpay; plans do not renew by themselves, you renew when you choose.</p>
@if($plans->isEmpty())<p class="ui-notice">Plans are not available right now. Please <a href="/contact">contact us</a>.</p>@endif
<div class="cards plans">@foreach($plans as $plan)@php($yearly = $plan->price_yearly !== null)
<article class="plan"><h2>{{ $plan->name }}</h2><p class="price">₹{{ number_format($plan->priceFor($yearly ? 'yearly' : 'monthly')) }}<small> / {{ $yearly ? 'year' : 'month' }}</small></p>
<ul><li>Up to {{ $plan->feature('staff_limit') }} staff accounts</li><li>Up to {{ number_format($plan->feature('max_items')) }} items</li><li>{{ $plan->grace_days }} days of full access after expiry</li></ul>
<a class="ui-btn" href="/register">Start {{ $plan->name }}</a></article>@endforeach</div>
<p class="note">See <a href="/refund">Refund &amp; Cancellation</a> before you pay.</p></section>
@endsection
