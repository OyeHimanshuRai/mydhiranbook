@extends('admin.layout') @section('title','Platform dashboard') @section('content')
<div class="heading"><div><p class="eyebrow" style="margin-bottom:10px">CONTROL TOWER</p><h1>Dhiran platform.</h1><p>Shops, subscriptions and platform access.</p></div><span class="badge">MFA verified</span></div>
<div class="metrics">
<div class="metric"><span>Shops</span><strong>{{ $stats['shops'] }}</strong><span class="help">{{ $stats['active'] }} active · {{ $stats['suspended'] }} suspended</span></div>
<div class="metric"><span>Entitled shops</span><strong>{{ $stats['entitled'] }}</strong></div>
<div class="metric"><span>Paid, business not created</span><strong>{{ $stats['pending'] }}</strong></div>
<div class="metric"><span>Collected this month</span><strong>₹{{ number_format((float)$stats['collected']) }}</strong></div>
</div>
@if($stats['unresolved'])<div class="notice error" role="alert"><a href="/admin/unresolved-payments">{{ $stats['unresolved'] }} captured payment(s) need review</a></div>@endif
<section class="panel"><div class="panel-body"><p><a href="/admin/shops">Shops</a> · <a href="/admin/plans">Plans</a> · <a href="/admin/unresolved-payments">Unresolved payments</a></p><p class="muted">{{ $adminCount }} active platform admin(s).</p></div></section>
@endsection
