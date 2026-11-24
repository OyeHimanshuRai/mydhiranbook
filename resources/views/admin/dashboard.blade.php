@extends('admin.layout') @section('title','Platform dashboard') @section('content')
@php $u=auth('platform_admin')->user(); @endphp
<div class="adm-head"><div><h1>Dashboard</h1><p>MyDhiranBook platform: shops, subscriptions and admin access.</p></div>@include('admin._badge',['s'=>'enabled','label'=>'MFA verified'])</div>
@if($stats['unresolved'])<div class="notice error" role="alert"><a href="/admin/unresolved-payments">{{ $stats['unresolved'] }} captured payment(s) need review</a></div>@endif
<dl class="adm-stats">
<div class="adm-stat"><dt>Shops</dt><dd>{{ $stats['shops'] }}</dd><small>{{ $stats['active'] }} active · {{ $stats['suspended'] }} suspended</small></div>
<div class="adm-stat"><dt>Entitled shops</dt><dd>{{ $stats['entitled'] }}</dd><small>Paid or in grace</small></div>
<div class="adm-stat"><dt>Paid, business not created</dt><dd>{{ $stats['pending'] }}</dd></div>
<div class="adm-stat"><dt>Collected this month</dt><dd>₹{{ \App\Support\IndianNumber::format((float)$stats['collected'], 2) }}</dd></div>
</dl>
<section class="adm-panel"><div class="adm-panel-head"><h2>Go to</h2><p>{{ $adminCount }} active platform admin(s).</p></div><div class="adm-panel-body"><div class="adm-actions" style="margin:0">
@if($u->hasPermission('can_manage_shops'))<a class="adm-btn adm-btn--ghost" href="/admin/shops">Shops</a>@endif
@if($u->hasPermission('can_manage_plans'))<a class="adm-btn adm-btn--ghost" href="/admin/plans">Plans</a>@endif
@if($u->hasPermission('can_manage_subscriptions'))<a class="adm-btn adm-btn--ghost" href="/admin/unresolved-payments">Unresolved payments</a>@endif
</div></div></section>
@endsection
