@extends('admin.layout') @section('title','Shops') @section('content')
<div class="adm-head"><div><h1>Shops</h1><p>Every shop on the platform, its access state and current subscription.</p></div><form class="adm-search" method="get" action="/admin/shops" role="search"><input name="q" value="{{ $q }}" placeholder="Search by name" aria-label="Search shops"><button class="adm-btn">Search</button></form></div>
<section class="adm-panel"><div class="adm-tablewrap"><table class="adm-table"><thead><tr><th>Shop</th><th>Access</th><th>Plan</th><th>Subscription</th><th>Ends</th></tr></thead><tbody>
@forelse($shops as $shop)@php $c=$shop->current; @endphp
<tr><td class="adm-key"><a href="/admin/shops/{{ $shop->id }}">{{ $shop->name }}</a><span class="adm-sub">Shop #{{ $shop->id }} · {{ $shop->created_at?->format('d M Y') }}</span></td>
<td data-label="Access">@include('admin._badge',['s'=>$shop->status])</td>
<td data-label="Plan">{{ $c?->plan?->code ?? 'No plan' }}</td>
<td data-label="Subscription">@if($c)@include('admin._badge',['s'=>$c->status])@else<span class="adm-muted">Not set</span>@endif</td>
<td data-label="Ends" class="num">{{ $c?->ends_at?->format('d M Y') ?? '—' }}</td></tr>
@empty<tr><td colspan="5" class="adm-empty"><strong>{{ $q ? 'No shops match “'.$q.'”' : 'No shops yet' }}</strong>{{ $q ? 'Check the spelling or clear the search.' : 'A shop appears here after its first captured payment or an admin grant.' }}</td></tr>@endforelse
</tbody></table></div>@include('pagination',['page'=>$shops])</section>
@endsection
