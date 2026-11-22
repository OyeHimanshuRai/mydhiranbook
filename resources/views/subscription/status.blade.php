<x-subscription-page title="Plan & billing">
<div class="ck">
    @if($hasShop)<x-dhiran.settings-nav active="billing" />@else<h1>Plan &amp; billing</h1>@endif
    <div class="ui-card ck-card">
        <h2>Your subscription</h2>
        @if($subscription)
            <p>
                {{ $subscription->plan?->name }}:
                @if($subscription->grantsAccessToday()) active @else expired @endif
                @unless($hasShop) <br><span class="lede">Paid. Your business will be set up next.</span> @endunless
            </p>
            <dl class="ck-facts">
                <div><dt>Status</dt><dd>{{ ucfirst($subscription->status) }}</dd></div>
                <div><dt>Term</dt><dd>{{ $subscription->starts_at?->format('d M Y') }} to {{ $subscription->ends_at?->format('d M Y') }}</dd></div>
                <div><dt>Full access until</dt><dd>{{ ($subscription->grace_ends_at ?? $subscription->ends_at)?->format('d M Y') }}</dd></div>
                <div><dt>Paid</dt><dd>₹{{ number_format((float) $subscription->price_paid) }}</dd></div>
            </dl>
            @if($queued)<p class="lede">Renewal queued: {{ $queued->plan?->name }}, starts {{ $queued->starts_at->format('d M Y') }}</p>@endif
        @else
            <p class="lede">No subscription yet. Choose a plan to start.</p>
        @endif
    </div>
    @if($receipts->isNotEmpty())
    <div class="ui-card ck-card">
        <h2>Payment receipts</h2>
        <ul class="ck-list">@foreach($receipts as $r)<li><a href="{{ route('subscription.receipt', $r) }}">{{ $r->invoice_number }}</a> · {{ $r->issued_at->format('d M Y') }} · ₹{{ number_format((float) $r->total_amount, 2) }}</li>@endforeach</ul>
    </div>
    @endif
    <h2 class="ck-gap">{{ $subscription ? 'Renew' : 'Choose a plan' }}</h2>
    <div class="ck-plans">
        @foreach($plans as $plan)
            @include('subscription._plan-card', ['plan' => $plan, 'label' => $subscription ? 'Renew with this plan' : 'Buy this plan'])
        @endforeach
    </div>
</div>
</x-subscription-page>
