@extends('admin.layout') @section('title','Audit log') @section('content')
<div class="adm-head"><div><h1>Audit log</h1><p>Append-only record of Dhiran admin activity, newest first.</p></div></div>
<section class="adm-panel"><div class="adm-tablewrap"><table class="adm-table"><thead><tr><th>Actor</th><th>Action</th><th>Target</th><th>Details</th></tr></thead><tbody>
@forelse($logs as $log)<tr><td data-label="When" class="num"><div>{{ $log->created_at->format('d M Y H:i:s') }}<span class="adm-sub">{{ $log->actor?->name ?? 'Unauthenticated / system' }}</span></div></td>
<td data-label="Action" class="adm-key">{{ $log->action }}<span class="adm-sub">{{ $log->ip }}</span></td>
<td data-label="Target">{{ $log->target_type }} {{ $log->target_id }}</td>
<td data-label="Details"><div>{{ $log->reason ?: '—' }}@if($log->before || $log->after)<details><summary>Changes</summary><pre class="adm-json">{{ json_encode(['before'=>$log->before,'after'=>$log->after],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></details>@endif</div></td></tr>
@empty<tr><td colspan="4" class="adm-empty"><strong>No admin activity recorded</strong>Sign-ins, grants and settings changes will be listed here.</td></tr>@endforelse
</tbody></table></div>@include('pagination',['page'=>$logs])</section>
@endsection
