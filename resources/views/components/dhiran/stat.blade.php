{{-- One report total (owner 2026-11-22): label, figure, optional note. tone: success | danger | null.
     total="key" keeps the figure addressable for the report screen script (data-report-total). --}}
@props(['label', 'value', 'icon' => null, 'tone' => null, 'sub' => null, 'total' => null, 'money' => false])
<div {{ $attributes->class(['dh-stat', $tone ? "dh-stat--{$tone}" : null]) }}>
    @if($icon)<span class="dh-stat-icon" aria-hidden="true"><x-icon :name="$icon" size="20" /></span>@endif
    <div class="dh-stat-body">
        <p class="dh-stat-label">{{ $label }}</p>
        <p class="dh-stat-value">@if($money)<span class="dh-stat-cur">₹</span>@endif<span{!! $total ? ' data-report-total="' . e($total) . '"' : '' !!}>{{ $value }}</span></p>
        @if($sub)<p class="dh-stat-sub">{{ $sub }}</p>@endif
    </div>
</div>
