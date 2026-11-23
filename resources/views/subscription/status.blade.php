<x-subscription-page title="Plan & billing" :settings="$hasShop" footer>
@if($hasShop)
    <x-dhiran.page-header>
        <x-dhiran.settings-nav active="billing" as="title" />
    </x-dhiran.page-header>
    <turbo-frame id="settings-pane" data-turbo-action="advance">
    <div class="content-inner">
        <x-dhiran.settings-nav active="billing" switcher />
        <x-settings.live />
        <div class="st-page">
            <p class="st-lead">Your subscription, payment receipts and renewal. You pay once per term; nothing is charged automatically.</p>

            <x-settings.panel title="Your plan" :desc="$subscription ? null : 'No subscription yet. Choose a plan below to start.'">
                @if($subscription)
                    @php
                        $active = $subscription->grantsAccessToday();
                        $fullUntil = $subscription->grace_ends_at ?? $subscription->ends_at;
                        $inGrace = $active && $subscription->ends_at?->isPast();
                    @endphp
                    <p class="st-plan-line">
                        <span class="st-plan-name">{{ $subscription->plan?->name }}</span>
                        <span class="st-badge {{ $active ? 'st-badge--ok' : 'st-badge--bad' }}">{{ $active ? 'Active' : 'Expired' }}</span>
                    </p>
                    @if($inGrace)
                        <p class="st-notice"><x-icon name="triangle-alert" size="20" /><span>This term ended on {{ $subscription->ends_at->format('d M Y') }}. You still have full access until {{ $fullUntil?->format('d M Y') }}; renew before then.</span></p>
                    @elseif(! $active)
                        <p class="st-notice"><x-icon name="triangle-alert" size="20" /><span>This plan has ended. You can still collect payments on existing loans; renew to make new ones.</span></p>
                    @endif
                    <dl class="st-facts">
                        <div><dt>Term</dt><dd>{{ $subscription->starts_at?->format('d M Y') }} to {{ $subscription->ends_at?->format('d M Y') }}</dd></div>
                        <div><dt>Full access until</dt><dd>{{ $fullUntil?->format('d M Y') }}</dd></div>
                        <div><dt>Paid</dt><dd>{{ \App\Support\IndianNumber::rupee($subscription->price_paid, fmod((float) $subscription->price_paid, 1) ? 2 : 0) }}</dd></div>
                    </dl>
                    @if($queued)<p class="st-notice"><x-icon name="info" size="20" /><span>Renewal queued: {{ $queued->plan?->name }}, starts {{ $queued->starts_at->format('d M Y') }}</span></p>@endif
                @endif
            </x-settings.panel>

            @if($receipts->isNotEmpty())
                <section class="st-panel" aria-labelledby="st-receipts">
                    <header class="st-panel-head"><h2 class="st-title" id="st-receipts">Payment receipts</h2><p class="st-desc">Open a receipt to print or save it.</p></header>
                    <ul class="st-receipts">
                        @foreach($receipts as $r)
                            <li class="st-receipt">
                                <a href="{{ route('subscription.receipt', $r) }}" data-turbo-frame="_top">{{ $r->invoice_number }}</a>
                                <p class="st-receipt-date">{{ $r->issued_at->format('d M Y') }}</p>
                                <span class="st-receipt-amt">{{ \App\Support\IndianNumber::rupee($r->total_amount, 2) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section aria-labelledby="st-renew">
                <div class="st-plans-head">
                    <h2 class="st-title" id="st-renew">{{ $subscription ? 'Renew' : 'Choose a plan' }}</h2>
                    <p class="st-desc">{{ $subscription ? 'A renewal paid early starts when the current term ends.' : 'Pick the plan that fits your shop.' }}</p>
                </div>
                <div class="st-plans">
                    @foreach($plans as $plan)
                        @include('subscription._plan-panel', ['plan' => $plan, 'label' => $subscription ? 'Renew with this plan' : 'Buy this plan'])
                    @endforeach
                </div>
            </section>
        </div>
    </div>
    </turbo-frame>
@else
<div class="ck">
    <h1>Plan &amp; billing</h1>
    <div class="ui-card ck-card">
        <h2>Your subscription</h2>
        @if($subscription)
            <p>{{ $subscription->plan?->name }}: @if($subscription->grantsAccessToday()) active @else expired @endif<br><span class="lede">Paid. Your business will be set up next.</span></p>
            <dl class="ck-facts">
                <div><dt>Status</dt><dd>{{ ucfirst($subscription->status) }}</dd></div>
                <div><dt>Term</dt><dd>{{ $subscription->starts_at?->format('d M Y') }} to {{ $subscription->ends_at?->format('d M Y') }}</dd></div>
                <div><dt>Full access until</dt><dd>{{ ($subscription->grace_ends_at ?? $subscription->ends_at)?->format('d M Y') }}</dd></div>
                <div><dt>Paid</dt><dd>₹{{ number_format((float) $subscription->price_paid) }}</dd></div>
            </dl>
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
@endif
</x-subscription-page>
