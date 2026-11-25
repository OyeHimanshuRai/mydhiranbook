@extends('admin.layout') @section('title','New shop requests') @section('content')
<div class="adm-head"><div><h1>New shop requests</h1><p>A new owner has verified their email and sent their shop details. They wait until you approve.</p></div></div>
@forelse($shops as $shop)
@php $owner = ($owners[$shop->id] ?? collect())->first(); @endphp
<section class="adm-panel">
  <div class="adm-panel-head"><h2>{{ $shop->name }}</h2><p>Shop #{{ $shop->id }} · sent {{ $shop->created_at?->format('d M Y, h:i A') }}</p></div>
  <div class="adm-panel-body">
    <dl class="adm-kv">
      <div><dt>Phone</dt><dd>{{ $shop->phone }}</dd></div>
      <div><dt>Address</dt><dd>{{ $shop->address }}</dd></div>
      <div><dt>Owner</dt><dd>{{ $owner?->name ?: ($owner?->first_name.' '.$owner?->last_name) }} · {{ $owner?->email }} · {{ $owner?->mobile_number }}</dd></div>
    </dl>
    <form method="post" action="/admin/requests/{{ $shop->id }}/approve" class="adm-form" data-once>@csrf
      <div class="adm-fields">
        <label>Plan<select name="plan" required>@foreach($plans as $plan)<option value="{{ $plan->code }}">{{ $plan->name }}</option>@endforeach</select></label>
        <label>Billing<select name="billing_cycle" required><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></label>
        <label>Access until<input type="date" name="ends_at" required value="{{ old('ends_at', today()->addMonth()->toDateString()) }}" min="{{ today()->toDateString() }}"></label>
      </div>
      <div class="adm-actions"><button class="adm-btn">Approve and open</button></div>
    </form>
    <form method="post" action="/admin/requests/{{ $shop->id }}/reject" class="adm-form" data-once>@csrf
      <div class="adm-fields"><label>Reason for turning it down (the owner sees this)<input name="reason" required maxlength="500" placeholder="e.g. Phone number does not match the business"></label></div>
      <div class="adm-actions"><button class="adm-btn adm-btn--ghost">Reject</button></div>
    </form>
  </div>
</section>
@empty
<section class="adm-panel"><div class="adm-panel-body"><p class="adm-empty"><strong>No requests waiting</strong>New shops appear here after the owner verifies their email and sends the business form.</p></div></section>
@endforelse
@if($rejected->isNotEmpty())
<section class="adm-panel"><div class="adm-panel-head"><h2>Recently turned down</h2></div><div class="adm-tablewrap"><table class="adm-table"><thead><tr><th>Shop</th><th>Reason</th><th>When</th></tr></thead><tbody>
@foreach($rejected as $s)<tr><td class="adm-key">{{ $s->name }}</td><td>{{ $s->review_note }}</td><td class="num">{{ $s->reviewed_at?->format('d M Y') }}</td></tr>@endforeach
</tbody></table></div></section>
@endif
@endsection
