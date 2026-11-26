{{-- Sidebar chip: when the shop's access ends. Outline styles only; the state is also said in words (colour is never the only cue). --}}
@php
    $until = auth()->user()?->shop?->accessUntil();
    $days = $until ? (int) today()->diffInDays($until->copy()->startOfDay(), false) : null;
    $date = $until?->format('j M Y');
    $state = match (true) { $days === null || $days < 0 => null, $days <= 7 => 'danger', $days <= 30 => 'warn', $days <= 60 => 'soon', default => 'ok' };
    $left = $state === 'danger'
        ? ($days === 0 ? 'Ends today' : 'Ends in '.$days.' '.\Illuminate\Support\Str::plural('day', $days))
        : ($days === 0 ? 'Last day' : $days.' '.\Illuminate\Support\Str::plural('day', $days).' left');
@endphp
@if($state)
<a href="{{ route('subscription.status') }}" class="dh-access dh-access--{{ $state }}" aria-label="Access until {{ $date }}, {{ strtolower($left) }}. See your access.">
    <span class="dh-access-until">@if($state !== 'ok')<x-icon name="clock" size="14" />@endif<span>Access until {{ $date }}</span></span>
    <span class="dh-access-left">{{ $left }}</span>
</a>
@endif
