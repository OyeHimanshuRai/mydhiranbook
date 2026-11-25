@extends('admin.layout') @section('title','Platform dashboard') @section('content')
@php $u=auth('platform_admin')->user(); @endphp
<div class="adm-head"><div><h1>Dashboard</h1><p>MyDhiranBook platform: shops, access terms and admin access.</p></div>@include('admin._badge',['s'=>'enabled','label'=>'MFA verified'])</div>
@if($stats['pending'])<div class="notice" role="status"><a href="/admin/requests">{{ $stats['pending'] }} new shop request(s) waiting for approval</a></div>@endif
<dl class="adm-stats">
<div class="adm-stat"><dt>Shops</dt><dd>{{ $stats['shops'] }}</dd><small>{{ $stats['active'] }} active · {{ $stats['suspended'] }} suspended</small></div>
<div class="adm-stat"><dt>Entitled shops</dt><dd>{{ $stats['entitled'] }}</dd><small>Access term running</small></div>
<div class="adm-stat"><dt>New shop requests</dt><dd>{{ $stats['pending'] }}</dd><small>Waiting for approval</small></div>
</dl>
@if($ending->isNotEmpty())<section class="adm-panel"><div class="adm-panel-head"><h2>Access ending soon or ended</h2><p>Shops whose one-year access ends within 30 days or has already ended. Extending adds one more year.</p></div><div class="adm-tablewrap"><table class="adm-table"><thead><tr><th>Shop</th><th>Access until</th><th>State</th><th></th></tr></thead><tbody>
@foreach($ending as $shop)@php $end=$shop->access_ends ? \Illuminate\Support\Carbon::parse($shop->access_ends) : null; $left=$end ? (int) today()->diffInDays($end->startOfDay(),false) : null; @endphp
<tr><td class="adm-key"><a href="/admin/shops/{{ $shop->id }}">{{ $shop->name }}</a><span class="adm-sub">Shop #{{ $shop->id }}</span></td>
<td data-label="Access until" class="num">{{ $end?->format('d M Y') ?? '—' }}</td>
<td data-label="State">@if($left===null)@include('admin._badge',['s'=>'expired','label'=>'No access term'])@elseif($left<0)@include('admin._badge',['s'=>'expired','label'=>'Ended '.abs($left).' d ago'])@else @include('admin._badge',['s'=>'grace','label'=>'Ends in '.$left.' d'])@endif</td>
<td>@if($u->hasPermission('can_manage_subscriptions'))<form method="post" action="/admin/shops/{{ $shop->id }}/extend" data-once>@csrf<button class="adm-btn adm-btn--ghost">Extend 1 year</button></form>@endif</td></tr>
@endforeach</tbody></table></div></section>@endif
<section class="adm-panel"><div class="adm-panel-head"><h2>Go to</h2><p>{{ $adminCount }} active platform admin(s).</p></div><div class="adm-panel-body"><div class="adm-actions" style="margin:0">
@if($u->hasPermission('can_manage_shops'))<a class="adm-btn adm-btn--ghost" href="/admin/shops">Shops</a>@endif
@if($u->hasPermission('can_manage_plans'))<a class="adm-btn adm-btn--ghost" href="/admin/plans">Plans</a>@endif
</div></div></section>
@endsection
