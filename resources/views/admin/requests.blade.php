@extends('admin.layout') @section('title','New shop requests') @section('content')
<div class="adm-head"><div>
  <h1>New shop requests @if($shops->isNotEmpty())<span class="adm-badge adm-badge--warn rq-count">{{ $shops->count() }} waiting</span>@endif</h1>
  <p>Check each shop's details, then approve it or turn it down with a reason the owner will read.</p>
</div></div>
@forelse($shops as $shop)
@php
  $owner = ($owners[$shop->id] ?? collect())->first();
  $ownerName = $owner?->name ?: trim($owner?->first_name.' '.$owner?->last_name);
  $until = today()->addYear()->format('d M Y');
@endphp
<article class="adm-panel rq-card" aria-labelledby="rq-{{ $shop->id }}">
  <header class="rq-top">
    <div class="rq-title"><h2 id="rq-{{ $shop->id }}">{{ $shop->name }}</h2>
      @php $at = $shop->resubmitted_at ?? $shop->created_at; @endphp
      <p>Shop #{{ $shop->id }} · {{ $at?->diffForHumans() }} · {{ $at?->format('d M Y, h:i A') }}</p></div>
    <span class="adm-badge adm-badge--warn">{{ $shop->submissions > 1 ? 'Sent again ('.$shop->submissions.')' : 'Waiting' }}</span>
  </header>
  @if($shop->previous_review_note)<p class="rq-prev"><strong>Turned down before:</strong> {{ $shop->previous_review_note }}</p>@endif
  <div class="rq-body">
    <div class="rq-info">
      <section aria-label="Business"><h3 class="rq-label">Business</h3>
        <dl class="adm-kv">
          <div><dt>Phone</dt><dd>@if($shop->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop->phone) }}">{{ $shop->phone }}</a>@else <span class="adm-muted">Not given</span>@endif</dd></div>
          <div><dt>Address</dt><dd>{{ $shop->address ?: 'Not given' }}</dd></div>
          <div><dt>Referred by</dt><dd>@include('admin._referrer')</dd></div>
        </dl></section>
      <section aria-label="Owner"><h3 class="rq-label">Owner</h3>
        <dl class="adm-kv">
          <div><dt>Name</dt><dd>{{ $ownerName ?: 'Not given' }}</dd></div>
          <div><dt>Email</dt><dd>@if($owner?->email)<a href="mailto:{{ $owner->email }}">{{ $owner->email }}</a>@else <span class="adm-muted">Not given</span>@endif</dd></div>
          <div><dt>Mobile</dt><dd>{{ $owner?->mobile_number ?: 'Not given' }}</dd></div>
        </dl></section>
    </div>
    <div class="rq-decide">
      <form method="post" action="/admin/requests/{{ $shop->id }}/approve" data-once data-rq-confirm="{{ 'Approve '.$shop->name.' for one year?' }}">@csrf
        <button class="adm-btn adm-btn--block">Approve for 1 year</button>
        <p class="rq-note">Opens the software until {{ $until }}. Extend it from the dashboard when the year ends.</p>
      </form>
      <form method="post" action="/admin/requests/{{ $shop->id }}/reject" class="rq-reject" data-once data-rq-confirm="{{ 'Turn down '.$shop->name.'? The owner is told the reason.' }}">@csrf
        <h3 class="rq-label">Turn down</h3>
        <div class="adm-field"><label for="rq-reason-{{ $shop->id }}">Reason (the owner sees this)</label>
          <input id="rq-reason-{{ $shop->id }}" name="reason" required maxlength="500" placeholder="e.g. Phone number does not match the business"></div>
        <button class="adm-btn adm-btn--danger adm-btn--block">Reject</button>
      </form>
    </div>
  </div>
</article>
@empty
<section class="adm-panel"><div class="rq-empty">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7l2-3h14l2 3M3 7v12a1 1 0 001 1h16a1 1 0 001-1V7M3 7h18M9 12l2 2 4-4"/></svg>
  <h2>No requests waiting</h2>
  <p>New shops appear here after the owner verifies their email and sends the business form.</p>
</div></section>
@endforelse
@if($rejected->isNotEmpty())
<section class="adm-panel"><div class="adm-panel-head"><h2>Recently turned down</h2></div><div class="adm-tablewrap"><table class="adm-table"><thead><tr><th>Shop</th><th>Reason</th><th>Date</th></tr></thead><tbody>
@foreach($rejected as $s)<tr><td class="adm-key">{{ $s->name }}</td><td data-label="Reason">{{ $s->review_note }}</td><td class="num" data-label="Date">{{ $s->reviewed_at?->format('d M Y') }}</td></tr>@endforeach
</tbody></table></div></section>
@endif

<dialog id="rq-dialog" class="rq-dialog" aria-labelledby="rq-dialog-msg">
  <form method="dialog">
    <p id="rq-dialog-msg"></p>
    <div class="rq-dialog-actions"><button value="cancel" class="adm-btn adm-btn--ghost">Cancel</button><button value="ok" class="adm-btn">Yes, continue</button></div>
  </form>
</dialog>
<script>
// An in-page confirm (a native confirm() box can be blocked or hidden, which made the buttons look dead).
(() => {
  const dlg = document.getElementById('rq-dialog');
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-rq-confirm]');
    if (!f || f.dataset.rqOk === '1') { return; }
    if (typeof dlg.showModal !== 'function') { return; }          // no dialog support: submit straight away
    e.preventDefault();
    document.getElementById('rq-dialog-msg').textContent = f.dataset.rqConfirm;
    dlg.returnValue = '';
    dlg.addEventListener('close', () => { if (dlg.returnValue === 'ok') { f.dataset.rqOk = '1'; f.requestSubmit(); } }, { once: true });
    dlg.showModal();
  }, true);
})();
</script>
@endsection
