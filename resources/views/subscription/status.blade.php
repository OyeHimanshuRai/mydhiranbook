<x-subscription-page title="Plan & billing">
<style>.sub-plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px}.sub-plan{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:24px}.sub-plan h2{margin:0 0 8px}.sub-price{font-size:30px;font-weight:600}.sub-price small{font-size:14px;font-weight:400;color:#64748b}.sub-plan ul{padding-left:18px;margin:16px 0 20px}.sub-current{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:24px;margin-bottom:24px}.sub-current dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin:16px 0 0}.sub-current dt{font-size:12px;color:#64748b}.sub-current dd{margin:0;font-weight:600}</style>
<div class="content-inner">
    @if($hasShop)<x-dhiran.settings-nav active="billing" />@endif
    <div class="sub-current">
        <h2>Your subscription</h2>
        @if($subscription)
            <p>
                {{ $subscription->plan?->name }}:
                @if($subscription->grantsAccessToday()) active @else expired @endif
                @unless($hasShop) <br><span class="muted">Paid. Your business will be set up next.</span> @endunless
            </p>
            <dl>
                <div><dt>Status</dt><dd>{{ ucfirst($subscription->status) }}</dd></div>
                <div><dt>Term</dt><dd>{{ $subscription->starts_at?->format('d M Y') }} to {{ $subscription->ends_at?->format('d M Y') }}</dd></div>
                <div><dt>Full access until</dt><dd>{{ ($subscription->grace_ends_at ?? $subscription->ends_at)?->format('d M Y') }}</dd></div>
                <div><dt>Paid</dt><dd>₹{{ number_format((float) $subscription->price_paid) }}</dd></div>
            </dl>
            @if($queued)<p class="muted">Renewal queued: {{ $queued->plan?->name }}, starts {{ $queued->starts_at->format('d M Y') }}</p>@endif
        @else
            <p class="muted">No subscription yet. Choose a plan to start.</p>
        @endif
    </div>
    @if($receipts->isNotEmpty())
    <div class="sub-current">
        <h2>Payment receipts</h2>
        <ul>@foreach($receipts as $r)<li><a href="{{ route('subscription.receipt', $r) }}">{{ $r->invoice_number }}</a> · {{ $r->issued_at->format('d M Y') }} · ₹{{ number_format((float) $r->total_amount, 2) }}</li>@endforeach</ul>
    </div>
    @endif
    <h2>{{ $subscription ? 'Renew' : 'Choose a plan' }}</h2>
    <div class="sub-plans">
        @foreach($plans as $plan)
            @include('subscription._plan-card', ['plan' => $plan, 'label' => $subscription ? 'Renew with this plan' : 'Buy this plan'])
        @endforeach
    </div>
</div>
</x-subscription-page>
