{{-- One report total (owner 2026-11-22): label, figure, optional note. tone: success | danger | null. money: show the rupee sign
     (sign first, then the symbol; the figure itself carries no sign). total="key" keeps the figure addressable (data-report-total). --}}
@props(['label', 'value', 'tone' => null, 'sub' => null, 'total' => null, 'money' => false])
@php
    $neg = $money && str_starts_with((string) $value, '-');
    $figure = $neg ? substr((string) $value, 1) : $value;
@endphp
<div {{ $attributes->class(['dh-stat', $tone ? "dh-stat--{$tone}" : null]) }}>
    <p class="dh-stat-label">{{ $label }}</p>
    <p class="dh-stat-value">{{ $neg ? '-' : '' }}@if($money)<span class="dh-stat-cur">₹</span>@endif<span{!! $total ? ' data-report-total="' . e($total) . '"' : '' !!}>{{ $figure }}</span></p>
    @if($sub)<p class="dh-stat-sub">{{ $sub }}</p>@endif
</div>
