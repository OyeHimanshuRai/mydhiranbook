@extends('admin.layout') @section('title','Unresolved payments') @section('content')
<div class="adm-head"><div><h1>Unresolved payments</h1><p>Captured Razorpay payments that could not be applied. Resolve in the Razorpay dashboard (refund or fix), then mark here.</p></div></div>
<section class="adm-panel"><div class="adm-tablewrap"><table class="adm-table"><thead><tr><th>Status</th><th>Razorpay ids</th><th>Detail</th><th>Resolve</th></tr></thead><tbody>
@forelse($events as $e)@php $a=$e->after ?? []; $f='ev-'.$e->id; @endphp
<tr><td data-label="Status"><div>@include('admin._badge',['s'=>empty($a['resolved_at'])?'open':'resolved'])<span class="adm-sub">{{ $e->created_at->format('d M Y H:i') }} · attempts {{ $a['attempt_count'] ?? 1 }}</span></div></td>
<td data-label="Razorpay ids"><div>Order {{ $a['order_id'] ?? '—' }}<span class="adm-sub">Payment {{ $a['payment_id'] ?? '—' }}</span></div></td>
<td data-label="Detail">{{ $e->reason ?: "—" }}</td>
<td data-label="Resolve">@if(empty($a['resolved_at']))<form action="/admin/unresolved-payments/{{ $e->id }}/resolve" method="post" class="adm-form-inline">@csrf<input type="hidden" name="_form" value="{{ $f }}">@include('admin._field',['name'=>'reason','label'=>'Reason','form'=>$f,'attrs'=>['required'=>'required','maxlength'=>'500']])<button class="adm-btn adm-btn--ghost">Mark resolved</button></form>@else{{ $a['resolution_note'] ?? 'Applied automatically' }}@endif</td></tr>
@empty<tr><td colspan="4" class="adm-empty"><strong>Nothing unresolved</strong>Every captured payment has been applied.</td></tr>@endforelse
</tbody></table></div>@include('pagination',['page'=>$events])</section>
@endsection
