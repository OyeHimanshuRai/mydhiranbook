@php
    $days = $subscription?->daysRemaining();
    $active = (bool) $subscription?->grantsAccessToday();
    if (! $subscription) { $chip = null; }
    elseif (! $active) { $chip = ['Ended', 'bad']; }
    elseif ($days !== null && $days < 0) { $chip = ['Ended, grace period', 'bad']; }
    elseif ($days !== null && $days <= 30) { $chip = ['Ends in '.$days.' '.\Illuminate\Support\Str::plural('day', $days), 'warn']; }
    else { $chip = ['Active', 'ok']; }
    $email = config('business.email');
    $phone = config('business.phone');
    $contact = collect([
        $email ? '<a href="mailto:'.e($email).'">'.e($email).'</a>' : null,
        $phone ? '<a href="tel:'.e(preg_replace('/[^+\d]/', '', $phone)).'">'.e($phone).'</a>' : null,
    ])->filter()->implode(' or ');
@endphp
<x-subscription-page title="Your plan" :settings="$hasShop" footer>
@if($hasShop)
    <x-dhiran.page-header>
        <x-dhiran.settings-nav active="billing" as="title" />
    </x-dhiran.page-header>
    <turbo-frame id="settings-pane" data-turbo-action="advance">
    <div class="content-inner">
        <x-dhiran.settings-nav active="billing" switcher />
        <x-settings.live />
        <div class="st-page">
            <x-settings.panel title="Your plan" :desc="$subscription ? null : 'No access term yet.'">
                @if($subscription)
                    <p class="st-plan-line">
                        <span class="st-plan-name">{{ $subscription->plan?->name }}</span>
                        <span class="st-badge st-badge--{{ $chip[1] }}">{{ $chip[0] }}</span>
                    </p>
                    <dl class="st-facts">
                        <div><dt>Access from</dt><dd>{{ $subscription->starts_at?->format('d M Y') }}</dd></div>
                        <div><dt>Access until</dt><dd>{{ $subscription->ends_at?->format('d M Y') }}</dd></div>
                        <div><dt>Days left</dt><dd>{{ $days !== null ? max($days, 0) : '' }}</dd></div>
                    </dl>
                @endif
                <p>To extend your access, contact us{!! $contact ? ': '.$contact : '.' !!}</p>
            </x-settings.panel>
        </div>
    </div>
    </turbo-frame>
@else
<div class="ck">
    <h1>Your plan</h1>
    <div class="ui-card ck-card">
        @if($subscription)
            <p>{{ $subscription->plan?->name }}: {{ $chip[0] }}</p>
            <dl class="ck-facts">
                <div><dt>Access from</dt><dd>{{ $subscription->starts_at?->format('d M Y') }}</dd></div>
                <div><dt>Access until</dt><dd>{{ $subscription->ends_at?->format('d M Y') }}</dd></div>
                <div><dt>Days left</dt><dd>{{ $days !== null ? max($days, 0) : '' }}</dd></div>
            </dl>
        @else
            <p class="lede">No access term yet.</p>
        @endif
        <p>To extend your access, contact us{!! $contact ? ': '.$contact : '.' !!}</p>
    </div>
</div>
@endif
</x-subscription-page>
