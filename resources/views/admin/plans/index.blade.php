@extends('admin.layout') @section('title','Plans') @section('content')
<div class="heading"><div><h1>Plans.</h1><p>Edit prices and limits. Inactive plans leave signup and renewal; existing subscriptions keep working.</p></div></div>
@foreach($plans as $p)<section class="panel"><div class="panel-head"><h2>{{ $p->name }} <span class="muted">{{ $p->code }}</span></h2><span class="badge {{ $p->is_active ? '' : 'suspended' }}">{{ $p->is_active ? 'active' : 'inactive' }}</span></div><div class="panel-body"><form action="/admin/plans/{{ $p->id }}" method="post" class="form-grid">@csrf @method('PATCH')
<label>Monthly price (₹)<input name="price_monthly" type="number" min="0" step="0.01" value="{{ $p->price_monthly }}" required></label>
@if($p->price_yearly!==null)<label>Yearly price (₹)<input name="price_yearly" type="number" min="0" step="0.01" value="{{ $p->price_yearly }}" required></label>@endif
<label>Grace days<input name="grace_days" type="number" min="0" max="90" value="{{ $p->grace_days }}" required></label>
<label>Staff limit<input name="staff_limit" type="number" min="1" value="{{ $p->feature('staff_limit') }}" required></label>
<label>Max pledged items<input name="max_items" type="number" min="1" value="{{ $p->feature('max_items') }}" required></label>
<label>Offered for sale<select name="is_active"><option value="1" @selected($p->is_active)>Active</option><option value="0" @selected(!$p->is_active)>Inactive</option></select></label>
<label class="full">Reason (optional)<input name="reason" maxlength="500"></label><div class="actions full"><button class="small">Save plan</button></div></form></div></section>@endforeach
@endsection
